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
     * Erweitert um automatische Suche in klassischen Windows-Ordnern bei fehlgeschlagener Ausführung.
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
                // Prüfe ob die gefundene Datei tatsächlich ausführbar ist
                if ($this->testExecutability($line)) {
                    return $line;
                }
            }
        }

        // Falls PATH-Suche fehlschlägt oder Datei nicht ausführbar ist,
        // suche in klassischen Windows-Ordnern
        if ($this->isWindows) {
            $foundPath = $this->searchInCommonDirectories($command);
            if ($foundPath !== null) {
                return $foundPath;
            }
        }

        return null;
    }

    /**
     * Testet, ob eine ausführbare Datei tatsächlich ausgeführt werden kann.
     */
    protected function testExecutability(string $path): bool {
        if (!file_exists($path)) {
            return false;
        }

        // Für Windows: Teste Ausführbarkeit durch sicherere Methoden
        if ($this->isWindows) {
            // Für bekannte GUI-Programme wie notepad.exe - nicht starten!
            $guiPrograms = ['notepad.exe', 'calc.exe', 'mspaint.exe', 'wordpad.exe'];
            $fileName = strtolower(basename($path));

            if (in_array($fileName, $guiPrograms)) {
                // Für GUI-Programme nur prüfen ob die Datei existiert und eine .exe ist
                return pathinfo($path, PATHINFO_EXTENSION) === 'exe';
            }

            $testOutput = [];
            $exitCode = 0;

            // Versuche verschiedene Standard-Optionen für Versionsinformationen
            // Aber nur für Kommandozeilen-Tools
            $versionFlags = ['--version', '-v', '/v', '/?', '--help', '-h'];

            foreach ($versionFlags as $flag) {
                exec(escapeshellarg($path) . " $flag 2>nul", $testOutput, $exitCode);
                if ($exitCode === 0 || !empty($testOutput)) {
                    return true; // Executable reagiert auf Befehle
                }
            }

            // Falls alle Flags fehlschlagen, prüfe nur ob es eine gültige Windows-Executable ist
            // Nicht mehr versuchen die Datei zu starten
            return pathinfo($path, PATHINFO_EXTENSION) === 'exe' && filesize($path) > 0;
        }

        return is_executable($path);
    }

    /**
     * Sucht eine ausführbare Datei in klassischen Windows-Ordnern.
     */
    protected function searchInCommonDirectories(string $command): ?string {
        if (!$this->isWindows) {
            return null;
        }

        // Entferne .exe wenn bereits vorhanden
        $baseCommand = preg_replace('/\.exe$/i', '', $command);

        // Klassische Windows-Ordner für ausführbare Dateien
        $commonDirectories = [
            'C:\Program Files\\',
            'C:\Program Files (x86)\\',
            'C:\Windows\System32\\',
            'C:\Windows\\',
            'C:\Windows\SysWOW64\\',
        ];

        // Füge Benutzerpfade hinzu, falls sie existieren
        $localAppData = getenv('LOCALAPPDATA');
        $appData = getenv('APPDATA');

        if ($localAppData && is_dir($localAppData . '\Programs')) {
            $commonDirectories[] = $localAppData . '\Programs\\';
        }

        if ($appData && is_dir($appData)) {
            $commonDirectories[] = $appData . '\\';
        }

        // Häufige Unterordner-Muster
        $subfolderPatterns = [
            '', // Direkt im Hauptordner
            $baseCommand . '\\',
            $baseCommand . '\bin\\',
            'bin\\',
            'tools\\',
        ];

        foreach ($commonDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach ($subfolderPatterns as $subfolder) {
                $fullPath = $directory . $subfolder;

                if (!is_dir($fullPath)) {
                    continue;
                }

                // Suche nach verschiedenen Executable-Varianten
                $possibleFiles = [
                    $fullPath . $baseCommand . '.exe',
                    $fullPath . $baseCommand . '.cmd',
                    $fullPath . $baseCommand . '.bat',
                    $fullPath . $command, // Originaler Name falls bereits .exe enthält
                ];

                foreach ($possibleFiles as $possiblePath) {
                    if (file_exists($possiblePath) && $this->testExecutability($possiblePath)) {
                        return $possiblePath;
                    }
                }

                // Rekursive Suche in Unterordnern (max. 2 Ebenen tief)
                // Aber nur für sicherere Ordner
                if (!$this->isRestrictedDirectory($fullPath)) {
                    $foundPath = $this->searchInSubdirectories($fullPath, $baseCommand, 2);
                    if ($foundPath !== null) {
                        return $foundPath;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Prüft, ob ein Verzeichnis eingeschränkte Zugriffsr echte hat.
     */
    protected function isRestrictedDirectory(string $directory): bool {
        $restrictedPaths = [
            'C:\Program Files\WindowsApps',
            'C:\Windows\WinSxS',
            'C:\System Volume Information',
            'C:\$Recycle.Bin',
        ];

        $normalizedPath = rtrim(str_replace('/', '\\', $directory), '\\');

        foreach ($restrictedPaths as $restrictedPath) {
            if (stripos($normalizedPath, $restrictedPath) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sucht rekursiv in Unterordnern nach der ausführbaren Datei.
     */
    protected function searchInSubdirectories(string $directory, string $baseCommand, int $maxDepth): ?string {
        if ($maxDepth <= 0 || !is_dir($directory)) {
            return null;
        }

        try {
            $iterator = new \DirectoryIterator($directory);
        } catch (\UnexpectedValueException $e) {
            // Zugriffsrechte-Problem oder anderer Fehler - diesen Ordner überspringen
            return null;
        }

        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $subdirPath = $item->getPathname() . DIRECTORY_SEPARATOR;

            // Suche direkt in diesem Unterordner
            $possibleFiles = [
                $subdirPath . $baseCommand . '.exe',
                $subdirPath . $baseCommand . '.cmd',
                $subdirPath . $baseCommand . '.bat',
            ];

            foreach ($possibleFiles as $possiblePath) {
                if (file_exists($possiblePath) && $this->testExecutability($possiblePath)) {
                    return $possiblePath;
                }
            }

            // Rekursive Suche in tieferen Ebenen (aber nur wenn wir Zugriff haben)
            try {
                $foundPath = $this->searchInSubdirectories($subdirPath, $baseCommand, $maxDepth - 1);
                if ($foundPath !== null) {
                    return $foundPath;
                }
            } catch (\UnexpectedValueException $e) {
                // Zugriffsrechte-Problem - diesen Unterordner überspringen
                continue;
            }
        }

        return null;
    }
}
