<?php
/*
 * Created on   : Sat Jul 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfigValidatorTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\{ConfigTypeRegistry, ConfigValidator};
use PHPUnit\Framework\TestCase;

/**
 * Tests für den ConfigValidator, insbesondere die eigenständige Nutzung ohne
 * vorher instanziierten ConfigLoader.
 */
class ConfigValidatorTest extends TestCase {
    private string $testConfigsPath;

    protected function setUp(): void {
        $this->testConfigsPath = __DIR__ . '/test-configs/';
    }

    /**
     * Regression: Der Validator muss auch dann funktionieren, wenn zuvor kein
     * ConfigLoader erzeugt wurde (Typ-Erkennung über die zentrale Registry).
     */
    public function test_validates_standalone_without_prior_loader(): void {
        // Registry-Cache leeren, um den "kalten" Zustand zu simulieren.
        ConfigTypeRegistry::reset();

        $errors = ConfigValidator::validate($this->testConfigsPath . 'valid_config.json');

        $this->assertIsArray($errors);
        $this->assertEmpty($errors, 'Valide Konfiguration sollte keine Fehler liefern');
    }

    public function test_detects_errors_in_executable_config(): void {
        ConfigTypeRegistry::reset();

        $errors = ConfigValidator::validate($this->testConfigsPath . 'executables_config.json');

        $this->assertIsArray($errors);
    }

    public function test_throws_for_missing_file(): void {
        $this->expectException(\Exception::class);
        ConfigValidator::validate($this->testConfigsPath . 'does_not_exist.json');
    }
}
