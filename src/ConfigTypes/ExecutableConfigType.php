<?php
/*
 * Created on   : Wed Feb 19 2025
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ExecutableConfigType.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use Exception;

class ExecutableConfigType extends ConfigTypeAbstract {
    protected bool $isWindows;

    public function __construct() {
        $this->isWindows = strtolower(PHP_OS_FAMILY) === "windows"; // Windows oder Linux
    }

    public function parse(array $data): array {
        $parsed = [];

        foreach ($data as $category => $executables) {
            foreach ($executables as $name => $executable) {
                $executablePath = $this->getExecutablePath($executable);
                $arguments = $this->getArguments($executable);
                $debugArguments = $this->getDebugArguments($executable);

                if (empty($executablePath) && ($executable['required'] ?? false)) {
                    throw new Exception("Fehlender ausführbarer Pfad für '{$name}' in '{$category}'");
                }

                $parsed[$category][$name] = [
                    'path'           => $executablePath,
                    'required'       => $executable['required'] ?? false,
                    'description'    => $executable['description'] ?? '',
                    'arguments'      => $arguments,
                    'debugArguments' => $debugArguments,
                ];
            }
        }
        return $parsed;
    }

    public function matches(array $data): bool {
        foreach ($data as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach ($section as $key => $value) {
                if (!isset($value['path'])) {
                    return false; // `path` MUSS existieren
                }
                if (isset($value['windowsPath']) || isset($value['linuxPath'])) {
                    return false; // Kein `windowsPath` oder `linuxPath` erlaubt
                }
            }
        }
        return true;
    }

    public function validate(array $data): array {
        $errors = [];

        foreach ($data as $category => $executables) {
            foreach ($executables as $name => $executable) {
                $path = $this->getExecutablePath($executable);

                if ($path === null && ($executable['required'] ?? false)) {
                    $errors[] = "Kein ausführbarer Pfad für '{$name}' in '{$category}'.";
                }
                if (!isset($executable['arguments']) || !is_array($executable['arguments'])) {
                    $errors[] = "Ungültige oder fehlende 'arguments' für '{$name}' in '{$category}'.";
                }
                if (!isset($executable['debugArguments']) || !is_array($executable['debugArguments'])) {
                    $errors[] = "Ungültige oder fehlende 'debugArguments' für '{$name}' in '{$category}'.";
                }
            }
        }
        return $errors;
    }

    /**
     * Prüft, ob eine Datei existiert und ausführbar ist.
     */
    protected function isExecutable(?string $path): bool {
        if (empty($path)) {
            return false;
        }

        // Windows: `is_executable()` ist unzuverlässig, daher nur `file_exists()` prüfen
        return $this->isWindows ? file_exists($path) : (file_exists($path) && is_executable($path));
    }

    /**
     * Ermittelt den vollständigen Pfad einer ausführbaren Datei.
     */
    protected function getExecutablePath(array $executable): ?string {
        $path = $executable['path'] ?? null;
        return $this->findExecutablePath($path);
    }

    /**
     * Gibt die Argumente für das ausführbare Programm zurück.
     */
    protected function getArguments(array $executable): array {
        return $executable['arguments'] ?? [];
    }

    /**
     * Gibt die Debug-Argumente für das ausführbare Programm zurück.
     */
    protected function getDebugArguments(array $executable): array {
        return $executable['debugArguments'] ?? [];
    }

    /**
     * Sucht eine ausführbare Datei im `PATH`.
     */
    protected function findExecutablePath(?string $command): ?string {
        if (empty($command)) {
            return null;
        }

        // Prüfen auf absolute UNIX- oder Windows-Pfade
        $isAbsoluteUnixPath = preg_match('/^\/[^\/]+(\/[^\/]+)+$/', $command);
        $isAbsoluteWindowsPath = preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\]{2,}[^\\\\]+[\\\\][^\\\\]+)/', $command);

        if ($isAbsoluteUnixPath || $isAbsoluteWindowsPath) {
            return file_exists($command) ? escapeshellarg($command) : null;
        }

        $output = [];
        $exitCode = 0;

        // Finde ausführbaren Pfad in PATH-Umgebung
        $lookupCommand = $this->isWindows ? "where" : "which";
        exec("$lookupCommand " . escapeshellarg($command), $output, $exitCode);

        // Falls mehrere Treffer, nehme den ersten gültigen
        foreach ($output as $line) {
            $line = trim($line);
            if (!empty($line) && file_exists($line)) {
                return escapeshellarg($line);
            }
        }

        return null;
    }
}