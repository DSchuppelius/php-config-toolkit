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
use DirectoryIterator;
use Exception;
use UnexpectedValueException;

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
     * Optimiert für bessere Performance.
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
        exec("$lookupCommand " . escapeshellarg($command) . " 2>nul", $output, $exitCode);

        // Falls mehrere Treffer, teste nur die ersten 3
        $pathsToTest = array_slice($output, 0, 3);
        foreach ($pathsToTest as $line) {
            $line = trim($line);
            if (!empty($line) && file_exists($line)) {
                // Für bekannte, sichere Programme keine weiteren Tests
                if ($this->isKnownSafeExecutable($line)) {
                    return $line;
                }

                // Für andere Programme teste Ausführbarkeit (aber nur schnell)
                if ($this->quickTestExecutability($line)) {
                    return $line;
                }
            }
        }

        // Falls PATH-Suche fehlschlägt, versuche nur die häufigsten Windows-Pfade
        if ($this->isWindows) {
            $foundPath = $this->quickSearchCommonPaths($command);
            if ($foundPath !== null) {
                return $foundPath;
            }
        }

        return null;
    }

    /**
     * Prüft ob es sich um ein bekanntes, sicheres Executable handelt.
     */
    protected function isKnownSafeExecutable(string $path): bool {
        $safeExecutables = [
            'ping.exe',
            'cmd.exe',
            'powershell.exe',
            'java.exe',
            'node.exe',
            'python.exe',
            'git.exe',
            'where.exe'
        ];

        $filename = strtolower(basename($path));
        return in_array($filename, $safeExecutables);
    }

    /**
     * Schnelle Ausführbarkeits-Prüfung ohne tatsächliche Ausführung.
     */
    protected function quickTestExecutability(string $path): bool {
        if (!file_exists($path)) {
            return false;
        }

        // Für Windows: Einfache Prüfungen ohne Ausführung
        if ($this->isWindows) {
            // Für GUI-Programme nur Dateiprüfung
            $guiPrograms = ['notepad.exe', 'calc.exe', 'mspaint.exe', 'wordpad.exe'];
            $fileName = strtolower(basename($path));

            if (in_array($fileName, $guiPrograms)) {
                return pathinfo($path, PATHINFO_EXTENSION) === 'exe';
            }

            // Für andere Programme: Nur Dateiformat prüfen
            return pathinfo($path, PATHINFO_EXTENSION) === 'exe' && filesize($path) > 0;
        }

        return is_executable($path);
    }

    /**
     * Schnelle Suche nur in den wichtigsten Windows-Pfaden.
     */
    protected function quickSearchCommonPaths(string $command): ?string {
        if (!$this->isWindows) {
            return null;
        }

        // Entferne .exe wenn bereits vorhanden
        $baseCommand = preg_replace('/\.exe$/i', '', $command);

        // Nur die wichtigsten Pfade prüfen
        $criticalPaths = [
            'C:\Windows\System32\\' . $baseCommand . '.exe',
            'C:\Windows\\' . $baseCommand . '.exe',
        ];

        foreach ($criticalPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Testet, ob eine ausführbare Datei tatsächlich ausgeführt werden kann.
     * Vereinfacht für bessere Performance.
     */
    protected function testExecutability(string $path): bool {
        if (!file_exists($path)) {
            return false;
        }

        // Für Windows: Vereinfachte Prüfung ohne tatsächliche Ausführung
        if ($this->isWindows) {
            // Für bekannte GUI-Programme wie notepad.exe - nicht starten!
            $guiPrograms = ['notepad.exe', 'calc.exe', 'mspaint.exe', 'wordpad.exe'];
            $fileName = strtolower(basename($path));

            if (in_array($fileName, $guiPrograms)) {
                // Für GUI-Programme nur prüfen ob die Datei existiert und eine .exe ist
                return pathinfo($path, PATHINFO_EXTENSION) === 'exe';
            }

            // Für andere Programme: Nur Dateierweiterung und Größe prüfen
            return pathinfo($path, PATHINFO_EXTENSION) === 'exe' && filesize($path) > 0;
        }

        return is_executable($path);
    }

    /**
     * Sucht eine ausführbare Datei in klassischen Windows-Ordnern.
     * Erweiterte gezielte Suche nach typischen Installationsmustern.
     */
    protected function searchInCommonDirectories(string $command): ?string {
        if (!$this->isWindows) {
            return null;
        }

        // Entferne .exe wenn bereits vorhanden
        $baseCommand = preg_replace('/\.exe$/i', '', $command);

        // Erst schnelle direkte Pfade probieren
        $directPaths = [
            'C:\Windows\System32\\' . $baseCommand . '.exe',
            'C:\Windows\\' . $baseCommand . '.exe',
            'C:\Program Files\\' . $baseCommand . '\\' . $baseCommand . '.exe',
            'C:\Program Files (x86)\\' . $baseCommand . '\\' . $baseCommand . '.exe',
        ];

        foreach ($directPaths as $possiblePath) {
            if (file_exists($possiblePath)) {
                return $possiblePath;
            }
        }

        // Gezielte Suche in Program Files für versionierte Installationen
        $result = $this->searchInProgramFiles($baseCommand);
        if ($result !== null) {
            return $result;
        }

        return null;
    }

    /**
     * Sucht gezielt in Program Files nach Ordnern, die den Programmnamen enthalten.
     * Findet auch versionierte Installationen wie "qpdf 12.2.0".
     */
    protected function searchInProgramFiles(string $baseCommand): ?string {
        $programDirs = [
            'C:\Program Files',
            'C:\Program Files (x86)'
        ];

        foreach ($programDirs as $programDir) {
            if (!is_dir($programDir)) {
                continue;
            }

            try {
                $iterator = new DirectoryIterator($programDir);
                foreach ($iterator as $dirInfo) {
                    if ($dirInfo->isDot() || !$dirInfo->isDir()) {
                        continue;
                    }

                    $folderName = $dirInfo->getFilename();
                    $folderPath = $dirInfo->getPathname();

                    // Prüfe ob der Ordner den Programmnamen enthält (z.B. "qpdf", "qpdf 12.2.0")
                    if (stripos($folderName, $baseCommand) !== false) {
                        $result = $this->searchInProgramFolder($folderPath, $baseCommand);
                        if ($result !== null) {
                            return $result;
                        }
                    }
                }
            } catch (UnexpectedValueException $e) {
                // Zugriffsprobleme - Ordner überspringen
                continue;
            }
        }

        return null;
    }

    /**
     * Sucht in einem spezifischen Programm-Ordner nach der ausführbaren Datei.
     * Berücksichtigt typische Unterordner wie bin/, tools/, etc.
     */
    protected function searchInProgramFolder(string $programPath, string $baseCommand): ?string {
        // Typische Unterordner für ausführbare Dateien
        $subDirs = [
            '',           // Direkt im Hauptordner
            'bin',        // Häufig für Tools wie qpdf
            'tools',      // Häufig für Entwicklertools
            'exe',        // Manche Programme
            'app',        // Manche Anwendungen
        ];

        foreach ($subDirs as $subDir) {
            $searchPath = empty($subDir) ? $programPath : $programPath . DIRECTORY_SEPARATOR . $subDir;

            if (!is_dir($searchPath)) {
                continue;
            }

            // Verschiedene mögliche Dateinamen
            $possibleFiles = [
                $searchPath . DIRECTORY_SEPARATOR . $baseCommand . '.exe',
                $searchPath . DIRECTORY_SEPARATOR . $baseCommand . '.cmd',
                $searchPath . DIRECTORY_SEPARATOR . $baseCommand . '.bat',
            ];

            foreach ($possibleFiles as $possiblePath) {
                if (file_exists($possiblePath)) {
                    return $possiblePath;
                }
            }
        }

        return null;
    }
}
