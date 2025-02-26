<?php

declare(strict_types=1);

namespace ConfigToolkit;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use Exception;
use ReflectionClass;

class ConfigLoader {
    private static ?self $instance = null;
    protected array $config = [];
    protected array $filePaths = [];
    protected ?ConfigTypeInterface $configType = null;
    protected static array $configTypeClasses = [];
    protected static string $configTypesDirectory = __DIR__ . '/ConfigTypes';
    protected static string $configTypesNamespace = 'ConfigToolkit\\ConfigTypes';

    private function __construct() {
        openlog("php-config-toolkit", LOG_PID | LOG_PERROR, LOG_LOCAL0);
        $this->loadAvailableConfigTypes();
    }

    /**
     * Singleton-Pattern, ohne sofortige Konfigurationsdatei
     */
    public static function getInstance(): static {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * Lädt eine einzelne Konfigurationsdatei
     */
    public function loadConfigFile(string $filePath, bool $throwException = false): void {
        if (!file_exists($filePath)) {
            syslog(LOG_WARNING, "Konfigurationsdatei nicht gefunden: {$filePath}");
            throw new Exception("Konfigurationsdatei nicht gefunden: {$filePath}");
        }

        $jsonContent = file_get_contents($filePath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            syslog(LOG_ERR, "Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
            throw new Exception("Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
        }

        try {
            $this->configType = $this->detectConfigType($data);
            $parsedConfig = $this->configType->parse($data);

            // Merge der Konfiguration, spätere Dateien überschreiben frühere
            $this->config = array_replace_recursive($this->config, $parsedConfig);
            syslog(LOG_INFO, "Konfigurationsdatei geladen: $filePath");
        } catch (Exception $e) {
            syslog(LOG_ERR, "Fehler beim Laden der Konfigurationsdatei $filePath: " . $e->getMessage());
            if ($throwException) {
                throw $e;
            }
        }
    }

    /**
     * Lädt mehrere Konfigurationsdateien auf einmal
     */
    public function loadConfigFiles(array $filePaths): void {
        foreach ($filePaths as $filePath) {
            $this->loadConfigFile($filePath);
        }
    }

    /**
     * Dynamisches Laden der Konfigurationsklassen aus dem `configTypes`-Verzeichnis
     */
    protected function loadAvailableConfigTypes(): void {
        if (!empty(self::$configTypeClasses)) {
            return; // Falls bereits geladen, nicht erneut scannen
        }

        $configTypesDir = realpath(self::$configTypesDirectory);

        if ($configTypesDir === false) {
            throw new Exception("Das Verzeichnis für Konfigurationstypen konnte nicht aufgelöst werden: " . self::$configTypesDirectory);
        }

        $files = scandir($configTypesDir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $className = self::$configTypesNamespace . '\\' . pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($className)) {
                $reflectionClass = new ReflectionClass($className);

                if (
                    $reflectionClass->isInstantiable()
                    && $reflectionClass->implementsInterface(ConfigTypeInterface::class)
                    && !$reflectionClass->isAbstract()
                ) {
                    self::$configTypeClasses[] = $className;
                    syslog(LOG_INFO, "ConfigType geladen: $className");
                } else {
                    syslog(LOG_WARNING, "ConfigType übersprungen: $className (nicht instanziierbar oder ungültig)");
                }
            } else {
                syslog(LOG_WARNING, "Klasse existiert nicht oder konnte nicht geladen werden: $className");
            }
        }

        if (empty(self::$configTypeClasses)) {
            syslog(LOG_ERR, "Keine gültigen Konfigurationstypen gefunden.");
            throw new Exception("Keine gültigen Konfigurationstypen gefunden.");
        }
    }

    /**
     * Erkennt den passenden Konfigurationstyp, indem alle registrierten Klassen geprüft werden.
     */
    protected function detectConfigType(array $data): ConfigTypeAbstract {
        foreach (self::$configTypeClasses as $class) {
            $instance = new $class();
            if ($instance->matches($data)) {
                return $instance;
            }
        }
        syslog(LOG_WARNING, "Unbekannter Konfigurationstyp in der aktuellen Datei");
        throw new Exception("Unbekannter Konfigurationstyp in der aktuellen Datei");
    }

    /**
     * Gibt einen bestimmten Wert aus der geladenen Konfiguration zurück
     */
    public function get(string $section, string $key, $default = null) {
        return $this->config[$section][$key] ?? $default;
    }

    /**
     * Lädt alle Konfigurationsdateien erneut
     */
    public function reload(): void {
        $this->config = [];
        $this->loadConfigFiles($this->filePaths);
    }

    public static function resetInstance(): void {
        self::$instance = null;
    }
}
