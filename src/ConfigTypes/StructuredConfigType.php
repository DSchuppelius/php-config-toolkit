<?php

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use Exception;

class StructuredConfigType extends ConfigTypeAbstract {
    public function parse(array $data): array {
        $parsed = [];
        foreach ($data as $section => $items) {
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
        return $parsed;
    }

    public function matches(array $data): bool {
        return array_reduce($data, fn($carry, $section) => $carry || $this->hasKeyValueStructure($section), false);
    }

    public function validate(array $data): array {
        $errors = [];

        foreach ($data as $section => $items) {
            foreach ($items as $index => $item) {
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

    protected function hasKeyValueStructure(mixed $items): bool {
        return is_array($items) && array_reduce($items, fn($carry, $item) => $carry || (is_array($item) && isset($item['key'], $item['value'])), false);
    }
}
