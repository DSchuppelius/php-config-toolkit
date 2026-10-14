<?php
/*
 * Created on   : Wed Oct 14 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ProcessRunner.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit\Helper;

use InvalidArgumentException;

/**
 * Startet ein kurzes Hilfsprogramm (which, where) ohne Shell und mit Zeitgrenze.
 *
 * Das config-toolkit liegt unter dem php-common-toolkit und kann dessen
 * Shell-Klasse nicht benutzen. Programm und Argumente gehen als Liste an
 * proc_open() (kein /bin/sh, kein cmd.exe: kein Quoting, keine Injektion ueber
 * Argumente); stdout wird mit Frist gelesen, nach Ablauf geht SIGTERM an das
 * Programm, nach einer kurzen Nachfrist SIGKILL. stderr geht ins Leere, die
 * Standardeingabe ist leer. Gedacht fuer Programme ohne eigene Kindprozesse.
 *
 * @internal Nur fuer die Programmsuche des Toolkits.
 */
final class ProcessRunner {
    /** Zeitgrenze in Sekunden, wenn der Aufrufer keine angibt. */
    public const DEFAULT_TIMEOUT = 5.0;

    /** Nachfrist zwischen SIGTERM und SIGKILL. */
    private const KILL_GRACE = 1.0;

    /** Wartezeit je Runde beim Lesen und Warten. */
    private const POLL_MICROSECONDS = 10000;

    /** Hoechstens so viel stdout wird gesammelt; der Rest wird gelesen und verworfen. */
    private const MAX_OUTPUT_BYTES = 1048576;

    /**
     * Ob proc_open() verfuegbar ist (auf gehaerteten Hosts per disable_functions gesperrt).
     */
    public static function canStartProcesses(): bool {
        if (!function_exists('proc_open')) {
            return false;
        }

        $disabled = strtolower((string) ini_get('disable_functions'));
        if ($disabled === '') {
            return true;
        }

        return !in_array('proc_open', array_map('trim', explode(',', $disabled)), true);
    }

    /**
     * Startet ein Programm mit Argumentliste und wartet hoechstens $timeout Sekunden.
     *
     * @param list<string> $command Programm und Argumente, z. B. ['which', 'pdftotext'].
     * @param float $timeout Sekunden bis zum Abbruch (positiv, endlich).
     * @return array{exitCode: int|null, output: string, timedOut: bool}|null null, wenn das
     *         Programm nicht startet; exitCode null nach Abbruch oder Signal.
     * @throws InvalidArgumentException Bei nicht positiver oder nicht endlicher Zeitgrenze.
     */
    public static function run(array $command, float $timeout = self::DEFAULT_TIMEOUT): ?array {
        if (!is_finite($timeout) || $timeout <= 0.0) {
            throw new InvalidArgumentException('Die Zeitgrenze muss eine positive, endliche Zahl sein.');
        }
        if ($command === [] || trim($command[0]) === '' || !self::canStartProcesses()) {
            return null;
        }

        $windows = PHP_OS_FAMILY === 'Windows';
        // Nur Pipes, keine Dateien: /dev/null laege unter open_basedir ausserhalb
        // der erlaubten Pfade, genau dort wird die Programmsuche gebraucht.
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $pipes = [];
        // Mit Argumentliste startet PHP das Programm direkt, ohne /bin/sh bzw. cmd.exe
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return null;
        }

        if (isset($pipes[0])) {
            // Leere Standardeingabe
            fclose($pipes[0]);
        }
        $stdout = $pipes[1];
        $stderr = $pipes[2];
        // Unter Windows blockieren Pipes trotzdem und stream_select() kennt sie nicht:
        // dort wird erst nach dem Ende des Programms gelesen.
        $canPoll = !$windows && stream_set_blocking($stdout, false) && stream_set_blocking($stderr, false);
        $deadline = microtime(true) + $timeout;
        $output = '';
        $exitCode = null;
        $timedOut = false;

        while (true) {
            if ($canPoll) {
                self::append($output, self::drain($stdout));
                // stderr wird gelesen und verworfen (wie 2>/dev/null), damit das Programm nicht haengt
                self::drain($stderr);
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                // exitcode ist nur beim ersten Abruf nach dem Ende gueltig, -1 nach einem Signal
                $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            self::wait($canPoll ? [$stdout, $stderr] : []);
        }

        $finished = true;
        if ($timedOut) {
            $finished = self::terminate($process);
        } else {
            self::append($output, $canPoll ? self::drain($stdout) : (string) stream_get_contents($stdout));
        }

        fclose($stdout);
        fclose($stderr);
        if ($finished) {
            proc_close($process);
        }
        // Sonst raeumt PHP die Ressource ohne blockierendes Warten ab.

        return ['exitCode' => $exitCode, 'output' => $output, 'timedOut' => $timedOut];
    }

    /**
     * Liest, was gerade in der (nicht blockierenden) Pipe steht.
     *
     * @param resource $stream
     */
    private static function drain($stream): string {
        $data = '';
        while (($chunk = fread($stream, 8192)) !== false && $chunk !== '') {
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Haengt Ausgabe an, hoechstens MAX_OUTPUT_BYTES insgesamt.
     */
    private static function append(string &$output, string $chunk): void {
        $room = self::MAX_OUTPUT_BYTES - strlen($output);
        if ($room > 0 && $chunk !== '') {
            $output .= substr($chunk, 0, $room);
        }
    }

    /**
     * Wartet bis Daten anliegen oder eine Runde vorbei ist.
     *
     * @param list<resource> $streams
     */
    private static function wait(array $streams): void {
        $read = array_values(array_filter($streams, static fn ($stream): bool => !feof($stream)));
        if ($read !== []) {
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, self::POLL_MICROSECONDS) !== false) {
                return;
            }
        }

        usleep(self::POLL_MICROSECONDS);
    }

    /**
     * Beendet das Programm: SIGTERM, nach der Nachfrist SIGKILL.
     *
     * @param resource $process
     * @return bool Ob das Programm beendet ist.
     */
    private static function terminate($process): bool {
        foreach ([15, 9] as $signal) {
            @proc_terminate($process, $signal);
            $graceEnd = microtime(true) + self::KILL_GRACE;
            do {
                if (!proc_get_status($process)['running']) {
                    return true;
                }
                usleep(self::POLL_MICROSECONDS);
            } while (microtime(true) < $graceEnd);
        }

        return false;
    }
}
