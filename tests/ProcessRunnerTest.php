<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigTypes\ExecutableConfigType;
use ConfigToolkit\Helper\ProcessRunner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests für den Prozessstart ohne Shell und mit Zeitgrenze (which/where in der
 * Programmsuche von ExecutableConfigType).
 */
class ProcessRunnerTest extends TestCase {
    public function test_run_returns_output_and_exit_code_without_a_shell(): void {
        $argument = 'a b;c $(x) `y` | z > /tmp/nie';
        $result = ProcessRunner::run([PHP_BINARY, '-r', 'fwrite(STDERR, "fehler"); echo $argv[1] . "[" . stream_get_contents(STDIN) . "]"; exit(3);', $argument]);

        $this->assertNotNull($result);
        $this->assertSame(3, $result['exitCode']);
        $this->assertSame($argument . '[]', $result['output'], 'Argumente unverändert, stderr verworfen, stdin leer');
        $this->assertFalse($result['timedOut']);
    }

    public function test_timeout_terminates_the_process(): void {
        $started = microtime(true);
        $result = ProcessRunner::run([PHP_BINARY, '-r', 'sleep(5);'], 0.5);

        $this->assertNotNull($result);
        $this->assertTrue($result['timedOut']);
        $this->assertNull($result['exitCode']);
        $this->assertLessThan(3.0, microtime(true) - $started);
    }

    public function test_large_output_does_not_block(): void {
        $result = ProcessRunner::run([PHP_BINARY, '-r', 'echo str_repeat("x", 3 * 1048576); fwrite(STDERR, str_repeat("e", 1048576));'], 10.0);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['exitCode']);
        $this->assertSame(1048576, strlen($result['output']));
    }

    public function test_missing_program_returns_null(): void {
        $this->assertNull(ProcessRunner::run(['gibt-es-nicht-' . bin2hex(random_bytes(4))]));
        $this->assertNull(ProcessRunner::run([]));
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function invalidTimeouts(): array {
        return ['null' => [0.0], 'negativ' => [-1.0], 'unendlich' => [INF], 'nan' => [NAN]];
    }

    #[DataProvider('invalidTimeouts')]
    public function test_invalid_timeout_is_rejected(float $timeout): void {
        $this->expectException(InvalidArgumentException::class);
        ProcessRunner::run([PHP_BINARY, '-r', 'echo 1;'], $timeout);
    }

    public function test_lookup_finds_programs_and_never_runs_the_name_as_a_command(): void {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('which-Test nur unter Unix.');
        }
        $lookup = new ReflectionMethod(ExecutableConfigType::class, 'lookupCommand');
        $type = new ExecutableConfigType;

        $found = $lookup->invoke($type, 'sh');
        if ($found === []) {
            $this->markTestSkipped('which ist nicht installiert.');
        }
        $this->assertStringStartsWith('/', $found[0]);

        $marker = sys_get_temp_dir() . '/ctk-lookup-' . bin2hex(random_bytes(4));
        $this->assertSame([], $lookup->invoke($type, "sh; touch $marker"));
        $this->assertSame([], $lookup->invoke($type, '$(touch ' . $marker . ')'));
        $this->assertFileDoesNotExist($marker, 'Der Name darf nie als Shell-Befehl laufen');
    }

    /**
     * Prozesse startet im Toolkit nur ProcessRunner - ohne Shell und mit Zeitgrenze.
     */
    public function test_no_other_class_starts_processes_or_shells(): void {
        $forbidden = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec'];
        $findings = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            foreach ($tokens as $index => $token) {
                if ($token === '`') {
                    $findings[] = $file->getFilename() . ': Backtick';
                    continue;
                }
                if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = strtolower(ltrim($token[1], '\\'));
                if (!in_array($name, $forbidden, true) || !$this->isFunctionCall($tokens, $index)) {
                    continue;
                }
                if (!($name === 'proc_open' && $file->getFilename() === 'ProcessRunner.php')) {
                    $findings[] = $file->getFilename() . ':' . $token[2] . ' ' . $name . '()';
                }
            }
        }

        $this->assertSame([], $findings);
    }

    /**
     * Ein Name ist ein Funktionsaufruf, wenn "(" folgt und kein "->", "::" oder "function" davorsteht.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isFunctionCall(array $tokens, int $index): bool {
        $next = $index + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        if (($tokens[$next] ?? null) !== '(') {
            return false;
        }

        $previous = $index - 1;
        while (isset($tokens[$previous]) && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
            $previous--;
        }
        $before = $tokens[$previous] ?? null;

        return !(is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true));
    }
}
