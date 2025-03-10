<?php
/*
 * Created on   : Wed Feb 19 2025
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfigLoader.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use ConfigToolkit\Traits\ErrorLog;
use Exception;

class ConfigLoader {
    use ErrorLog;

    private static ?self $instance = null;

    protected array $config = [];
    protected array $filePaths = [];
    protected array $loadedFiles = []; // Speichert bereits geladene Konfigurationsdateien
    protected ClassLoader $classLoader;
    protected ?ConfigTypeInterface $configType = null;

    protected static string $configTypesDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'ConfigTypes';
    protected static string $configTypesNamespace = 'ConfigToolkit\\ConfigTypes';

    private function __construct() {
        if (function_exists('openlog')) {
            if (defined('LOG_LOCAL0')) {
                openlog("php-config-toolkit", LOG_PID | LOG_PERROR, LOG_LOCAL0);
            } else {
                openlog("php-config-toolkit", LOG_PID | LOG_PERROR, LOG_USER);
            }
        }
        $this->classLoader = new ClassLoader(self::$configTypesDirectory, self::$configTypesNamespace, ConfigTypeInterface::class);
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
     * Prüft, ob eine Konfigurationsdatei bereits geladen wurde
     */
    public function hasLoadedConfigFile(string $filePath): bool {
        return in_array(realpath($filePath), $this->loadedFiles, true);
    }

    /**
     * Lädt eine einzelne Konfigurationsdatei, falls sie nicht bereits geladen wurde.
     *
     * @param string $filePath - Pfad zur Konfigurationsdatei
     * @param bool $throwException - Falls `true`, wird eine Exception geworfen, wenn die Datei nicht existiert
     * @param bool $forceReload - Falls `true`, wird die Datei erneut geladen, auch wenn sie bereits geladen wurde
     */
    public function loadConfigFile(string $filePath, bool $throwException = false, bool $forceReload = false): void {
        $realPath = realpath($filePath);

        if (!$realPath) {
            $this->logError("Konfigurationsdatei nicht gefunden: {$filePath}");
            throw new Exception("Konfigurationsdatei nicht gefunden: {$filePath}");
            return;
        }

        if (!$forceReload && $this->hasLoadedConfigFile($realPath)) {
            $this->logInfo("Konfigurationsdatei bereits geladen, übersprungen: $realPath");
            return;
        }

        $jsonContent = file_get_contents($realPath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError("Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
            throw new Exception("Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
        }

        try {
            $this->configType = $this->detectConfigType($data);
            $parsedConfig = $this->configType->parse($data);

            // Merge der Konfiguration, spätere Dateien überschreiben frühere
            $this->config = array_replace_recursive($this->config, $parsedConfig);
            $this->loadedFiles[] = $realPath; // Speichert die Datei als geladen
            $this->logInfo("Konfigurationsdatei: $realPath, mit Typ: " . get_class($this->configType) . " geladen");
        } catch (Exception $e) {
            $this->logError("Fehler beim Laden der Konfigurationsdatei $realPath: " . $e->getMessage());
            if ($throwException) {
                throw $e;
            }
        }
    }

    /**
     * Lädt mehrere Konfigurationsdateien auf einmal
     */
    public function loadConfigFiles(array $filePaths, bool $throwException = false, bool $forceReload = false): void {
        foreach ($filePaths as $filePath) {
            $this->loadConfigFile($filePath, $throwException, $forceReload);
        }
    }

    /**
     * Erkennt den passenden Konfigurationstyp, indem alle registrierten Klassen geprüft werden.
     */
    protected function detectConfigType(array $data): ConfigTypeAbstract {
        foreach ($this->classLoader->getClasses() as $class) {
            $instance = new $class();
            if ($instance->matches($data)) {
                return $instance;
            }
        }
        $this->logError("Unbekannter Konfigurationstyp in der aktuellen Datei");
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
        $this->loadedFiles = []; // Setzt die geladenen Dateien zurück
        $this->loadConfigFiles($this->filePaths, true);
    }

    public static function resetInstance(): void {
        self::$instance = null;
    }
}