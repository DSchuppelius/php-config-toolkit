<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigLoader;
use Exception;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase {
    private string $validConfigPath;
    private string $invalidConfigPath;
    private string $postmanConfigPath;
    private string $advancedConfigPath;
    private string $executablesConfigPath;
    private string $crossPlatformExecutablesConfigPath;

    protected function setUp(): void {
        $this->validConfigPath = __DIR__ . '/test-configs/valid_config.json';
        $this->invalidConfigPath = __DIR__ . '/test-configs/invalid_config.json';
        $this->postmanConfigPath = __DIR__ . '/test-configs/postman_config.json';
        $this->advancedConfigPath = __DIR__ . '/test-configs/advancedvalid_config.json';
        $this->executablesConfigPath = __DIR__ . '/test-configs/executables_config.json';
        $this->crossPlatformExecutablesConfigPath = __DIR__ . '/test-configs/cross_platform_executables_config.json';
    }

    public function testCanLoadValidConfig(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->validConfigPath);

        $this->assertSame('log.txt', $config->get('Logger', 'logFile'));
        $this->assertSame(5242880, $config->get('Logger', 'maxFileSize'));
        $this->assertSame("5242880", $config->get('Logger', 'maxTestFileSize'));
        $this->assertTrue($config->get('Archive', 'enabled'));
    }

    public function testCanLoadAdvValidConfig(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->advancedConfigPath);

        $this->assertSame('/tmp/log.txt', $config->get('Logging', 'path'));
        $this->assertSame(3, $config->get('maxYears', 'previousYears4Internal'));
        $this->assertSame("Vorjahre", $config->get('maxYears', 'previousYearsFolderName4Internal'));
        $this->assertFalse($config->get('Debugging', 'debug'));
        $this->assertIsArray($config->get('TenantIDs'));
        $this->assertIsArray($config->get('DatevDMSMapping'));
    }

    public function testCanLoadPostmanConfig(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->postmanConfigPath);

        $this->assertSame('50f06896-c367-476e-b3ac-1a03179aa9aa', $config->get('id'));
        $this->assertSame('lexoffice API', $config->get('name'));
        $this->assertIsArray($config->get('values'));
    }

    public function testCanLoadinValidConfig(): void {
        $this->expectException(Exception::class);

        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->invalidConfigPath, true);
    }

    public function testCanLoadExecutablesConfig(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->executablesConfigPath);

        $ping = $config->get('shellExecutables', 'ping');

        $this->assertNotNull($ping);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertSame('C:\Windows\System32\PING.EXE', $ping['path']);
        } else {
            $this->assertSame('/usr/bin/ping', $ping['path']);
        }
        $this->assertTrue($ping['required']);
    }

    public function testCanLoadCrossPlattformExecutablesConfig(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->crossPlatformExecutablesConfigPath);

        $editor = $config->get('shellExecutables', 'editor');

        $this->assertNotNull($editor);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertSame('C:\Windows\System32\notepad.exe', $editor['path']);
        } else {
            $this->assertSame('/usr/bin/vi', $editor['path']);
        }
        $this->assertTrue($editor['required']);
    }

    public function testThrowsExceptionForMissingConfigFile(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Konfigurationsdatei nicht gefunden");

        $config = ConfigLoader::getInstance();
        $config->loadConfigFile(__DIR__ . '/test-configs/non_existent.json', true);
    }

    public function testGetWithReplaceParams(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->crossPlatformExecutablesConfigPath);

        $params = [
            "[INPUT]"  => "/tmp/input.jpg",
            "[OUTPUT]" => "/tmp/output.png"
        ];

        $convertedCommand = $config->getWithReplaceParams("shellExecutables", "editor", $params);

        $this->assertNotNull($convertedCommand);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertSame('C:\Windows\System32\notepad.exe', $convertedCommand['path']);
        } else {
            $this->assertSame('/usr/bin/vi', $convertedCommand['path']);
        }
        $this->assertSame(["'/tmp/input.jpg'", "/tmp/output.png"], $convertedCommand["arguments"]);
    }
}