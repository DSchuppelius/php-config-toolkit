<?php
/*
 * Created on   : Mon Mar 17 2025
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PostmanConfigType.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use Exception;

/**
 * ConfigType für Postman-Umgebungs-Konfigurationen.
 * Unterstützt das Standard-Postman-Export-Format mit id, name und values.
 */
class PostmanConfigType extends ConfigTypeAbstract {
    /**
     * Prüft, ob die gegebene Konfiguration dem Postman-Format entspricht.
     * Erfordert 'id', 'name' und 'values' als Array.
     */
    public static function matches(array $data): bool {
        if (empty($data)) {
            return false;
        }

        return isset($data['id'], $data['name'], $data['values'])
            && is_string($data['id'])
            && is_string($data['name'])
            && is_array($data['values']);
    }

    /**
     * Konvertiert die Postman-Konfigurationsstruktur in eine nutzbare Form.
     */
    public function parse(array $data): array {
        $parsed = [];
        $parsed['id'] = $data['id'];
        $parsed['name'] = $data['name'];

        foreach ($data['values'] as $item) {
            if (!($item['enabled'] ?? true)) {
                continue; // Überspringe deaktivierte Einträge
            }

            if (!isset($item['key'])) {
                $this->logErrorAndThrow(Exception::class, "Fehlender 'key' in Postman-Konfigurationswerten.");
            }

            $key = $item['key'];
            $value = $item['value'] ?? null;
            $type = $item['type'] ?? 'text';

            $parsed['values'][$key] = $this->castValue($value, $type);
        }

        return $parsed;
    }

    /**
     * Validiert die Postman-Konfiguration.
     */
    public function validate(array $data): array {
        $errors = [];

        if (!isset($data['id']) || !is_string($data['id'])) {
            $errors[] = "Fehlende oder ungültige 'id'.";
        }

        if (!isset($data['name']) || !is_string($data['name'])) {
            $errors[] = "Fehlender oder ungültiger 'name'.";
        }

        if (!isset($data['values']) || !is_array($data['values'])) {
            $errors[] = "Fehlende oder ungültige 'values'-Struktur.";
        } else {
            foreach ($data['values'] as $index => $item) {
                if (!isset($item['key']) || !is_string($item['key'])) {
                    $errors[] = "Fehlender oder ungültiger 'key' in values[{$index}].";
                }
                if (!isset($item['value'])) {
                    $errors[] = "Fehlender 'value' in values[{$index}].";
                }
                if (isset($item['enabled']) && !is_bool($item['enabled'])) {
                    $errors[] = "Ungültiger 'enabled'-Wert in values[{$index}]. Muss bool sein.";
                }
            }
        }

        return $errors;
    }
}
