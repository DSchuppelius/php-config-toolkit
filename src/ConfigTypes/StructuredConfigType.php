<?php

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use Exception;

/**
 * ConfigType für strukturierte Konfigurationen mit key/value/enabled-Einträgen.
 * Dies ist der Fallback-Typ für Standard-Konfigurationsstrukturen.
 */
class StructuredConfigType extends ConfigTypeAbstract {
    /**
     * Parst die strukturierte Konfiguration in ein nutzbares Array.
     *
     * @throws Exception Wenn ein erforderlicher 'key' fehlt.
     */
    public function parse(array $data): array {
        $parsed = [];

        foreach ($data as $section => $items) {
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        // Skalare Werte direkt übernehmen
                        continue;
                    }

                    if (!($item['enabled'] ?? true)) {
                        continue;
                    }

                    if (!isset($item['key'])) {
                        throw new Exception("Fehlender 'key' in '{$section}'.");
                    }

                    $parsed[$section][$item['key']] = $this->castValue($item['value'] ?? null, $item['type'] ?? 'text');
                }
            } else {
                $parsed[$section] = $this->castValue($items, 'text');
            }
        }

        return $parsed;
    }

    /**
     * Prüft, ob dieser ConfigType zur gegebenen Konfiguration passt.
     * Dieser Typ ist der Fallback und wird nur gewählt, wenn kein spezifischerer Typ passt.
     */
    public static function matches(array $data): bool {
        if (empty($data)) {
            return false;
        }

        // Spezifischere Typen haben Vorrang
        if (PostmanConfigType::matches($data)) {
            return false;
        }

        if (AdvancedStructuredConfigType::matches($data)) {
            return false;
        }

        // Mindestens eine Sektion muss Key-Value-Struktur haben
        foreach ($data as $section) {
            if (static::hasKeyValueStructure($section)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validiert die strukturierte Konfiguration.
     *
     * @return array Liste der gefundenen Validierungsfehler.
     */
    public function validate(array $data): array {
        $errors = [];

        foreach ($data as $section => $items) {
            if (!is_array($items)) {
                $errors[] = "Sektion '{$section}' muss ein Array sein.";
                continue;
            }

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
     * Prüft, ob ein Array die erwartete Key-Value-Struktur hat.
     * Erwartet: Array von Objekten mit 'key' (string) und 'value' Eigenschaften.
     */
    protected static function hasKeyValueStructure(mixed $items): bool {
        if (!is_array($items) || empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['key'], $item['value']) || !is_string($item['key'])) {
                return false;
            }
        }

        return true;
    }
}
