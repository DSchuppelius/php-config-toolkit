<?php
/**
 * Subprozess-Probe für ExecutableConfigTypeTest: läuft mit aktivem open_basedir
 * (per -d gesetzt) und einem Laravel-artigen Error-Handler, der jede nicht
 * unterdrückte Warnung in eine ErrorException wandelt. Gibt die Ergebnisse von
 * isPathWithinOpenBasedir() als var_export aus; Exit-Code != 0 bedeutet, dass
 * die Prüfung selbst eine open_basedir-Warnung ausgelöst hat.
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
$method = new ReflectionMethod($type, 'isPathWithinOpenBasedir');

var_export([
    'disallowed' => $method->invoke($type, '/usr/local/sbin'),
    'allowed' => $method->invoke($type, __DIR__),
]);
