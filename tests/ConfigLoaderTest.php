<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigLoader;
use ERRORToolkit\Factories\ConsoleLoggerFactory;
use Exception;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase {
    private string $validConfigPath;
    private string $invalidConfigPath;
    private string $postmanConfigPath;
    private string $advancedConfigPath;
    private string $executablesConfigPath;
    private string $crossPlatformExecutablesConfigPath;
    private string $extendedExecutablesConfigPath;

    protected function setUp(): void {
        $this->validConfigPath = __DIR__ . '/test-configs/valid_config.json';
        $this->invalidConfigPath = __DIR__ . '/test-configs/invalid_config.json';
        $this->postmanConfigPath = __DIR__ . '/test-configs/postman_config.json';
        $this->advancedConfigPath = __DIR__ . '/test-configs/advancedvalid_config.json';
        $this->executablesConfigPath = __DIR__ . '/test-configs/executables_config.json';
        $this->crossPlatformExecutablesConfigPath = __DIR__ . '/test-configs/cross_platform_executables_config.json';
        $this->extendedExecutablesConfigPath = __DIR__ . '/test-configs/extended_executables_config.json';
    }

    public function test_can_load_valid_config(): void {
        $config = ConfigLoader::getInstance(ConsoleLoggerFactory::getLogger());
        $config->loadConfigFile($this->validConfigPath);

        $this->assertSame('log.txt', $config->get('Logger', 'logFile'));
        $this->assertSame(5242880, $config->get('Logger', 'maxFileSize'));
        $this->assertSame("5242880", $config->get('Logger', 'maxTestFileSize'));
        $this->assertTrue($config->get('Archive', 'enabled'));
    }

    public function test_can_load_adv_valid_config(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->advancedConfigPath);

        $this->assertSame('/tmp/log.txt', $config->get('Logging', 'path'));
        $this->assertSame(3, $config->get('maxYears', 'previousYears4Internal'));
        $this->assertSame("Vorjahre", $config->get('maxYears', 'previousYearsFolderName4Internal'));
        $this->assertFalse($config->get('Debugging', 'debug'));
        $this->assertIsArray($config->get('TenantIDs'));
        $this->assertIsArray($config->get('DatevDMSMapping'));
    }

    public function test_can_load_postman_config(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->postmanConfigPath);

        $this->assertSame('50f06896-c367-476e-b3ac-1a03179aa9aa', $config->get('id'));
        $this->assertSame('lexoffice API', $config->get('name'));
        $this->assertIsArray($config->get('values'));
    }

    public function test_can_loadin_valid_config(): void {
        $this->expectException(Exception::class);

        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->invalidConfigPath, true);
    }

    public function test_can_load_executables_config(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->executablesConfigPath);

        $ping = $config->get('shellExecutables', 'ping');

        $this->assertNotNull($ping);
        $this->assertSame('ping', $ping['path']);
        $this->assertTrue($ping['required']);
    }

    public function test_can_load_cross_plattform_executables_config(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->crossPlatformExecutablesConfigPath);

        $editor = $config->get('shellExecutables', 'editor');

        $this->assertNotNull($editor);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertSame('c:\windows\system32\notepad.exe', strtolower($editor['path']));
        } else {
            $this->assertSame('/usr/bin/vi', $editor['path']);
        }
        $this->assertTrue($editor['required']);
    }

    public function test_throws_exception_for_missing_config_file(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Konfigurationsdatei nicht gefunden");

        $config = ConfigLoader::getInstance();
        $config->loadConfigFile(__DIR__ . '/test-configs/non_existent.json', true);
    }

    public function test_get_with_replace_params(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->crossPlatformExecutablesConfigPath);

        $params = [
            "[INPUT]" => "/tmp/input.jpg",
            "[OUTPUT]" => "/tmp/output.png",
        ];

        $convertedCommand = $config->getWithReplaceParams("shellExecutables", "editor", $params);

        $this->assertNotNull($convertedCommand);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertSame('c:\windows\system32\notepad.exe', strtolower($convertedCommand['path']));
        } else {
            $this->assertSame('/usr/bin/vi', $convertedCommand['path']);
        }
        $this->assertSame(["'/tmp/input.jpg'", "/tmp/output.png", "--verbose"], $convertedCommand["arguments"]);
    }

    /**
     * Testet die erweiterte Executable-Suche in klassischen Windows-Ordnern
     */
    public function test_extended_executables_config(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->extendedExecutablesConfigPath);

        // Teste ping (sollte immer gefunden werden)
        $ping = $config->get('shellExecutables', 'ping');
        $this->assertIsArray($ping);
        $this->assertNotNull($ping['path']);
        $this->assertTrue($ping['required']);

        // Teste cmd auf Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = $config->get('shellExecutables', 'cmd');
            $this->assertIsArray($cmd);
            // cmd sollte auf Windows immer gefunden werden
            if ($cmd['path'] !== null) {
                $this->assertStringContainsString('cmd', strtolower($cmd['path']));
            }
        }

        // Teste Development Tools
        $developmentTools = $config->get('developmentTools');
        $this->assertIsArray($developmentTools);

        if (isset($developmentTools['java'])) {
            $java = $developmentTools['java'];
            if ($java['path'] !== null) {
                $this->assertSame(['-version'], $java['arguments']);
                $this->assertSame(['-version', '-verbose'], $java['debugArguments']);
            }
        }
    }

    /**
     * Testet die Behandlung nicht existierender Executables
     */
    public function test_non_existent_executables(): void {
        $config = ConfigLoader::getInstance();
        $config->loadConfigFile($this->extendedExecutablesConfigPath);

        $nonexistent = $config->get('shellExecutables', 'nonexistent');
        $this->assertIsArray($nonexistent);
        $this->assertNull($nonexistent['path']); // Sollte nicht gefunden werden
        $this->assertFalse($nonexistent['required']); // Daher keine Exception
    }

    /**
     * Testet die files2Check Funktionalität mit einem separaten Test
     */
    public function test_files2_check_functionality(): void {
        // Erstelle temporäre Testdateien für files2Check Test
        $tempFile1 = tempnam(sys_get_temp_dir(), 'test_file_1_');
        $tempFile2 = tempnam(sys_get_temp_dir(), 'test_file_2_');

        $testConfig = [
            'testTools' => [
                'toolWithFiles' => [
                    'path' => 'ping',
                    'required' => false,
                    'files2Check' => [$tempFile1, $tempFile2],
                ],
            ],
        ];

        $configType = new \ConfigToolkit\ConfigTypes\ExecutableConfigType;
        $result = $configType->parse($testConfig);

        $this->assertIsArray($result['testTools']['toolWithFiles']);
        $this->assertNotNull($result['testTools']['toolWithFiles']['path']);

        // Aufräumen
        unlink($tempFile1);
        unlink($tempFile2);
    }

    /**
     * Testet die Validierung von Executable-Konfigurationen
     */
    public function test_executable_validation(): void {
        $config = ConfigLoader::getInstance();

        // Dies sollte ohne Fehler laden
        $config->loadConfigFile($this->extendedExecutablesConfigPath);

        // Teste dass alle erwarteten Sectionen vorhanden sind
        $this->assertIsArray($config->get('shellExecutables'));
        $this->assertIsArray($config->get('developmentTools'));
    }
}
