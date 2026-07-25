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
     *
     * @var list<array<string, mixed>>
     */
    protected array $duplicates = [];

    /**
     * Ergebnis der Überschreibungsprüfung
     *
     * @var list<array<string, mixed>>
     */
    protected array $overrides = [];

    /**
     * Speichert den Ursprung jedes Konfigurationswerts
     * Format: ['section']['key'] => ['file' => 'path', 'value' => 'originalValue']
     *
     * @var array<string, mixed>
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
     * Verarbeitet eine bereits geparste Konfigurationsdatei inkrementell:
     * erkennt Duplikate innerhalb der Datei und Überschreibungen gegenüber allen
     * zuvor per ingest() verarbeiteten Dateien – ohne erneutes Lesen von der Platte.
     *
     * @param string $filePath Absoluter Pfad der Datei (nur für die Herkunftsangabe)
     * @param array<string, mixed> $data Bereits dekodierte JSON-Daten der Datei
     * @return array{duplicates: list<array<string, mixed>>, overrides: list<array<string, mixed>>} Neue Befunde für DIESE Datei
     */
    public function ingest(string $filePath, array $data): array {
        $duplicates = $this->collectDuplicatesFromData($filePath, $data);
        $overrides = $this->collectOverridesFromData($filePath, $data);

        $this->duplicates = array_merge($this->duplicates, $duplicates);
        $this->overrides = array_merge($this->overrides, $overrides);

        return ['duplicates' => $duplicates, 'overrides' => $overrides];
    }

    /**
     * Prüft eine einzelne Konfigurationsdatei auf Duplikate innerhalb der Datei.
     *
     * @param string $filePath Pfad zur JSON-Datei
     * @param array<string, mixed>|null $data Optional bereits geparste Daten; sonst wird die Datei gelesen
     * @return list<array<string, mixed>> Liste der gefundenen Duplikate
     */
    public function checkFileForDuplicates(string $filePath, ?array $data = null): array {
        if ($data === null) {
            $data = $this->readJsonFile($filePath);
            if ($data === null) {
                return [];
            }
        }

        $duplicates = $this->collectDuplicatesFromData($filePath, $data);
        $this->duplicates = array_merge($this->duplicates, $duplicates);

        return $duplicates;
    }

    /**
     * Erkennt Duplikate innerhalb einer bereits geparsten Datei (ohne Zustandsänderung).
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>> Liste der gefundenen Duplikate
     */
    private function collectDuplicatesFromData(string $filePath, array $data): array {
        $duplicates = [];

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

        return $duplicates;
    }

    /**
     * Prüft mehrere Konfigurationsdateien auf Duplikate und Überschreibungen.
     *
     * @param list<string> $filePaths Liste der zu prüfenden Dateipfade
     * @return array{duplicates: list<array<string, mixed>>, overrides: list<array<string, mixed>>} Assoziatives Array mit 'duplicates' und 'overrides'
     */
    public function checkFilesForDuplicatesAndOverrides(array $filePaths): array {
        $this->reset();

        foreach ($filePaths as $filePath) {
            $data = $this->readJsonFile($filePath);
            if ($data === null) {
                continue;
            }

            // Prüfe Duplikate innerhalb der Datei
            $this->checkFileForDuplicates($filePath, $data);

            // Prüfe Überschreibungen gegenüber vorherigen Dateien
            $this->checkForOverrides($filePath, $data);
        }

        return [
            'duplicates' => $this->duplicates,
            'overrides' => $this->overrides,
        ];
    }

    /**
     * Liest und dekodiert eine JSON-Datei. Gibt null zurück, wenn sie fehlt oder ungültig ist.
     *
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $filePath): ?array {
        if (!file_exists($filePath)) {
            $this->logError("Datei nicht gefunden: {$filePath}");
            return null;
        }

        $jsonContent = file_get_contents($filePath);
        $data = json_decode((string) $jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError("Fehler beim Parsen der JSON-Datei: " . json_last_error_msg());
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Prüft, ob Werte aus dieser Datei vorherige Werte überschreiben würden.
     *
     * @param string $filePath Pfad zur JSON-Datei
     * @param array<string, mixed>|null $data Optional bereits geparste Daten; sonst wird die Datei gelesen
     */
    protected function checkForOverrides(string $filePath, ?array $data = null): void {
        if ($data === null) {
            $data = $this->readJsonFile($filePath);
            if ($data === null) {
                return;
            }
        }

        $this->overrides = array_merge($this->overrides, $this->collectOverridesFromData($filePath, $data));
    }

    /**
     * Ermittelt Überschreibungen der gegebenen Daten gegenüber dem bisher aufgebauten
     * Herkunftsindex und aktualisiert diesen. Gibt die neuen Überschreibungen zurück.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>> Liste der neu erkannten Überschreibungen
     */
    private function collectOverridesFromData(string $filePath, array $data): array {
        $overrides = [];

        foreach ($data as $section => $items) {
            if (!is_array($items)) {
                // Skalare Sektion
                if (isset($this->valueOrigins[$section]) && !is_array($this->valueOrigins[$section])) {
                    $overrides[] = [
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
                        $overrides[] = [
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

        return $overrides;
    }

    /**
     * Prüft die aktuell im ConfigLoader geladenen Dateien.
     *
     * @param ConfigLoader $loader Die ConfigLoader-Instanz
     * @return array{duplicates: list<array<string, mixed>>, overrides: list<array<string, mixed>>} Assoziatives Array mit 'duplicates' und 'overrides'
     */
    public function checkConfigLoader(ConfigLoader $loader): array {
        $loadedFiles = $loader->getLoadedFiles();
        return $this->checkFilesForDuplicatesAndOverrides($loadedFiles);
    }

    /**
     * Gibt alle gefundenen Duplikate zurück.
     *
     * @return list<array<string, mixed>>
     */
    public function getDuplicates(): array {
        return $this->duplicates;
    }

    /**
     * Gibt alle gefundenen Überschreibungen zurück.
     *
     * @return list<array<string, mixed>>
     */
    public function getOverrides(): array {
        return $this->overrides;
    }

    /**
     * Prüft, ob Duplikate gefunden wurden.
     */
    public function hasDuplicates(): bool {
        return !empty($this->duplicates);
    }

    /**
     * Prüft, ob Überschreibungen gefunden wurden.
     */
    public function hasOverrides(): bool {
        return !empty($this->overrides);
    }

    /**
     * Prüft, ob Probleme (Duplikate oder Überschreibungen) gefunden wurden.
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
     */
    protected function formatValue(mixed $value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        return (string) $value;
    }
}
