<?php
/*
 * Created on   : Sat Jul 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfigTypeRegistry.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit;

use ConfigToolkit\ConfigTypes\{AdvancedStructuredConfigType, CrossPlatformExecutableConfigType, ExecutableConfigType, PostmanConfigType, StructuredConfigType};
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Zentrale, prozessweit gecachte Erkennung der verfügbaren ConfigType-Plugins.
 *
 * Bündelt die zuvor doppelt vorhandene Typ-Erkennung aus ConfigLoader und
 * ConfigValidator an einer Stelle. Die Reihenfolge der Prüfung ist deterministisch
 * (feste Priorität statt Dateisystem-Iterationsreihenfolge), sodass spezifischere
 * Typen zuverlässig vor dem Fallback (StructuredConfigType) greifen.
 */
final class ConfigTypeRegistry {
    use ErrorLog;

    protected static string $configTypesDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'ConfigTypes';
    protected static string $configTypesNamespace = 'ConfigToolkit\\ConfigTypes';

    /**
     * Feste Prüfreihenfolge: kleinere Zahl = früher geprüft. Unbekannte (z.B. extern
     * ergänzte) Typen werden dazwischen einsortiert, der Fallback bleibt garantiert zuletzt.
     */
    private const PRIORITY = [
        PostmanConfigType::class => 10,
        CrossPlatformExecutableConfigType::class => 20,
        ExecutableConfigType::class => 30,
        AdvancedStructuredConfigType::class => 40,
        StructuredConfigType::class => 100,
    ];

    private const DEFAULT_PRIORITY = 50;

    /** @var array<class-string<ConfigTypeInterface>>|null Gecachte, sortierte Typliste. */
    private static ?array $types = null;

    /**
     * Gibt die verfügbaren ConfigType-Klassen in fester Prüfreihenfolge zurück.
     *
     * @return array<class-string<ConfigTypeInterface>>
     */
    public static function getTypes(?LoggerInterface $logger = null): array {
        if (self::$types !== null) {
            return self::$types;
        }

        $classLoader = new ClassLoader(
            self::$configTypesDirectory,
            self::$configTypesNamespace,
            ConfigTypeInterface::class,
            $logger
        );

        $classes = $classLoader->getClasses();

        usort($classes, static fn (string $a, string $b): int => (self::PRIORITY[$a] ?? self::DEFAULT_PRIORITY)
            <=> (self::PRIORITY[$b] ?? self::DEFAULT_PRIORITY));

        return self::$types = $classes;
    }

    /**
     * Erkennt den passenden ConfigType für die gegebenen Daten.
     *
     * @throws Exception Wenn kein registrierter Typ passt.
     */
    public static function detect(array $data, ?LoggerInterface $logger = null): ConfigTypeInterface {
        foreach (self::getTypes($logger) as $class) {
            if ($class::matches($data)) {
                return new $class;
            }
        }

        self::logErrorAndThrow(Exception::class, "Unbekannter Konfigurationstyp in der aktuellen Datei");
    }

    /**
     * Setzt den Typ-Cache zurück (hauptsächlich für Tests).
     */
    public static function reset(): void {
        self::$types = null;
    }
}
