<?php

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;

class ExecutableConfigType extends ConfigTypeAbstract {
    public function parse(array $data): array {
        $parsed = [];
        foreach ($data as $category => $executables) {
            foreach ($executables as $name => $executable) {
                $parsed[$category][$name] = [
                    'path' => $executable['path'] ?? null,
                    'required' => $executable['required'] ?? false,
                    'description' => $executable['description'] ?? '',
                    'arguments' => $executable['arguments'] ?? [],
                    'debugArguments' => $executable['debugArguments'] ?? [],
                ];
            }
        }
        return $parsed;
    }

    public function matches(array $data): bool {
        foreach ($data as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach ($section as $key => $value) {
                if (isset($value['path']) && isset($value['arguments']) && is_array($value['arguments'])) {
                    return true;
                }
            }
        }
        return false;
    }

    public function validate(array $data): array {
        $errors = [];

        foreach ($data as $category => $executables) {
            foreach ($executables as $name => $executable) {
                if (!isset($executable['path']) || !is_string($executable['path'])) {
                    $errors[] = "Fehlender oder ungültiger 'path' für '{$name}' in '{$category}'.";
                }
                if (!isset($executable['required']) || !is_bool($executable['required'])) {
                    $errors[] = "Fehlender oder ungültiger 'required' für '{$name}' in '{$category}'.";
                }
                if (!isset($executable['arguments']) || !is_array($executable['arguments'])) {
                    $errors[] = "Fehlender oder ungültiger 'arguments' für '{$name}' in '{$category}'.";
                }
                if (!isset($executable['debugArguments']) || !is_array($executable['debugArguments'])) {
                    $errors[] = "Fehlender oder ungültiger 'debugArguments' für '{$name}' in '{$category}'.";
                }
            }
        }

        return $errors;
    }
}
