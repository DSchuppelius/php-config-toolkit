<?php
/**
 * Subprozess-Probe für ExecutableConfigTypeTest: läuft mit aktivem open_basedir
 * (per -d gesetzt) und einem Laravel-artigen Error-Handler, der jede nicht
 * unterdrückte Warnung in eine ErrorException wandelt. Deckt neben
 * isPathWithinOpenBasedir() auch die komplette Executable-Suche und parse() ab
 * (is_dir/file_exists auf verbotenen Standard-/PATH-Verzeichnissen). Exit-Code
 * != 0 bedeutet, dass irgendwo eine open_basedir-Warnung ausgelöst wurde.
 */

declare(strict_types=1);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$type = new ConfigToolkit\ConfigTypes\ExecutableConfigType;
$within = new ReflectionMethod($type, 'isPathWithinOpenBasedir');
$find = new ReflectionMethod($type, 'findExecutablePath');

$parsed = $type->parse([
    'tools' => [
        'probe' => [
            'path' => 'sh',
            'required' => false,
        ],
    ],
]);

var_export([
    'disallowed' => $within->invoke($type, '/usr/local/sbin'),
    'allowed' => $within->invoke($type, __DIR__),
    'find_relative' => $find->invoke($type, 'sh'),
    'find_absolute' => $find->invoke($type, '/usr/bin/sh'),
    'parse_ok' => is_array($parsed),
]);
