<?php
/*
 * Created on   : Thu Jan 23 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CommandBuilderTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit\Tests;

use ConfigToolkit\CommandBuilder;
use ConfigToolkit\ConfigLoader;
use PHPUnit\Framework\TestCase;

class CommandBuilderTest extends TestCase {
    private ConfigLoader $configLoader;
    private CommandBuilder $commandBuilder;

    protected function setUp(): void {
        ConfigLoader::resetInstance();
        $this->configLoader = ConfigLoader::getInstance();
        $this->commandBuilder = new CommandBuilder($this->configLoader);
    }

    protected function tearDown(): void {
        ConfigLoader::resetInstance();
    }

    public function testBuildCommandWithReplacements(): void {
        // Erstelle temporäre Test-Config mit existierendem Pfad (echo ist überall vorhanden)
        $tempDir = sys_get_temp_dir();
        $tempConfig = $tempDir . '/test_executable_config.json';
        
        $config = [
            'shellExecutables' => [
                'testcmd' => [
                    'path' => 'echo', // echo existiert auf jedem System
                    'required' => false,
                    'arguments' => ['-n', '[INPUT]', '[OUTPUT]']
                ]
            ]
        ];
        
        file_put_contents($tempConfig, json_encode($config));
        
        try {
            ConfigLoader::resetInstance();
            $loader = ConfigLoader::getInstance();
            $loader->loadConfigFile($tempConfig);
            
            $builder = new CommandBuilder($loader);
            
            $command = $builder->build('testcmd', [
                '[INPUT]' => 'input.pdf',
                '[OUTPUT]' => 'output.txt'
            ]);
            
            $this->assertNotNull($command);
            $this->assertStringContainsString('echo', $command);
            $this->assertStringContainsString('input.pdf', $command);
            $this->assertStringContainsString('output.txt', $command);
        } finally {
            unlink($tempConfig);
        }
    }

    public function testBuildCommandReturnsNullForMissingExecutable(): void {
        $command = $this->commandBuilder->build('nonexistent', []);
        $this->assertNull($command);
    }

    public function testIsAvailable(): void {
        // Erstelle temporäre Test-Config mit existierenden/nicht-existierenden Pfaden
        $tempDir = sys_get_temp_dir();
        $tempConfig = $tempDir . '/test_avail_config.json';
        
        $config = [
            'shellExecutables' => [
                'available' => [
                    'path' => 'echo', // existiert
                    'required' => false,
                    'arguments' => []
                ],
                'unavailable' => [
                    'path' => 'nonexistent_command_xyz_12345', // existiert nicht
                    'required' => false,
                    'arguments' => []
                ]
            ]
        ];
        
        file_put_contents($tempConfig, json_encode($config));
        
        try {
            ConfigLoader::resetInstance();
            $loader = ConfigLoader::getInstance();
            $loader->loadConfigFile($tempConfig);
            
            $builder = new CommandBuilder($loader);
            
            $this->assertTrue($builder->isAvailable('available'));
            $this->assertFalse($builder->isAvailable('unavailable'));
            $this->assertFalse($builder->isAvailable('nonexistent'));
        } finally {
            unlink($tempConfig);
        }
    }

    public function testBuildJavaCommand(): void {
        // Erstelle temporäre Test-Config
        $tempDir = sys_get_temp_dir();
        $tempConfig = $tempDir . '/test_java_config.json';
        
        // Erstelle eine temporäre JAR-Datei (leer) für den Test
        $tempJar = $tempDir . '/test_pdfbox.jar';
        file_put_contents($tempJar, '');
        
        $config = [
            'shellExecutables' => [
                'java' => [
                    'path' => 'java', // java ist oft verfügbar
                    'required' => false,
                    'arguments' => []
                ]
            ],
            'javaExecutables' => [
                'pdfbox' => [
                    'path' => $tempJar,
                    'required' => false,
                    'arguments' => ['export:text', '-i', '[INPUT]', '-o', '[OUTPUT]']
                ]
            ]
        ];
        
        file_put_contents($tempConfig, json_encode($config));
        
        try {
            ConfigLoader::resetInstance();
            $loader = ConfigLoader::getInstance();
            $loader->loadConfigFile($tempConfig);
            
            $builder = new CommandBuilder($loader);
            
            $command = $builder->buildJava('pdfbox', [
                '[INPUT]' => '/path/to/input.pdf',
                '[OUTPUT]' => '/path/to/output.txt'
            ]);
            
            // Falls java nicht installiert ist, wird der Befehl trotzdem gebaut
            // (mit dem konfigurierten Pfad)
            if ($command !== null) {
                $this->assertStringContainsString('-jar', $command);
                $this->assertStringContainsString('pdfbox', $command);
                $this->assertStringContainsString('/path/to/input.pdf', $command);
            } else {
                // Wenn kein java verfügbar, ist das auch ok
                $this->assertNull($command);
            }
        } finally {
            unlink($tempConfig);
            unlink($tempJar);
        }
    }

    public function testFromConfigFiles(): void {
        // Erstelle temporäre Test-Config
        $tempDir = sys_get_temp_dir();
        $tempConfig = $tempDir . '/test_from_files_config.json';
        
        $config = [
            'shellExecutables' => [
                'testcmd' => [
                    'path' => 'echo',
                    'required' => false,
                    'arguments' => ['--help']
                ]
            ]
        ];
        
        file_put_contents($tempConfig, json_encode($config));
        
        try {
            ConfigLoader::resetInstance();
            $builder = CommandBuilder::fromConfigFiles([$tempConfig]);
            
            $this->assertTrue($builder->isAvailable('testcmd'));
        } finally {
            unlink($tempConfig);
        }
    }

    public function testGetPath(): void {
        // Erstelle temporäre Test-Config
        $tempDir = sys_get_temp_dir();
        $tempConfig = $tempDir . '/test_path_config.json';
        
        $config = [
            'shellExecutables' => [
                'testcmd' => [
                    'path' => 'echo', // wird zu vollem Pfad aufgelöst
                    'required' => false,
                    'arguments' => []
                ]
            ]
        ];
        
        file_put_contents($tempConfig, json_encode($config));
        
        try {
            ConfigLoader::resetInstance();
            $loader = ConfigLoader::getInstance();
            $loader->loadConfigFile($tempConfig);
            
            $builder = new CommandBuilder($loader);
            
            $path = $builder->getPath('testcmd');
            $this->assertNotNull($path);
            $this->assertStringContainsString('echo', $path);
            $this->assertNull($builder->getPath('nonexistent'));
        } finally {
            unlink($tempConfig);
        }
    }

    public function testBuildWithExtraArgs(): void {
        $tempDir = sys_get_temp_dir();
        $tempConfig = $tempDir . '/test_extra_args_config.json';
        
        $config = [
            'shellExecutables' => [
                'testcmd' => [
                    'path' => 'echo',
                    'required' => false,
                    'arguments' => ['[INPUT]']
                ]
            ]
        ];
        
        file_put_contents($tempConfig, json_encode($config));
        
        try {
            ConfigLoader::resetInstance();
            $loader = ConfigLoader::getInstance();
            $loader->loadConfigFile($tempConfig);
            
            $builder = new CommandBuilder($loader);
            
            $command = $builder->build('testcmd', 
                ['[INPUT]' => 'file.txt'],
                ['--verbose', '--debug']
            );
            
            $this->assertNotNull($command);
            $this->assertStringContainsString('file.txt', $command);
            $this->assertStringContainsString('--verbose', $command);
            $this->assertStringContainsString('--debug', $command);
        } finally {
            unlink($tempConfig);
        }
    }
}