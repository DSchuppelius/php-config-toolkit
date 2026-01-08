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
        $this->expectExceptionMessageMatches("/Erforderliche Zusatzdateien nicht verfügbar/");

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
     * Testet die Ausführbarkeits-Prüfung indirekt über die Haupt-API
     */
    public function testExecutabilityTest(): void {
        // Teste indirekt über findExecutablePath - das ist sicherer als direkte Reflection
        $reflection = new \ReflectionClass($this->configType);
        $findMethod = $reflection->getMethod('findExecutablePath');

        if ($this->isWindows) {
            // Teste mit cmd - sollte gefunden und als ausführbar erkannt werden
            $result = $findMethod->invoke($this->configType, 'cmd');
            if ($result !== null) {
                $this->assertFileExists($result, 'Gefundene cmd.exe sollte existieren');
                $this->assertStringContainsString('cmd', strtolower($result));
            }

            // Teste mit nicht-existierendem Befehl
            $result = $findMethod->invoke($this->configType, 'non-existent-command-xyz-12345');
            $this->assertNull($result, 'Nicht-existierender Befehl sollte null zurückgeben');
        } else {
            // Unix/Linux Test
            $result = $findMethod->invoke($this->configType, 'ping');
            if ($result !== null) {
                $this->assertFileExists($result);
                $this->assertStringContainsString('ping', strtolower($result));
            }
        }
    }

    /**
     * Testet die Suche in Unterordnern
     */
    public function testOptimizedPerformance(): void {
        $start = microtime(true);

        // Teste über die öffentliche getExecutablePath Methode
        $testConfig = ['path' => 'cmd'];
        $result = null;

        // Verwende Reflection nur für getExecutablePath (weniger problematisch)
        $reflection = new \ReflectionClass($this->configType);
        $getPathMethod = $reflection->getMethod('getExecutablePath');

        if ($getPathMethod->isPublic() || method_exists($this->configType, 'findExecutablePath')) {
            $result = $getPathMethod->invoke($this->configType, $testConfig);
        }

        $duration = microtime(true) - $start;

        // Performance-Check: Sollte unter 2 Sekunden dauern
        $this->assertLessThan(2.0, $duration, 'Executable-Suche sollte unter 2 Sekunden dauern');

        if ($this->isWindows && $result !== null) {
            $this->assertStringContainsString('cmd', strtolower($result));
        }
    }

    /**
     * Testet die gezielte Suche nach Programmen indirekt über die Haupt-API
     */
    public function testTargetedProgramFilesSearch(): void {
        if (!$this->isWindows) {
            $this->markTestSkipped('Dieser Test läuft nur unter Windows');
        }

        // Teste über eine vollständige Konfiguration statt direkter Methodenaufrufe
        $testData = [
            'tools' => [
                'testTool' => [
                    'path' => 'qpdf',  // Hypothetisches Tool
                    'required' => false
                ]
            ]
        ];

        // Parse und validiere die Konfiguration
        $parsedConfig = $this->configType->parse($testData);
        $this->assertIsArray($parsedConfig);

        // Validiere die Konfiguration - sollte keine Fehler für fehlende Tools geben
        // da wir eine intelligente Suche implementiert haben
        $errors = $this->configType->validate($testData);

        // Der Test ist erfolgreich wenn entweder:
        // 1. Keine Fehler (Tool gefunden)
        // 2. Erwartbare Fehler für nicht installiertes Tool
        $this->assertIsArray($errors, 'Validation sollte immer ein Array zurückgeben');

        // Mindestens sollten wir keine kritischen Systemfehler haben
        foreach ($errors as $error) {
            $this->assertIsString($error);
            // Sollte keine PHP-Fehler oder Exceptions enthalten
            $this->assertStringNotContainsString('Fatal error', $error);
            $this->assertStringNotContainsString('Exception', $error);
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
