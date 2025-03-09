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

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use Exception;
use ReflectionClass;

class ConfigValidator {
    protected static array $configTypeClasses = [];

    /**
     * Validiert eine JSON-Konfigurationsdatei anhand der passenden `ConfigTypeAbstract`-Klasse.
     *
     * @param string $filePath Pfad zur JSON-Datei.
     * @return array Liste der Fehler, falls vorhanden, sonst ein leeres Array.
     * @throws Exception Falls die Datei nicht gefunden oder ungültig ist.
     */
    public static function validate(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new Exception("Konfigurationsdatei nicht gefunden: {$filePath}");
        }

        $jsonContent = file_get_contents($filePath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
        }

        self::loadAvailableConfigTypes();

        $configType = self::detectConfigType($data);
        return $configType->validate($data);
    }

    /**
     * Erkennt alle `ConfigTypeAbstract`-Klassen aus `ConfigToolkit\ConfigTypes`.
     */
    protected static function loadAvailableConfigTypes(): void {
        if (!empty(self::$configTypeClasses)) {
            return; // Falls bereits geladen, nicht erneut scannen
        }

        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, 'ConfigToolkit\\ConfigTypes\\') && is_subclass_of($class, ConfigTypeAbstract::class)) {
                self::$configTypeClasses[] = $class;
            }
        }

        if (empty(self::$configTypeClasses)) {
            throw new Exception("Keine gültigen Konfigurationstypen gefunden.");
        }
    }

    /**
     * Erkennt den passenden Konfigurationstyp, indem alle registrierten Klassen geprüft werden.
     */
    protected static function detectConfigType(array $data): ConfigTypeAbstract {
        foreach (self::$configTypeClasses as $class) {
            $reflection = new ReflectionClass($class);

            if (!$reflection->isAbstract() && $reflection->implementsInterface(ConfigTypeInterface::class)) {
                $instance = $reflection->newInstance();
                if ($instance->matches($data)) {
                    return $instance;
                }
            }
        }

        throw new Exception("Unbekannter Konfigurationstyp in der Datei.");
    }
}
