<?php
/*
 * Created on   : Sat Jul 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CommandBuilderSecurityTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\{CommandBuilder, ConfigLoader};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sicherheitsregressionstests für den CommandBuilder.
 *
 * Stellt sicher, dass durch Nutzer bestimmte Platzhalterwerte die Argumentstruktur
 * nicht verändern können (keine Shell-Operatoren/Redirects/Command-Injection),
 * während vertrauenswürdige Template-Konstrukte (Operatoren, '' -Sentinel) erhalten bleiben.
 */
class CommandBuilderSecurityTest extends TestCase {
    private string $tempConfig;

    protected function setUp(): void {
        ConfigLoader::resetInstance();

        $this->tempConfig = sys_get_temp_dir() . '/cb_security_config.json';
        $config = [
            'shellExecutables' => [
                'plain' => [
                    'path' => 'echo',
                    'required' => false,
                    'arguments' => ['[INPUT]'],
                ],
                'embedded' => [
                    'path' => 'echo',
                    'required' => false,
                    'arguments' => ['--password=[PASS]', '[INPUT]'],
                ],
                'quoted' => [
                    'path' => 'echo',
                    'required' => false,
                    'arguments' => ["'[INPUT]'"],
                ],
                'withoperator' => [
                    'path' => 'echo',
                    'required' => false,
                    'arguments' => ['[INPUT]', '2>&1'],
                ],
                'encrypt' => [
                    'path' => 'echo',
                    'required' => false,
                    'arguments' => ['--encrypt', '[UPASS]', '--', '[INPUT]'],
                ],
            ],
        ];
        file_put_contents($this->tempConfig, json_encode($config));
    }

    protected function tearDown(): void {
        ConfigLoader::resetInstance();
        if (file_exists($this->tempConfig)) {
            unlink($this->tempConfig);
        }
    }

    private function builder(): CommandBuilder {
        $loader = ConfigLoader::getInstance();
        $loader->loadConfigFile($this->tempConfig);

        return new CommandBuilder($loader);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function maliciousValues(): array {
        return [
            'redirect' => ['harmless > /tmp/pwned'],
            'pipe' => ['x | id'],
            'chain-and' => ['a && touch /tmp/pwned'],
            'chain-semicolon' => ['a; rm -rf /tmp/x'],
            'subshell' => ['$(id)'],
            'backtick' => ['`id`'],
            'stderr-redirect' => ['x 2>&1 > /tmp/pwned'],
        ];
    }

    #[DataProvider('maliciousValues')]
    public function test_placeholder_only_value_is_never_injected(string $evil): void {
        $command = $this->builder()->build('plain', ['[INPUT]' => $evil]);

        $this->assertNotNull($command);
        // Der bösartige Wert muss als EIN escaptes Shell-Token erscheinen.
        $this->assertStringContainsString(escapeshellarg($evil), $command);
        // Und darf nicht unescaped als Operatorsequenz auftauchen.
        $this->assertStringNotContainsString(' ' . $evil, $command);
    }

    #[DataProvider('maliciousValues')]
    public function test_embedded_value_is_never_injected(string $evil): void {
        $command = $this->builder()->build('embedded', ['[PASS]' => $evil, '[INPUT]' => 'file.pdf']);

        $this->assertNotNull($command);
        // Der gesamte --password=... Token wird als ein escaptes Argument übergeben.
        $this->assertStringContainsString(escapeshellarg('--password=' . $evil), $command);
    }

    #[DataProvider('maliciousValues')]
    public function test_author_quoted_placeholder_value_is_never_injected(string $evil): void {
        $command = $this->builder()->build('quoted', ['[INPUT]' => $evil]);

        $this->assertNotNull($command);
        // Es darf keine ungeschützte einfache Anführungszeichen-Ausbruchsequenz entstehen.
        // Nach dem Entfernen aller korrekt gequoteten Segmente darf kein Operator übrig bleiben.
        $this->assertDoesNotMatchRegularExpression('/(^|[^\\\\])[|;&><`]/', $this->stripQuotedSegments($command));
    }

    public function test_template_operator_is_preserved(): void {
        $command = $this->builder()->build('withoperator', ['[INPUT]' => 'file.pdf']);

        $this->assertNotNull($command);
        // Der vom Autor gesetzte Redirect bleibt als Operator erhalten (nicht escaped).
        $this->assertStringEndsWith('2>&1', $command);
        $this->assertStringContainsString(escapeshellarg('file.pdf'), $command);
    }

    public function test_empty_string_sentinel_is_preserved(): void {
        $command = $this->builder()->build('encrypt', ['[UPASS]' => "''", '[INPUT]' => 'in.pdf']);

        $this->assertNotNull($command);
        // Das Sentinel '' bleibt ein Shell-Leerstring-Token (nicht als 2-Zeichen-Passwort escaped).
        $this->assertStringContainsString(" '' ", $command);
    }

    public function test_pre_escaped_multi_value_sequence_is_preserved(): void {
        // Muster wie beim tiffcp-Merge: der Aufrufer escaped jede Datei einzeln
        // und übergibt sie als eine Sequenz an einen einzelnen Platzhalter.
        $files = ['/tmp/a.tif', '/tmp/b c.tif', "/tmp/o'brien.tif"];
        $value = implode(' ', array_map('escapeshellarg', $files));

        $command = $this->builder()->build('plain', ['[INPUT]' => $value]);

        $this->assertNotNull($command);
        // Die vorescapte Sequenz bleibt als mehrere Tokens erhalten (nicht zu einem verklebt).
        $this->assertStringContainsString($value, $command);
        foreach ($files as $file) {
            $this->assertStringContainsString(escapeshellarg($file), $command);
        }
    }

    public function test_pre_escaped_single_value_is_preserved(): void {
        $value = escapeshellarg('/tmp/single.tif');
        $command = $this->builder()->build('plain', ['[INPUT]' => $value]);

        $this->assertNotNull($command);
        // Kein doppeltes Escapen einer bereits escapten Einzeldatei.
        $this->assertStringContainsString($value, $command);
        $this->assertStringNotContainsString(escapeshellarg($value), $command);
    }

    public function test_empty_value_drops_placeholder_only_argument(): void {
        $command = $this->builder()->build('encrypt', ['[UPASS]' => '', '[INPUT]' => 'in.pdf']);

        $this->assertNotNull($command);
        // Leerer Wert -> das reine Platzhalter-Argument wird weggelassen.
        $this->assertStringContainsString(escapeshellarg('--encrypt'), $command);
        $this->assertStringContainsString(escapeshellarg('in.pdf'), $command);
    }

    /**
     * Entfernt korrekt einfach-gequotete Segmente ('...') aus einem Befehl, sodass nur
     * die "rohe" (ungeschützte) Struktur übrig bleibt.
     */
    private function stripQuotedSegments(string $command): string {
        // Bash-Single-Quote-Segmente inkl. der '\'' -Escapesequenz entfernen.
        return preg_replace("/'(?:[^']|'\\\\'')*'/", '', $command) ?? $command;
    }
}
