<?php

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;

class StructuredConfigType extends ConfigTypeAbstract {
    public function parse(array $data): array {
        $parsed = [];
        foreach ($data as $section => $items) {
            foreach ($items as $item) {
                if (!isset($item['enabled']) || $item['enabled'] !== true) {
                    continue;
                }

                $key = $item['key'] ?? null;
                $value = $item['value'] ?? null;
                $type = $item['type'] ?? 'text';

                if ($key) {
                    $parsed[$section][$key] = $this->castValue($value, $type);
                }
            }
        }
        return $parsed;
    }

    public function matches(array $data): bool {
        foreach ($data as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach ($section as $item) {
                if (isset($item['key']) && isset($item['value'])) {
                    return true;
                }
            }
        }
        return false;
    }

    public function validate(array $data): array {
        $errors = [];

        foreach ($data as $section => $items) {
            foreach ($items as $index => $item) {
                if (!isset($item['key']) || !is_string($item['key'])) {
                    $errors[] = "Fehlender oder ungültiger 'key' in '{$section}' an Index {$index}.";
                }
                if (!isset($item['value'])) {
                    $errors[] = "Fehlender 'value' in '{$section}' an Index {$index}.";
                }
                if (!isset($item['enabled']) || !is_bool($item['enabled'])) {
                    $errors[] = "Fehlender oder ungültiger 'enabled' in '{$section}' an Index {$index}.";
                }
            }
        }

        return $errors;
    }

    private function castValue($value, string $type) {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'number' => is_numeric($value) ? (int) $value : 0,
            default => (string) $value,
        };
    }
}
