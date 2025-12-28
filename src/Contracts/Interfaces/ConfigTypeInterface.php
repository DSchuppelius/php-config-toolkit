<?php

declare(strict_types=1);

namespace ConfigToolkit\Contracts\Interfaces;

/**
 * Interface für alle ConfigType-Implementierungen.
 * Definiert die grundlegenden Methoden für das Plugin-System.
 */
interface ConfigTypeInterface {
    /**
     * Parst die Konfigurationsdaten in ein nutzbares Array.
     *
     * @param array $data Die rohen Konfigurationsdaten.
     * @return array Die geparsten Konfigurationsdaten.
     */
    public function parse(array $data): array;

    /**
     * Prüft, ob dieser ConfigType die gegebenen Daten verarbeiten kann.
     *
     * @param array $data Die zu prüfenden Konfigurationsdaten.
     * @return bool True wenn dieser Typ die Daten verarbeiten kann.
     */
    public static function matches(array $data): bool;

    /**
     * Validiert die Konfigurationsdaten und gibt gefundene Fehler zurück.
     *
     * @param array $data Die zu validierenden Konfigurationsdaten.
     * @return array Liste der Validierungsfehler (leer wenn keine Fehler).
     */
    public function validate(array $data): array;
}
