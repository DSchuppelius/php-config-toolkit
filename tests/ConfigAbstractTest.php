<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\CommandBuilder;
use ConfigToolkit\ConfigLoader;
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

    public function testGetInstance(): void {
        $config = TestConfig::getInstance();

        $this->assertInstanceOf(ConfigAbstract::class, $config);
        $this->assertInstanceOf(TestConfig::class, $config);
    }

    public function testSingletonPattern(): void {
        $config1 = TestConfig::getInstance();
        $config2 = TestConfig::getInstance();

        $this->assertSame($config1, $config2);
    }

    public function testResetInstance(): void {
        $config1 = TestConfig::getInstance();
        TestConfig::resetInstance();
        $config2 = TestConfig::getInstance();

        $this->assertNotSame($config1, $config2);
    }

    public function testGetConfigLoader(): void {
        $config = TestConfig::getInstance();

        $this->assertInstanceOf(ConfigLoader::class, $config->getConfigLoader());
    }

    public function testGetCommandBuilder(): void {
        $config = TestConfig::getInstance();

        $this->assertInstanceOf(CommandBuilder::class, $config->getCommandBuilder());
    }

    public function testGetConfig(): void {
        $config = TestConfig::getInstance();

        // Sollte den Default-Wert zurückgeben wenn Sektion nicht existiert
        $result = $config->getConfig('NonExistent', 'key', 'default');
        $this->assertEquals('default', $result);
    }

    public function testGetSection(): void {
        $config = TestConfig::getInstance();

        // Leere Sektion zurückgeben wenn nicht vorhanden
        $result = $config->getSection('NonExistent');
        $this->assertIsArray($result);
    }

    public function testDebugMode(): void {
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

    public function testLogLevel(): void {
        $config = TestConfig::getInstance();

        // Standard LogLevel
        $level = $config->getLogLevel();
        $this->assertIsString($level);

        // Bei Debug sollte LogLevel DEBUG sein
        $config->setDebug(true);
        $this->assertEquals('debug', $config->getLogLevel());
    }

    public function testGetVersion(): void {
        $config = TestConfig::getInstance();

        // Sollte einen String zurückgeben (auch 'unknown' ist gültig)
        $version = $config->getVersion();
        $this->assertIsString($version);
    }

    public function testIsExecutableAvailable(): void {
        $config = TestConfig::getInstance();

        // Nicht konfiguriertes Executable sollte false zurückgeben
        $this->assertFalse($config->isExecutableAvailable('nonexistent'));
    }

    public function testBuildCommand(): void {
        $config = TestConfig::getInstance();

        // Nicht konfiguriertes Executable sollte null zurückgeben
        $command = $config->buildCommand('nonexistent', ['[INPUT]' => 'test.txt']);
        $this->assertNull($command);
    }
}
