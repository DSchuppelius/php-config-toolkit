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

/**
 * ConfigType für ausführbare Programme mit Pfaden und Argumenten.
 * Unterstützt Pfadvalidierung und automatische Suche im System-PATH.
 */
class ExecutableConfigType extends ConfigTypeAbstract {
    protected bool $isWindows;

    public function __construct() {
        $this->isWindows = strtolower(PHP_OS_FAMILY) === 'windows';
    }

    public function parse(array $data): array {
        $parsed = [];

        foreach ($data as $category => $executables) {
            foreach ($executables as $name => $executable) {
                $executablePath = $this->getExecutablePath($executable);
                $arguments = $this->getArguments($executable);
                $debugArguments = $this->getDebugArguments($executable);
                $files2Check = $this->getFiles2Check($executable);
                $allFilesOk = $this->checkRequiredFiles($files2Check);

                if (empty($executablePath) && ($executable['required'] ?? false)) {
                    throw new Exception("Fehlender ausführbarer Pfad für '{$name}' in '{$category}'");
                } elseif (!$allFilesOk && ($executable['required'] ?? false)) {
                    throw new Exception("Erforderliche Zusatzdateien fehlen für '{$name}' in '{$category}'.");
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

    /**
     * Prüft, ob die Konfiguration dem ExecutableConfigType-Format entspricht.
     * Erfordert 'path' und verbietet plattformspezifische Pfade.
     */
    public static function matches(array $data): bool {
        if (empty($data)) {
            return false;
        }

        $hasValidExecutable = false;

        foreach ($data as $section) {
            if (!is_array($section)) {
                continue;
            }

            foreach ($section as $value) {
                if (!is_array($value)) {
                    return false;
                }

                if (!isset($value['path'])) {
                    return false; // `path` MUSS existieren
                }

                if (isset($value['windowsPath']) || isset($value['linuxPath'])) {
                    return false; // Kein `windowsPath` oder `linuxPath` erlaubt
                }

                $hasValidExecutable = true;
            }
        }

        return $hasValidExecutable;
    }

    /**
     * Prüft, ob alle erforderlichen Dateien existieren und zugänglich sind.
     */
    protected function checkRequiredFiles(array $paths): bool {
        foreach ($paths as $path) {
            // Eine der Bedingungen muss erfüllt sein
            if (!file_exists($path) && !is_link($path)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validiert die Executable-Konfiguration.
     *
     * @return array Liste der gefundenen Validierungsfehler.
     */
    public function validate(array $data): array {
        $errors = [];

        foreach ($data as $category => $executables) {
            if (!is_array($executables)) {
                $errors[] = "Kategorie '{$category}' muss ein Array sein.";
                continue;
            }

            foreach ($executables as $name => $executable) {
                if (!is_array($executable)) {
                    $errors[] = "Executable '{$name}' in '{$category}' muss ein Array sein.";
                    continue;
                }

                $path = $this->getExecutablePath($executable);

                if ($path === null && ($executable['required'] ?? false)) {
                    $errors[] = "Kein ausführbarer Pfad für '{$name}' in '{$category}'.";
                }

                if (isset($executable['arguments']) && !is_array($executable['arguments'])) {
                    $errors[] = "Ungültige 'arguments' für '{$name}' in '{$category}' - muss ein Array sein.";
                }

                if (isset($executable['debugArguments']) && !is_array($executable['debugArguments'])) {
                    $errors[] = "Ungültige 'debugArguments' für '{$name}' in '{$category}' - muss ein Array sein.";
                }

                $files2Check = $this->getFiles2Check($executable);
                foreach ($files2Check as $file) {
                    if (!file_exists($file) && !is_link($file)) {
                        $errors[] = "Datei fehlt für '{$name}' in '{$category}': {$file}";
                    }
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

    protected function getFiles2Check(array $executable): array {
        return $executable['files2Check'] ?? [];
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
            return file_exists($command) ? $command : null;
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
                return $line;
            }
        }

        return null;
    }
}
