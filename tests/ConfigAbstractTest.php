<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\{CommandBuilder, ConfigLoader};
use ConfigToolkit\Contracts\Abstracts\ConfigAbstract;
use PHPUnit\Framework\TestCase;

/**
 * Konkrete Test-Implementierung der abstrakten ConfigAbstract.
 */
class TestConfig extends ConfigAbstract {
    protected static function getDefaultConfigDir(): string {
        return __DIR__ . '/test-configs';
    }

    protected static function getProjectName(): string {
        return 'test-project';
    }

    /**
     * Überschreibt den Konstruktor um Fehler bei ungültigen Test-Configs zu ignorieren.
     */
    protected function __construct(?string $configDir = null, bool $throwOnError = false) {
        parent::__construct($configDir, $throwOnError);
    }
}

/**
 * Tests für die abstrakte ConfigAbstract Klasse.
 */
class ConfigAbstractTest extends TestCase {
    protected function setUp(): void {
        // Reset der Singleton-Instanz vor jedem Test
        TestConfig::resetInstance();
        ConfigLoader::resetInstance();
    }

    protected function tearDown(): void {
        TestConfig::resetInstance();
        ConfigLoader::resetInstance();
    }

    public function test_get_instance(): void {
        $config = TestConfig::getInstance();

        $this->assertInstanceOf(ConfigAbstract::class, $config);
        $this->assertInstanceOf(TestConfig::class, $config);
    }

    public function test_singleton_pattern(): void {
        $config1 = TestConfig::getInstance();
        $config2 = TestConfig::getInstance();

        $this->assertSame($config1, $config2);
    }

    public function test_reset_instance(): void {
        $config1 = TestConfig::getInstance();
        TestConfig::resetInstance();
        $config2 = TestConfig::getInstance();

        $this->assertNotSame($config1, $config2);
    }

    public function test_get_config_loader(): void {
        $config = TestConfig::getInstance();

        $this->assertInstanceOf(ConfigLoader::class, $config->getConfigLoader());
    }

    public function test_get_command_builder(): void {
        $config = TestConfig::getInstance();

        $this->assertInstanceOf(CommandBuilder::class, $config->getCommandBuilder());
    }

    public function test_get_config(): void {
        $config = TestConfig::getInstance();

        // Sollte den Default-Wert zurückgeben wenn Sektion nicht existiert
        $result = $config->getConfig('NonExistent', 'key', 'default');
        $this->assertEquals('default', $result);
    }

    public function test_get_section(): void {
        $config = TestConfig::getInstance();

        // Leere Sektion zurückgeben wenn nicht vorhanden
        $result = $config->getSection('NonExistent');
        $this->assertIsArray($result);
    }

    public function test_debug_mode(): void {
        $config = TestConfig::getInstance();

        // Standard: Debug nicht aktiviert
        $this->assertFalse($config->isDebugEnabled());

        // Debug aktivieren
        $config->setDebug(true);
        $this->assertTrue($config->isDebugEnabled());

        // Debug deaktivieren
        $config->setDebug(false);
        $this->assertFalse($config->isDebugEnabled());
    }

    public function test_log_level(): void {
        $config = TestConfig::getInstance();

        // Standard LogLevel
        $level = $config->getLogLevel();
        $this->assertIsString($level);

        // Bei Debug sollte LogLevel DEBUG sein
        $config->setDebug(true);
        $this->assertEquals('debug', $config->getLogLevel());
    }

    public function test_get_version(): void {
        $config = TestConfig::getInstance();

        // Sollte einen String zurückgeben (auch 'unknown' ist gültig)
        $version = $config->getVersion();
        $this->assertIsString($version);
    }

    public function test_is_executable_available(): void {
        $config = TestConfig::getInstance();

        // Nicht konfiguriertes Executable sollte false zurückgeben
        $this->assertFalse($config->isExecutableAvailable('nonexistent'));
    }

    public function test_build_command(): void {
        $config = TestConfig::getInstance();

        // Nicht konfiguriertes Executable sollte null zurückgeben
        $command = $config->buildCommand('nonexistent', ['[INPUT]' => 'test.txt']);
        $this->assertNull($command);
    }
}
