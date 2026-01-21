<?php
/*
 * Created on   : Tue Jan 21 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfigDuplicateCheckerTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigDuplicateChecker;
use ConfigToolkit\ConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * Testklasse für den ConfigDuplicateChecker
 */
class ConfigDuplicateCheckerTest extends TestCase {
    private string $testConfigsPath;

    protected function setUp(): void {
        $this->testConfigsPath = __DIR__ . '/test-configs/';
        ConfigLoader::resetInstance();
    }

    protected function tearDown(): void {
        ConfigLoader::resetInstance();
    }

    /**
     * Testet die Erkennung von Duplikaten innerhalb einer Datei
     */
    public function testDetectsDuplicatesWithinFile(): void {
        $checker = new ConfigDuplicateChecker();
        $duplicates = $checker->checkFileForDuplicates($this->testConfigsPath . 'duplicate_config.json');

        $this->assertNotEmpty($duplicates, 'Es sollten Duplikate erkannt werden');

        // Prüfe, dass das Duplikat für "logFile" in der Logger-Sektion erkannt wurde
        $logFileDuplicates = array_filter($duplicates, fn($d) => $d['key'] === 'logFile' && $d['section'] === 'Logger');
        $this->assertNotEmpty($logFileDuplicates, 'Duplikat für logFile sollte erkannt werden');

        // Prüfe, dass das Duplikat für "enabled" in der Archive-Sektion erkannt wurde
        $enabledDuplicates = array_filter($duplicates, fn($d) => $d['key'] === 'enabled' && $d['section'] === 'Archive');
        $this->assertNotEmpty($enabledDuplicates, 'Duplikat für enabled sollte erkannt werden');

        // Prüfe, dass logLevel als Duplikat erkannt wird (auch wenn deaktiviert)
        $logLevelDuplicates = array_filter($duplicates, fn($d) => $d['key'] === 'logLevel' && $d['section'] === 'Logger');
        $this->assertNotEmpty($logLevelDuplicates, 'Duplikat für logLevel sollte erkannt werden');
    }

    /**
     * Testet, dass eine valide Konfiguration keine Duplikate hat
     */
    public function testNoDuplicatesInValidConfig(): void {
        $checker = new ConfigDuplicateChecker();
        $duplicates = $checker->checkFileForDuplicates($this->testConfigsPath . 'valid_config.json');

        $this->assertEmpty($duplicates, 'Valide Konfiguration sollte keine Duplikate haben');
    }

    /**
     * Testet die Erkennung von Überschreibungen zwischen Dateien
     */
    public function testDetectsOverridesBetweenFiles(): void {
        $checker = new ConfigDuplicateChecker();
        $result = $checker->checkFilesForDuplicatesAndOverrides([
            $this->testConfigsPath . 'valid_config.json',
            $this->testConfigsPath . 'override_config.json',
        ]);

        $this->assertArrayHasKey('overrides', $result);
        $this->assertNotEmpty($result['overrides'], 'Es sollten Überschreibungen erkannt werden');

        // Prüfe, dass logFile überschrieben wird
        $logFileOverrides = array_filter($result['overrides'], fn($o) => $o['key'] === 'logFile');
        $this->assertNotEmpty($logFileOverrides, 'Überschreibung für logFile sollte erkannt werden');

        // Prüfe die Details der Überschreibung
        $logFileOverride = reset($logFileOverrides);
        $this->assertEquals('log.txt', $logFileOverride['originalValue']);
        $this->assertEquals('overridden.txt', $logFileOverride['newValue']);
    }

    /**
     * Testet die Integration mit ConfigLoader
     */
    public function testCheckConfigLoader(): void {
        $loader = ConfigLoader::getInstance();
        $loader->loadConfigFiles([
            $this->testConfigsPath . 'valid_config.json',
            $this->testConfigsPath . 'override_config.json',
        ]);

        $checker = new ConfigDuplicateChecker();
        $result = $checker->checkConfigLoader($loader);

        $this->assertArrayHasKey('duplicates', $result);
        $this->assertArrayHasKey('overrides', $result);
        $this->assertNotEmpty($result['overrides'], 'Überschreibungen sollten erkannt werden');
    }

    /**
     * Testet die Convenience-Methode im ConfigLoader
     */
    public function testConfigLoaderCheckForDuplicates(): void {
        $loader = ConfigLoader::getInstance();
        $loader->loadConfigFiles([
            $this->testConfigsPath . 'duplicate_config.json',
        ]);

        $result = $loader->checkForDuplicates();

        $this->assertArrayHasKey('duplicates', $result);
        $this->assertNotEmpty($result['duplicates'], 'Duplikate sollten erkannt werden');
    }

    /**
     * Testet die Helper-Methoden des Checkers
     */
    public function testCheckerHelperMethods(): void {
        $checker = new ConfigDuplicateChecker();

        // Vor dem Check sollten keine Issues vorhanden sein
        $this->assertFalse($checker->hasDuplicates());
        $this->assertFalse($checker->hasOverrides());
        $this->assertFalse($checker->hasIssues());

        // Nach dem Check mit Duplikaten
        $checker->checkFileForDuplicates($this->testConfigsPath . 'duplicate_config.json');

        $this->assertTrue($checker->hasDuplicates());
        $this->assertTrue($checker->hasIssues());
    }

    /**
     * Testet die Reset-Funktionalität
     */
    public function testReset(): void {
        $checker = new ConfigDuplicateChecker();
        $checker->checkFileForDuplicates($this->testConfigsPath . 'duplicate_config.json');

        $this->assertTrue($checker->hasDuplicates());

        $checker->reset();

        $this->assertFalse($checker->hasDuplicates());
        $this->assertEmpty($checker->getDuplicates());
    }

    /**
     * Testet die formatierte Ausgabe
     */
    public function testFormatResults(): void {
        $checker = new ConfigDuplicateChecker();
        $checker->checkFileForDuplicates($this->testConfigsPath . 'duplicate_config.json');

        $formatted = $checker->formatResults();

        $this->assertStringContainsString('DUPLIKATE', $formatted);
        $this->assertStringContainsString('Logger', $formatted);
        $this->assertStringContainsString('logFile', $formatted);
    }

    /**
     * Testet das Verhalten bei nicht existierender Datei
     */
    public function testNonExistentFile(): void {
        $checker = new ConfigDuplicateChecker();
        $duplicates = $checker->checkFileForDuplicates('/non/existent/file.json');

        $this->assertEmpty($duplicates);
    }

    /**
     * Testet das Verhalten bei ungültiger JSON-Datei
     */
    public function testInvalidJsonFile(): void {
        $checker = new ConfigDuplicateChecker();
        $duplicates = $checker->checkFileForDuplicates($this->testConfigsPath . 'invalid_config.json');

        // Sollte ein leeres Array zurückgeben (oder die Duplikate, falls die Datei trotzdem parsbar ist)
        $this->assertIsArray($duplicates);
    }
}
