<?php

declare(strict_types=1);

namespace ConfigToolkit\Contracts\Abstracts;

use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;

abstract class ConfigTypeAbstract implements ConfigTypeInterface {
    protected function castValue($value, string $type): mixed {
        return match ($type) {
            'float', 'double' => is_numeric($value) ? (float) $value : 0.0,
            'int', 'integer', 'number' => is_numeric($value) ? (int) $value : 0,
            'timestamp' => is_numeric($value) ? (int) $value : (strtotime((string) $value) ?: 0),
            'date' => strtotime((string) $value) ? date('Y-m-d', strtotime((string) $value)) : null,
            'datetime' => strtotime((string) $value) ? date('Y-m-d H:i:s', strtotime((string) $value)) : null,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            default => (string) $value,
        };
    }

    abstract public function parse(array $data): array;
    abstract public function matches(array $data): bool;
    abstract public function validate(array $data): array;
}
