<?php

declare(strict_types=1);

namespace Tests;

use ConfigToolkit\ConfigTypes\ExecutableConfigType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests für die Programmsuche von ExecutableConfigType: which/where laufen über
 * ProcessHelper aus dem error-toolkit, ohne Shell und mit Zeitgrenze.
 */
class ExecutableLookupTest extends TestCase {
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
     * Prozesse startet das Toolkit nur über ProcessHelper - ohne Shell und mit Zeitgrenze.
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
                $findings[] = $file->getFilename() . ':' . $token[2] . ' ' . $name . '()';
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
