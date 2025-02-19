<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigLoader;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase {
    private string $validConfigPath;
    private string $executablesConfigPath;

    protected function setUp(): void {
        $this->validConfigPath = __DIR__ . '/test-configs/valid_config.json';
        $this->executablesConfigPath = __DIR__ . '/test-configs/executables_config.json';
    }

    public function testCanLoadValidConfig(): void {
        $config = ConfigLoader::getInstance($this->validConfigPath);

        $this->assertSame('log.txt', $config->get('Logger', 'logFile'));
        $this->assertSame(5242880, $config->get('Logger', 'maxFileSize'));
        $this->assertTrue($config->get('Archive', 'enabled'));
    }

    public function testCanLoadExecutablesConfig(): void {
        $config = ConfigLoader::getInstance($this->executablesConfigPath);

        $mutt = $config->get('shellExecutables', 'mutt');

        $this->assertNotNull($mutt);
        $this->assertSame('/usr/bin/mutt', $mutt['path']);
        $this->assertTrue($mutt['required']);
    }

    public function testThrowsExceptionForMissingConfigFile(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Konfigurationsdatei nicht gefunden");

        ConfigLoader::getInstance(__DIR__ . '/test-configs/non_existent.json');
    }
}
