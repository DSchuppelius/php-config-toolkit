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
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use Psr\Log\LoggerInterface;

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

    private function __construct(?LoggerInterface $logger = null) {
        $this->initializeLogger($logger);

        $this->classLoader = new ClassLoader(self::$configTypesDirectory, self::$configTypesNamespace, ConfigTypeInterface::class, $logger);
    }

    /**
     * Singleton-Pattern, ohne sofortige Konfigurationsdatei
     */
    public static function getInstance(?LoggerInterface $logger = null): static {
        if (self::$instance === null) {
            self::$instance = new static($logger);
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
    public function loadConfigFile(string $filePath, bool $throwException = false, bool $forceReload = false): bool {
        $realPath = realpath($filePath);

        if (!$realPath) {
            $this->logError("Konfigurationsdatei nicht gefunden: {$filePath}");
            if ($throwException) {
                throw new Exception("Konfigurationsdatei nicht gefunden: {$filePath}");
            }
            return false;
        }

        if (!$forceReload && $this->hasLoadedConfigFile($realPath)) {
            $this->logInfo("Konfigurationsdatei bereits geladen, übersprungen: $realPath");
            return true;
        }

        $jsonContent = file_get_contents($realPath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError("Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
            if ($throwException) {
                throw new Exception("Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
            }
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
            return false;
        }
        return true;
    }

    /**
     * Lädt mehrere Konfigurationsdateien auf einmal
     */
    public function loadConfigFiles(array $filePaths, bool $throwException = false, bool $forceReload = false): bool {
        $result = true;
        foreach ($filePaths as $filePath) {
            if (!$this->loadConfigFile($filePath, $throwException, $forceReload)) {
                $result = false;
            }
        }
        return $result;
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
     * Gibt entweder den kompletten Abschnitt oder einen bestimmten Wert aus der Konfiguration zurück.
     *
     * @param string $section Die Sektion in der Konfigurationsdatei (z. B. "DatevDMSMapping").
     * @param string|null $key Der Schlüssel innerhalb der Sektion (z. B. "01 Stammakte Einkommensbesch."). Falls null, wird die gesamte Sektion zurückgegeben.
     * @param mixed $default Standardwert, falls die Sektion oder der Key nicht existiert.
     * @return mixed Der Wert aus der Konfiguration oder der Standardwert.
     */
    public function get(string $section, ?string $key = null, mixed $default = null): mixed {
        if (!isset($this->config[$section])) {
            return $default;
        }

        // Falls kein Key angegeben wird, geben wir den kompletten Abschnitt zurück
        if ($key === null) {
            return $this->config[$section];
        }

        return $this->config[$section][$key] ?? $default;
    }

    /**
     * Holt einen Wert aus der Konfiguration und ersetzt Platzhalter in Strings oder Arrays.
     *
     * Diese Methode ruft einen Konfigurationswert aus der angegebenen Sektion ab
     * und ersetzt Platzhalter ([VAR]) mit den übergebenen Parametern.
     * Falls der Wert nicht existiert, wird der Standardwert zurückgegeben.
     *
     * @param string $section  Die Sektion in der Konfigurationsdatei (z. B. "shellExecutables").
     * @param string $key      Der Schlüssel innerhalb der Sektion (z. B. "convert").
     * @param array  $params   Ein assoziatives Array mit Platzhalter-Werten (z. B. ['[VAR]' => 'Wert']).
     * @param mixed  $default  Standardwert, falls der Schlüssel nicht existiert.
     * @return mixed           Der Wert aus der Konfiguration mit ersetzten Platzhaltern oder der Standardwert.
     */
    public function getWithReplaceParams(string $section, ?string $key = null, array $params = [], mixed $default = null): mixed {
        $configValue = $this->get($section, $key, $default);

        if ($configValue === $default) {
            return $default;
        }

        return $this->applyPlaceholders($configValue, $params);
    }

    /**
     * Ersetzt Platzhalter ([VAR]) in Strings oder Arrays rekursiv.
     */
    private function applyPlaceholders(mixed $value, array $params): mixed {
        if (is_string($value)) {
            return str_replace(array_keys($params), array_values($params), $value);
        }

        if (is_array($value)) {
            return array_map(fn($item) => $this->applyPlaceholders($item, $params), $value);
        }

        return $value;
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
