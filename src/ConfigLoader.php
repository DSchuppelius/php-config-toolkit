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

use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Klasse zum Laden und Verwalten von JSON-Konfigurationsdateien.
 * Unterstützt automatische Typ-Erkennung durch das Plugin-System.
 *
 * Instanzverwaltung: getInstance() liefert benannte Instanzen. Der Standard-Key
 * ('default') verhält sich wie ein prozessweiter Singleton (rückwärtskompatibel),
 * über einen eigenen Key erhält jeder Nutzer eine isolierte Konfiguration – so
 * kollidieren z.B. verschiedene Toolkits nicht mehr in einem gemeinsamen Namespace.
 * Für eine vollständig eigenständige, nicht registrierte Instanz siehe create().
 */
final class ConfigLoader {
    use ErrorLog;

    public const DEFAULT_INSTANCE = 'default';

    /** @var array<string, self> Registrierte, benannte Instanzen. */
    private static array $instances = [];

    protected array $config = [];
    protected array $filePaths = [];
    protected array $loadedFiles = []; // Speichert bereits geladene Konfigurationsdateien
    protected ConfigDuplicateChecker $duplicateChecker;

    private function __construct(?LoggerInterface $logger = null) {
        $this->initializeLogger($logger);

        $this->duplicateChecker = new ConfigDuplicateChecker($logger);
    }

    /**
     * Gibt eine benannte Instanz zurück (Standard-Key = prozessweiter Singleton).
     *
     * @param LoggerInterface|null $logger      Optionaler Logger (nur beim ersten Erzeugen relevant)
     * @param string               $instanceKey Instanz-Schlüssel für isolierte Konfigurationen
     */
    public static function getInstance(?LoggerInterface $logger = null, string $instanceKey = self::DEFAULT_INSTANCE): static {
        if (!isset(self::$instances[$instanceKey])) {
            self::$instances[$instanceKey] = new static($logger);
        }
        return self::$instances[$instanceKey];
    }

    /**
     * Erzeugt eine vollständig eigenständige, NICHT registrierte Instanz.
     * Nützlich, wenn eine Konfiguration garantiert isoliert bleiben soll.
     */
    public static function create(?LoggerInterface $logger = null): self {
        return new self($logger);
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
            if ($throwException) {
                $this->logErrorAndThrow(Exception::class, "Konfigurationsdatei nicht gefunden: {$filePath}");
            }
            return $this->logErrorAndReturn(false, "Konfigurationsdatei nicht gefunden: {$filePath}");
        }

        if (!$forceReload && $this->hasLoadedConfigFile($realPath)) {
            return $this->logInfoAndReturn(true, "Konfigurationsdatei bereits geladen, übersprungen: $realPath");
        }

        $jsonContent = @file_get_contents($realPath);
        if ($jsonContent === false) {
            if ($throwException) {
                $this->logErrorAndThrow(Exception::class, "Konfigurationsdatei nicht lesbar: {$realPath}");
            }
            return $this->logErrorAndReturn(false, "Konfigurationsdatei nicht lesbar: {$realPath}");
        }

        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($throwException) {
                $this->logErrorAndThrow(Exception::class, "Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
            }
            return $this->logErrorAndReturn(false, "Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
        }

        try {
            // Duplikate innerhalb der Datei und Überschreibungen gegenüber bereits
            // geladenen Dateien inkrementell prüfen – ohne erneutes Lesen von der Platte.
            $ingest = $this->duplicateChecker->ingest($realPath, $data);

            foreach ($ingest['duplicates'] as $dup) {
                $this->logWarning(sprintf(
                    "Duplikat gefunden in '%s': Sektion '%s', Key '%s' ist mehrfach definiert (Index %d und %d)",
                    basename($realPath),
                    $dup['section'],
                    $dup['key'],
                    $dup['firstIndex'],
                    $dup['secondIndex']
                ));
            }

            foreach ($ingest['overrides'] as $override) {
                $this->logWarning(sprintf(
                    "Überschreibung: Sektion '%s', Key '%s' wird von '%s' auf '%s' geändert (Datei: %s -> %s)",
                    $override['section'],
                    $override['key'] ?? '(Skalarer Wert)',
                    $this->formatValue($override['originalValue']),
                    $this->formatValue($override['newValue']),
                    basename($override['originalFile']),
                    basename($override['newFile'])
                ));
            }

            $configType = $this->detectConfigType($data);
            $parsedConfig = $configType->parse($data);

            // Merge der Konfiguration, spätere Dateien überschreiben frühere
            $this->config = array_replace_recursive($this->config, $parsedConfig);

            // Datei als geladen und als (für reload relevanter) Ausgangspfad merken
            if (!in_array($realPath, $this->loadedFiles, true)) {
                $this->loadedFiles[] = $realPath;
            }
            if (!in_array($realPath, $this->filePaths, true)) {
                $this->filePaths[] = $realPath;
            }

            return $this->logDebugAndReturn(true, "Konfigurationsdatei: $realPath, mit Typ: " . get_class($configType) . " geladen");
        } catch (Exception $e) {
            $this->logError("Fehler beim Laden der Konfigurationsdatei $realPath: " . $e->getMessage());
            if ($throwException) {
                throw $e;
            }
            return false;
        }
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
     * Erkennt den passenden Konfigurationstyp über die zentrale Registry.
     */
    protected function detectConfigType(array $data): ConfigTypeInterface {
        return ConfigTypeRegistry::detect($data, self::getLogger());
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
            return array_map(fn ($item) => $this->applyPlaceholders($item, $params), $value);
        }

        return $value;
    }

    /**
     * Formatiert einen Wert für die Log-Ausgabe.
     *
     * @param mixed $value Der zu formatierende Wert
     * @return string Der formatierte Wert
     */
    private function formatValue(mixed $value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }

    /**
     * Lädt alle zuvor geladenen Konfigurationsdateien erneut von der Platte.
     */
    public function reload(): void {
        $files = $this->filePaths; // Snapshot der Ausgangspfade
        $this->config = [];
        $this->loadedFiles = [];   // Setzt die geladenen Dateien zurück
        $this->filePaths = [];     // wird beim erneuten Laden neu befüllt
        $this->duplicateChecker->reset(); // Setzt den Duplikat-Checker zurück
        $this->loadConfigFiles($files, true, true);
    }

    /**
     * Gibt die Liste der geladenen Konfigurationsdateien zurück.
     *
     * @return array Liste der absoluten Pfade der geladenen Dateien
     */
    public function getLoadedFiles(): array {
        return $this->loadedFiles;
    }

    /**
     * Prüft die geladenen Konfigurationsdateien auf Duplikate und Überschreibungen.
     *
     * @return array Assoziatives Array mit 'duplicates' und 'overrides'
     */
    public function checkForDuplicates(): array {
        return $this->duplicateChecker->checkConfigLoader($this);
    }

    /**
     * Gibt den Duplikat-Checker zurück.
     */
    public function getDuplicateChecker(): ConfigDuplicateChecker {
        return $this->duplicateChecker;
    }

    /**
     * Setzt registrierte Instanzen zurück (hauptsächlich für Tests).
     *
     * @param string|null $instanceKey Null = alle Instanzen zurücksetzen, sonst nur der genannte Key
     */
    public static function resetInstance(?string $instanceKey = null): void {
        if ($instanceKey === null) {
            self::$instances = [];
            return;
        }
        unset(self::$instances[$instanceKey]);
    }
}
