<?php

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use Exception;

class AdvancedStructuredConfigType extends StructuredConfigType {
    /**
     * Prüft, ob dieser ConfigType zur gegebenen Konfiguration passt.
     * Wird nur gewählt, wenn mindestens eine "flache" Array-Sektion und
     * mindestens eine strukturierte Sektion existiert.
     */
    public static function matches(array $data): bool {
        if (empty($data)) {
            return false;
        }

        $hasFlatArray = false;
        $hasStructuredArray = false;

        foreach ($data as $items) {
            if (static::isFlatArray($items)) {
                $hasFlatArray = true;
            } elseif (static::hasKeyValueStructure($items) || static::isKeyValueMapping($items)) {
                $hasStructuredArray = true;
            } else {
                return false; // Ungültige Struktur gefunden
            }
        }

        return $hasFlatArray && $hasStructuredArray;
    }

    /**
     * Parsen der erweiterten Struktur, einschließlich "flacher" Arrays.
     */
    public function parse(array $data): array {
        $parsed = [];

        foreach ($data as $section => $items) {
            if (static::isFlatArray($items)) {
                $parsed[$section] = $items;
            } elseif (static::isKeyValueMapping($items)) {
                $parsed[$section] = $items;
            } else {
                $parsed[$section] = $this->parseStructuredSection($section, $items);
            }
        }

        return $parsed;
    }

    /**
     * Validiert die erweiterte Struktur inkl. flacher Arrays und Key-Value-Mappings.
     */
    public function validate(array $data): array {
        $errors = [];

        foreach ($data as $section => $items) {
            if (!is_array($items)) {
                $errors[] = "Sektion '{$section}' muss ein Array sein.";
                continue;
            }

            if (static::isFlatArray($items)) {
                // Flache Arrays sind immer valide (Typen bereits in isFlatArray geprüft)
                continue;
            }

            if (static::isKeyValueMapping($items)) {
                // Key-Value-Mappings sind valide, wenn alle Keys Strings sind
                continue;
            }

            // Strukturierte Sektion validieren (Elternklassen-Logik)
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $errors[] = "Eintrag an Index {$index} in '{$section}' muss ein Array sein.";
                    continue;
                }
                if (!isset($item['key']) || !is_string($item['key'])) {
                    $errors[] = "Fehlender oder ungültiger 'key' in '{$section}' an Index {$index}.";
                }
                if (!array_key_exists('value', $item)) {
                    $errors[] = "Fehlender 'value' in '{$section}' an Index {$index}.";
                }
                if (!isset($item['enabled']) || !is_bool($item['enabled'])) {
                    $errors[] = "Fehlender oder ungültiger 'enabled' in '{$section}' an Index {$index}.";
                }
            }
        }

        return $errors;
    }

    /**
     * Parst eine strukturierte Sektion mit key/value/enabled Einträgen.
     */
    private function parseStructuredSection(string $section, array $items): array {
        $result = [];

        foreach ($items as $item) {
            if (!($item['enabled'] ?? true)) {
                continue;
            }

            if (!isset($item['key'])) {
                $this->logErrorAndThrow(Exception::class, "Fehlender 'key' in '{$section}'.");
            }

            $result[$item['key']] = $this->castValue($item['value'] ?? null, $item['type'] ?? 'text');
        }

        return $result;
    }

    /**
     * Prüft, ob ein Array eine "flache" Struktur hat (z.B. eine Liste von Strings/Zahlen/Booleans).
     * Leere Arrays gelten nicht als flach, da sie keine aussagekräftige Struktur haben.
     */
    private static function isFlatArray(mixed $items): bool {
        if (!is_array($items) || empty($items)) {
            return false;
        }

        // Prüfen ob es sich um ein sequentielles Array handelt (numerische Keys)
        if (array_keys($items) !== range(0, count($items) - 1)) {
            return false;
        }

        return array_reduce($items, fn($carry, $item) => $carry && (is_string($item) || is_numeric($item) || is_bool($item)), true);
    }

    /**
     * Prüft, ob die Sektion eine Key-Value-Zuordnung ist (assoziatives Array mit skalaren Werten).
     */
    private static function isKeyValueMapping(mixed $items): bool {
        if (!is_array($items) || empty($items)) {
            return false;
        }

        foreach ($items as $key => $value) {
            if (!is_string($key)) {
                return false;
            }
            if (!is_scalar($value)) {
                return false;
            }
        }

        return true;
    }
}
