<?php
/*
 * Created on   : Wed Feb 19 2025
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfigValidator.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit;

use ERRORToolkit\Exceptions\FileSystem\FileNotFoundException;
use ERRORToolkit\Traits\ErrorLog;
use Exception;

/**
 * Statische Klasse zur Validierung von JSON-Konfigurationsdateien.
 * Erkennt automatisch den passenden ConfigType und führt dessen Validierung aus.
 */
class ConfigValidator {
    use ErrorLog;

    /**
     * Validiert eine JSON-Konfigurationsdatei anhand des passenden ConfigTypes.
     *
     * @param string $filePath Pfad zur JSON-Datei.
     * @return array Liste der Fehler, falls vorhanden, sonst ein leeres Array.
     * @throws Exception Falls die Datei nicht gefunden oder ungültig ist.
     */
    public static function validate(string $filePath): array {
        if (!file_exists($filePath)) {
            self::logErrorAndThrow(FileNotFoundException::class, "Konfigurationsdatei nicht gefunden: {$filePath}");
        }

        $jsonContent = file_get_contents($filePath);
        $data = json_decode((string) $jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::logErrorAndThrow(Exception::class, "Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
        }

        // Zentrale Typ-Erkennung – stellt sicher, dass die ConfigType-Plugins geladen sind,
        // auch wenn zuvor kein ConfigLoader instanziiert wurde.
        $configType = ConfigTypeRegistry::detect($data, self::getLogger());

        return $configType->validate($data);
    }
}
