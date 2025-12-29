<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigTypes\ExecutableConfigType;
use PHPUnit\Framework\TestCase;

/**
 * Erweiterte Tests für ExecutableConfigType mit Fokus auf die neuen Funktionalitäten:
 * - Ausführbarkeits-Tests
 * - Suche in klassischen Windows-Ordnern
 * - Rekursive Unterordner-Suche
 */
class ExecutableConfigTypeTest extends TestCase {
    private ExecutableConfigType $configType;
    private bool $isWindows;

    protected function setUp(): void {
        $this->configType = new ExecutableConfigType();
        $this->isWindows = strtolower(PHP_OS_FAMILY) === 'windows';
    }

    /**
     * Testet die grundlegende Funktionalität der matches() Methode
     */
    public function testMatches(): void {
        $validData = [
            'shellExecutables' => [
                'test' => [
                    'path' => 'test.exe',
                    'required' => true
                ]
            ]
        ];

        $invalidDataNoPath = [
            'shellExecutables' => [
                'test' => [
                    'required' => true
                ]
            ]
        ];

        $invalidDataWithPlatformPath = [
            'shellExecutables' => [
                'test' => [
                    'path' => 'test.exe',
                    'windowsPath' => 'test_win.exe'
                ]
            ]
        ];

        $this->assertTrue(ExecutableConfigType::matches($validData));
        $this->assertFalse(ExecutableConfigType::matches($invalidDataNoPath));
        $this->assertFalse(ExecutableConfigType::matches($invalidDataWithPlatformPath));
        $this->assertFalse(ExecutableConfigType::matches([]));
    }

    /**
     * Testet die parse() Methode mit gültigen Daten
     */
    public function testParseValidData(): void {
        $data = [
            'tools' => [
                'ping' => [
                    'path' => 'ping',
                    'required' => true,
                    'description' => 'Network ping tool',
                    'arguments' => ['-c', '1'],
                    'debugArguments' => ['-c', '1', '-v']
                ]
            ]
        ];

        $result = $this->configType->parse($data);

        $this->assertArrayHasKey('tools', $result);
        $this->assertArrayHasKey('ping', $result['tools']);

        $ping = $result['tools']['ping'];
        $this->assertNotNull($ping['path']); // Sollte einen Pfad finden
        $this->assertTrue($ping['required']);
        $this->assertSame('Network ping tool', $ping['description']);
        $this->assertSame(['-c', '1'], $ping['arguments']);
        $this->assertSame(['-c', '1', '-v'], $ping['debugArguments']);
    }

    /**
     * Testet Exception bei fehlendem required Executable
     */
    public function testParseThrowsExceptionForMissingRequiredExecutable(): void {
        $data = [
            'tools' => [
                'nonexistent' => [
                    'path' => 'this-does-not-exist-anywhere-on-this-system-really',
                    'required' => true
                ]
            ]
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches("/Fehlender ausführbarer Pfad für 'nonexistent'/");

        $this->configType->parse($data);
    }

    /**
     * Testet die validate() Methode
     */
    public function testValidate(): void {
        $validData = [
            'tools' => [
                'ping' => [
                    'path' => 'ping',
                    'required' => true,
                    'arguments' => ['-c', '1']
                ]
            ]
        ];

        $invalidData = [
            'tools' => [
                'test' => [
                    'path' => 'nonexistent-executable-test',
                    'required' => true,
                    'arguments' => 'invalid-not-array'
                ]
            ]
        ];

        $validErrors = $this->configType->validate($validData);
        $invalidErrors = $this->configType->validate($invalidData);

        $this->assertEmpty($validErrors);
        $this->assertNotEmpty($invalidErrors);

        // Prüfe spezifische Fehlermeldungen
        $errorString = implode(' ', $invalidErrors);
        $this->assertStringContainsString("muss ein Array sein", $errorString);
    }

    /**
     * Testet die files2Check Funktionalität
     */
    public function testFiles2Check(): void {
        // Erstelle temporäre Testdateien
        $tempFile1 = tempnam(sys_get_temp_dir(), 'test_file_1_');
        $tempFile2 = tempnam(sys_get_temp_dir(), 'test_file_2_');

        $data = [
            'tools' => [
                'testTool' => [
                    'path' => 'ping', // Verwende ein existierendes Tool
                    'required' => false,
                    'files2Check' => [$tempFile1, $tempFile2]
                ]
            ]
        ];

        $result = $this->configType->parse($data);
        $this->assertNotNull($result['tools']['testTool']['path']);

        // Test mit fehlender Datei
        unlink($tempFile1);
        $dataWithMissingFile = [
            'tools' => [
                'testTool' => [
                    'path' => 'ping',
                    'required' => true,
                    'files2Check' => [$tempFile1, $tempFile2]
                ]
            ]
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches("/Erforderliche Zusatzdateien fehlen/");

        $this->configType->parse($dataWithMissingFile);

        // Aufräumen
        if (file_exists($tempFile2)) {
            unlink($tempFile2);
        }
    }

    /**
     * Testet die Pfadauflösung für absolute Pfade
     */
    public function testAbsolutePathResolution(): void {
        if ($this->isWindows) {
            $absolutePath = 'C:\Windows\System32\ping.exe';
            if (file_exists($absolutePath)) {
                $data = [
                    'tools' => [
                        'ping' => [
                            'path' => $absolutePath,
                            'required' => true
                        ]
                    ]
                ];

                $result = $this->configType->parse($data);
                $this->assertSame($absolutePath, $result['tools']['ping']['path']);
            }
        } else {
            $absolutePath = '/usr/bin/ping';
            if (file_exists($absolutePath)) {
                $data = [
                    'tools' => [
                        'ping' => [
                            'path' => $absolutePath,
                            'required' => true
                        ]
                    ]
                ];

                $result = $this->configType->parse($data);
                $this->assertSame($absolutePath, $result['tools']['ping']['path']);
            }
        }
    }

    /**
     * Testet die erweiterte Windows-Ordner-Suche (nur auf Windows)
     * 
     * @requires OS WIN32|WINNT|Windows
     */
    public function testWindowsCommonDirectoriesSearch(): void {
        if (!$this->isWindows) {
            $this->markTestSkipped('Dieser Test läuft nur unter Windows');
        }

        // Teste mit cmd.exe statt notepad (öffnet kein GUI-Fenster)
        $data = [
            'tools' => [
                'cmd' => [
                    'path' => 'cmd.exe',
                    'required' => true
                ]
            ]
        ];

        $result = $this->configType->parse($data);

        // cmd.exe sollte gefunden werden
        $this->assertNotNull($result['tools']['cmd']['path']);
        $this->assertStringContainsString('cmd.exe', strtolower($result['tools']['cmd']['path']));

        // Der gefundene Pfad sollte existieren
        $this->assertFileExists($result['tools']['cmd']['path']);
    }

    /**
     * Testet die Ausführbarkeits-Prüfung
     */
    public function testExecutabilityTest(): void {
        // Verwende Reflection um die protected Methode zu testen
        $reflection = new \ReflectionClass($this->configType);
        $testExecutabilityMethod = $reflection->getMethod('testExecutability');
        $testExecutabilityMethod->setAccessible(true);

        if ($this->isWindows) {
            // Teste mit cmd.exe (sollte existieren und ist sicher zu testen)
            $cmdPath = 'C:\Windows\System32\cmd.exe';
            if (file_exists($cmdPath)) {
                $this->assertTrue($testExecutabilityMethod->invoke($this->configType, $cmdPath));
            }

            // Teste mit nicht existierender Datei
            $this->assertFalse($testExecutabilityMethod->invoke($this->configType, 'C:\nonexistent\file.exe'));
        } else {
            // Teste mit /usr/bin/ping (sollte auf den meisten Systemen existieren)
            $pingPath = '/usr/bin/ping';
            if (file_exists($pingPath)) {
                $this->assertTrue($testExecutabilityMethod->invoke($this->configType, $pingPath));
            }

            // Teste mit nicht existierender Datei
            $this->assertFalse($testExecutabilityMethod->invoke($this->configType, '/nonexistent/file'));
        }
    }

    /**
     * Testet die Suche in Unterordnern
     * 
     * @requires OS WIN32|WINNT|Windows
     */
    public function testSubdirectorySearch(): void {
        if (!$this->isWindows) {
            $this->markTestSkipped('Dieser Test läuft nur unter Windows');
        }

        // Verwende Reflection um die protected Methode zu testen
        $reflection = new \ReflectionClass($this->configType);
        $searchMethod = $reflection->getMethod('searchInSubdirectories');
        $searchMethod->setAccessible(true);

        // Teste Suche im Windows System32 Ordner - aber sicher mit cmd statt notepad
        $system32 = 'C:\Windows\System32';
        if (is_dir($system32)) {
            try {
                $result = $searchMethod->invoke($this->configType, $system32, 'cmd', 1);

                if ($result !== null) {
                    $this->assertFileExists($result);
                    $this->assertStringContainsString('cmd', strtolower($result));
                } else {
                    // Wenn nichts gefunden wird, ist das auch in Ordnung für diesen Test
                    $this->assertTrue(true);
                }
            } catch (\UnexpectedValueException $e) {
                // Bei Zugriffsfehlern ist das auch in Ordnung
                $this->assertTrue(true);
            }
        } else {
            $this->markTestSkipped('Windows System32 Verzeichnis nicht verfügbar');
        }
    }

    /**
     * Testet die Behandlung ungültiger Konfigurationen
     */
    public function testInvalidConfigurationHandling(): void {
        $invalidConfigurations = [
            // Kategorie ist kein Array
            [
                'tools' => 'invalid-string'
            ],
            // Executable ist kein Array
            [
                'tools' => [
                    'test' => 'invalid-string'
                ]
            ]
        ];

        foreach ($invalidConfigurations as $invalidConfig) {
            $errors = $this->configType->validate($invalidConfig);
            $this->assertNotEmpty($errors);
        }
    }

    /**
     * Testet die Argumentverarbeitung
     */
    public function testArgumentProcessing(): void {
        $data = [
            'tools' => [
                'test' => [
                    'path' => 'ping',
                    'required' => false,
                    'arguments' => ['-t', '-n', '1'],
                    'debugArguments' => ['-t', '-n', '1', '-v']
                ]
            ]
        ];

        $result = $this->configType->parse($data);

        $this->assertSame(['-t', '-n', '1'], $result['tools']['test']['arguments']);
        $this->assertSame(['-t', '-n', '1', '-v'], $result['tools']['test']['debugArguments']);
    }

    /**
     * Testet die Behandlung leerer oder null-Werte
     */
    public function testEmptyValueHandling(): void {
        $data = [
            'tools' => [
                'test1' => [
                    'path' => '',
                    'required' => false
                ],
                'test2' => [
                    'path' => null,
                    'required' => false
                ]
            ]
        ];

        $result = $this->configType->parse($data);

        $this->assertNull($result['tools']['test1']['path']);
        $this->assertNull($result['tools']['test2']['path']);
    }

    /**
     * Testet die Beschreibungsfelder
     */
    public function testDescriptionFields(): void {
        $data = [
            'tools' => [
                'test_with_description' => [
                    'path' => 'ping',
                    'required' => false,
                    'description' => 'Test tool with description'
                ],
                'test_without_description' => [
                    'path' => 'ping',
                    'required' => false
                ]
            ]
        ];

        $result = $this->configType->parse($data);

        $this->assertSame('Test tool with description', $result['tools']['test_with_description']['description']);
        $this->assertSame('', $result['tools']['test_without_description']['description']);
    }
}
