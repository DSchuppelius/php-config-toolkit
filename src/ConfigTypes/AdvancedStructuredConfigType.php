<?php

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use Exception;

class AdvancedStructuredConfigType extends StructuredConfigType {
    /**
     * Prüft, ob dieser ConfigType zur gegebenen Konfiguration passt.
     * Wird nur gewählt, wenn mindestens eine "flache" Array-Sektion existiert.
     */
    public function matches(array $data): bool {
        $hasFlatArray = false;
        $hasStructuredArray = false;

        foreach ($data as $section => $items) {
            if ($this->isFlatArray($items)) {
                $hasFlatArray = true;
            } elseif ($this->hasKeyValueStructure($items) || $this->isKeyValueMapping($items)) {
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
            if ($this->isFlatArray($items)) {
                $parsed[$section] = $items;
            } elseif ($this->isKeyValueMapping($items)) {
                $parsed[$section] = $items;
            } else {
                foreach ($items as $item) {
                    if (!($item['enabled'] ?? true)) {
                        continue;
                    }

                    if (!isset($item['key'])) {
                        throw new Exception("Fehlender 'key' in '{$section}'.");
                    }

                    $parsed[$section][$item['key']] = $this->castValue($item['value'] ?? null, $item['type'] ?? 'text');
                }
            }
        }

        return $parsed;
    }

    /**
     * Prüft, ob ein Array eine "flache" Struktur hat (z.B. eine Liste von Strings).
     */
    private function isFlatArray(mixed $items): bool {
        return is_array($items) && array_reduce($items, fn($carry, $item) => $carry && is_string($item), true);
    }

    /**
     * Prüft, ob die Sektion eine Key-Value-Zuordnung ist, wie "DatevDMSMapping".
     */
    private function isKeyValueMapping(mixed $items): bool {
        return is_array($items) && array_reduce(array_keys($items), fn($carry, $key) => $carry && is_string($key) && is_string($items[$key]), true);
    }
}
