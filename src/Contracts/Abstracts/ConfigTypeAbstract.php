<?php

declare(strict_types=1);

namespace ConfigToolkit\Contracts\Abstracts;

use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;

/**
 * Abstrakte Basisklasse für alle ConfigType-Implementierungen.
 * Stellt gemeinsame Funktionalität wie Type-Casting bereit.
 */
abstract class ConfigTypeAbstract implements ConfigTypeInterface {
    /**
     * Konvertiert einen Wert in den angegebenen Typ.
     *
     * Unterstützte Typen:
     * - float, double: Fließkommazahl
     * - int, integer, number: Ganzzahl
     * - timestamp: Unix-Zeitstempel
     * - date: Datum im Format Y-m-d
     * - datetime: Datum und Zeit im Format Y-m-d H:i:s
     * - bool, boolean: Wahrheitswert
     * - array: Array (JSON-String wird dekodiert)
     * - json: JSON-dekodiertes Array/Objekt
     * - null: Gibt null zurück
     * - text, string (default): Zeichenkette
     *
     * @param mixed $value Der zu konvertierende Wert.
     * @param string $type Der Zieltyp.
     * @return mixed Der konvertierte Wert.
     */
    protected function castValue(mixed $value, string $type): mixed {
        // Null-Werte früh behandeln
        if ($value === null) {
            return match ($type) {
                'float', 'double' => 0.0,
                'int', 'integer', 'number', 'timestamp' => 0,
                'bool', 'boolean' => false,
                'array', 'json' => [],
                'null' => null,
                default => '',
            };
        }

        return match ($type) {
            'float', 'double' => is_numeric($value) ? (float) $value : 0.0,
            'int', 'integer', 'number' => is_numeric($value) ? (int) $value : 0,
            'timestamp' => is_numeric($value) ? (int) $value : (strtotime((string) $value) ?: 0),
            'date' => $this->castToDate($value),
            'datetime' => $this->castToDateTime($value),
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'array', 'json' => $this->castToArray($value),
            'null' => null,
            default => (string) $value,
        };
    }

    /**
     * Konvertiert einen Wert in ein Datum (Y-m-d).
     */
    private function castToDate(mixed $value): ?string {
        $timestamp = strtotime((string) $value);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /**
     * Konvertiert einen Wert in Datum und Zeit (Y-m-d H:i:s).
     */
    private function castToDateTime(mixed $value): ?string {
        $timestamp = strtotime((string) $value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * Konvertiert einen Wert in ein Array.
     * JSON-Strings werden dekodiert, Arrays werden direkt zurückgegeben.
     */
    private function castToArray(mixed $value): array {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Parst die Konfigurationsdaten in ein nutzbares Array.
     */
    abstract public function parse(array $data): array;

    /**
     * Prüft, ob dieser ConfigType die gegebenen Daten verarbeiten kann.
     */
    abstract public static function matches(array $data): bool;

    /**
     * Validiert die Konfigurationsdaten und gibt gefundene Fehler zurück.
     */
    abstract public function validate(array $data): array;
}
