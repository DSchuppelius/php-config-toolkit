<?php
/*
 * Created on   : Tue Jan 21 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfigDuplicateChecker.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit;

use ERRORToolkit\Traits\ErrorLog;
use Psr\Log\LoggerInterface;

/**
 * Klasse zur Erkennung von Duplikaten und Überschreibungen in Konfigurationsdateien.
 * 
 * Erkennt:
 * - Doppelte Keys innerhalb derselben Sektion einer Datei
 * - Überschreibungen von Werten beim Laden mehrerer Dateien
 */
class ConfigDuplicateChecker {
    use ErrorLog;

    /**
     * Ergebnis der Duplikatprüfung
     */
    protected array $duplicates = [];

    /**
     * Ergebnis der Überschreibungsprüfung
     */
    protected array $overrides = [];

    /**
     * Speichert den Ursprung jedes Konfigurationswerts
     * Format: ['section']['key'] => ['file' => 'path', 'value' => 'originalValue']
     */
    protected array $valueOrigins = [];

    /**
     * Konstruktor mit optionalem Logger.
     *
     * @param LoggerInterface|null $logger Optional ein PSR-3 Logger
     */
    public function __construct(?LoggerInterface $logger = null) {
        $this->initializeLogger($logger);
    }

    /**
     * Prüft eine einzelne Konfigurationsdatei auf Duplikate innerhalb der Datei.
     *
     * @param string $filePath Pfad zur JSON-Datei
     * @return array Liste der gefundenen Duplikate
     */
    public function checkFileForDuplicates(string $filePath): array {
        $duplicates = [];

        if (!file_exists($filePath)) {
            $this->logError("Datei nicht gefunden: {$filePath}");
            return $duplicates;
        }

        $jsonContent = file_get_contents($filePath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError("Fehler beim Parsen der JSON-Datei: " . json_last_error_msg());
            return $duplicates;
        }

        foreach ($data as $section => $items) {
            if (!is_array($items)) {
                continue;
            }

            $keysInSection = [];
            foreach ($items as $index => $item) {
                if (!is_array($item) || !isset($item['key'])) {
                    continue;
                }

                $key = $item['key'];
                $isEnabled = $item['enabled'] ?? true;

                if (isset($keysInSection[$key])) {
                    $duplicates[] = [
                        'file' => $filePath,
                        'section' => $section,
                        'key' => $key,
                        'firstIndex' => $keysInSection[$key]['index'],
                        'secondIndex' => $index,
                        'firstEnabled' => $keysInSection[$key]['enabled'],
                        'secondEnabled' => $isEnabled,
                        'firstValue' => $keysInSection[$key]['value'],
                        'secondValue' => $item['value'] ?? null,
                    ];
                } else {
                    $keysInSection[$key] = [
                        'index' => $index,
                        'enabled' => $isEnabled,
                        'value' => $item['value'] ?? null,
                    ];
                }
            }
        }

        $this->duplicates = array_merge($this->duplicates, $duplicates);
        return $duplicates;
    }

    /**
     * Prüft mehrere Konfigurationsdateien auf Duplikate und Überschreibungen.
     *
     * @param array $filePaths Liste der zu prüfenden Dateipfade
     * @return array Assoziatives Array mit 'duplicates' und 'overrides'
     */
    public function checkFilesForDuplicatesAndOverrides(array $filePaths): array {
        $this->reset();

        foreach ($filePaths as $filePath) {
            // Prüfe Duplikate innerhalb der Datei
            $this->checkFileForDuplicates($filePath);

            // Prüfe Überschreibungen gegenüber vorherigen Dateien
            $this->checkForOverrides($filePath);
        }

        return [
            'duplicates' => $this->duplicates,
            'overrides' => $this->overrides,
        ];
    }

    /**
     * Prüft, ob Werte aus dieser Datei vorherige Werte überschreiben würden.
     *
     * @param string $filePath Pfad zur JSON-Datei
     */
    protected function checkForOverrides(string $filePath): void {
        if (!file_exists($filePath)) {
            return;
        }

        $jsonContent = file_get_contents($filePath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        foreach ($data as $section => $items) {
            if (!is_array($items)) {
                // Skalare Sektion
                if (isset($this->valueOrigins[$section]) && !is_array($this->valueOrigins[$section])) {
                    $this->overrides[] = [
                        'section' => $section,
                        'key' => null,
                        'originalFile' => $this->valueOrigins[$section]['file'],
                        'originalValue' => $this->valueOrigins[$section]['value'],
                        'newFile' => $filePath,
                        'newValue' => $items,
                    ];
                }
                $this->valueOrigins[$section] = [
                    'file' => $filePath,
                    'value' => $items,
                ];
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['key'])) {
                    continue;
                }

                $key = $item['key'];
                $isEnabled = $item['enabled'] ?? true;

                // Nur aktivierte Einträge prüfen
                if (!$isEnabled) {
                    continue;
                }

                $value = $item['value'] ?? null;

                // Prüfe, ob dieser Key bereits existiert
                if (isset($this->valueOrigins[$section][$key])) {
                    $original = $this->valueOrigins[$section][$key];

                    // Nur als Überschreibung melden, wenn der Wert unterschiedlich ist
                    if ($original['value'] !== $value) {
                        $this->overrides[] = [
                            'section' => $section,
                            'key' => $key,
                            'originalFile' => $original['file'],
                            'originalValue' => $original['value'],
                            'newFile' => $filePath,
                            'newValue' => $value,
                        ];
                    }
                }

                // Speichere den aktuellen Wert als Ursprung
                $this->valueOrigins[$section][$key] = [
                    'file' => $filePath,
                    'value' => $value,
                ];
            }
        }
    }

    /**
     * Prüft die aktuell im ConfigLoader geladenen Dateien.
     *
     * @param ConfigLoader $loader Die ConfigLoader-Instanz
     * @return array Assoziatives Array mit 'duplicates' und 'overrides'
     */
    public function checkConfigLoader(ConfigLoader $loader): array {
        $loadedFiles = $loader->getLoadedFiles();
        return $this->checkFilesForDuplicatesAndOverrides($loadedFiles);
    }

    /**
     * Gibt alle gefundenen Duplikate zurück.
     *
     * @return array
     */
    public function getDuplicates(): array {
        return $this->duplicates;
    }

    /**
     * Gibt alle gefundenen Überschreibungen zurück.
     *
     * @return array
     */
    public function getOverrides(): array {
        return $this->overrides;
    }

    /**
     * Prüft, ob Duplikate gefunden wurden.
     *
     * @return bool
     */
    public function hasDuplicates(): bool {
        return !empty($this->duplicates);
    }

    /**
     * Prüft, ob Überschreibungen gefunden wurden.
     *
     * @return bool
     */
    public function hasOverrides(): bool {
        return !empty($this->overrides);
    }

    /**
     * Prüft, ob Probleme (Duplikate oder Überschreibungen) gefunden wurden.
     *
     * @return bool
     */
    public function hasIssues(): bool {
        return $this->hasDuplicates() || $this->hasOverrides();
    }

    /**
     * Setzt die Prüfergebnisse zurück.
     */
    public function reset(): void {
        $this->duplicates = [];
        $this->overrides = [];
        $this->valueOrigins = [];
    }

    /**
     * Formatiert die Ergebnisse als lesbaren String.
     *
     * @return string
     */
    public function formatResults(): string {
        $output = [];

        if (!empty($this->duplicates)) {
            $output[] = "=== DUPLIKATE INNERHALB VON DATEIEN ===";
            foreach ($this->duplicates as $dup) {
                $output[] = sprintf(
                    "  Datei: %s\n  Sektion: %s\n  Key: '%s'\n  Erste Stelle: Index %d (Wert: %s, aktiv: %s)\n  Zweite Stelle: Index %d (Wert: %s, aktiv: %s)\n",
                    $dup['file'],
                    $dup['section'],
                    $dup['key'],
                    $dup['firstIndex'],
                    $this->formatValue($dup['firstValue']),
                    $dup['firstEnabled'] ? 'ja' : 'nein',
                    $dup['secondIndex'],
                    $this->formatValue($dup['secondValue']),
                    $dup['secondEnabled'] ? 'ja' : 'nein'
                );
            }
        }

        if (!empty($this->overrides)) {
            $output[] = "=== ÜBERSCHREIBUNGEN ZWISCHEN DATEIEN ===";
            foreach ($this->overrides as $override) {
                $keyInfo = $override['key'] !== null ? "Key: '{$override['key']}'" : "(Skalarer Wert)";
                $output[] = sprintf(
                    "  Sektion: %s\n  %s\n  Original: %s (Datei: %s)\n  Überschrieben mit: %s (Datei: %s)\n",
                    $override['section'],
                    $keyInfo,
                    $this->formatValue($override['originalValue']),
                    $override['originalFile'],
                    $this->formatValue($override['newValue']),
                    $override['newFile']
                );
            }
        }

        if (empty($output)) {
            return "Keine Duplikate oder Überschreibungen gefunden.";
        }

        return implode("\n", $output);
    }

    /**
     * Formatiert einen Wert für die Ausgabe.
     *
     * @param mixed $value
     * @return string
     */
    protected function formatValue(mixed $value): string {
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
}