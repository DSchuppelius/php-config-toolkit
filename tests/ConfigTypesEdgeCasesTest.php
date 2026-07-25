<?php
/*
 * Created on   : Sat Jul 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfigTypesEdgeCasesTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigTypes\{ExecutableConfigType, PostmanConfigType};
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Regressionstests für Randfälle in den ConfigType-Implementierungen.
 */
class ConfigTypesEdgeCasesTest extends TestCase {
    /**
     * Regression: Ein required-Executable ohne 'path'-Key darf zwar eine Exception
     * werfen, aber keine "Undefined array key"-Warning erzeugen.
     */
    public function test_missing_path_key_throws_without_warning(): void {
        $type = new ExecutableConfigType;

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        try {
            $type->parse(['tools' => ['x' => ['required' => true]]]); // kein 'path'
            $this->fail('Es sollte eine Exception geworfen werden.');
        } catch (Exception $e) {
            $this->assertStringContainsString("Fehlender ausführbarer Pfad für 'x'", $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $undefinedKeyWarnings = array_filter($warnings, static fn (string $w): bool => str_contains($w, 'Undefined array key'));
        $this->assertEmpty($undefinedKeyWarnings, 'Es darf keine "Undefined array key"-Warning entstehen: ' . implode('; ', $warnings));
    }

    /**
     * Regression: PostmanConfigType::parse muss den 'values'-Key immer liefern,
     * auch wenn alle Einträge deaktiviert sind.
     */
    public function test_postman_all_disabled_keeps_values_key(): void {
        $type = new PostmanConfigType;

        $result = $type->parse([
            'id' => 'i',
            'name' => 'n',
            'values' => [
                ['key' => 'a', 'value' => '1', 'enabled' => false],
                ['key' => 'b', 'value' => '2', 'enabled' => false],
            ],
        ]);

        $this->assertArrayHasKey('values', $result);
        $this->assertSame([], $result['values']);
    }

    /**
     * Gegenprobe: Aktive Postman-Einträge landen weiterhin unter 'values'.
     */
    public function test_postman_enabled_values_are_parsed(): void {
        $type = new PostmanConfigType;

        $result = $type->parse([
            'id' => 'i',
            'name' => 'n',
            'values' => [
                ['key' => 'a', 'value' => '1', 'enabled' => true],
                ['key' => 'b', 'value' => '2', 'enabled' => false],
            ],
        ]);

        $this->assertSame(['a' => '1'], $result['values']);
    }
}
