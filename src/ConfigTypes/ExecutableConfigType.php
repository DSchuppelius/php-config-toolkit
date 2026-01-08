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
    /**
     * Liste bekannter sicherer System-Executables (lowercase für schnellen Vergleich).
     * Enthält sowohl Windows (.exe) als auch Linux/Unix Varianten.
     */
    protected const KNOWN_SAFE_EXECUTABLES = [
        // Windows
        'ping.exe'       => true,
        'cmd.exe'        => true,
        'powershell.exe' => true,
        'java.exe'       => true,
        'node.exe'       => true,
        'python.exe'     => true,
        'git.exe'        => true,
        'where.exe'      => true,
        // Linux/Unix
        'ping'           => true,
        'bash'           => true,
        'sh'             => true,
        'zsh'            => true,
        'java'           => true,
        'node'           => true,
        'python'         => true,
        'python3'        => true,
        'git'            => true,
        'which'          => true,
        'file'           => true,
        'cat'            => true,
        'ls'             => true,
        'grep'           => true,
        'find'           => true,
        'curl'           => true,
        'wget'           => true,
        'php'            => true,
        'composer'       => true,
        'npm'            => true,
        'yarn'           => true,
    ];

    protected bool $isWindows;

    /** @var bool|null Gecachter Wert für exec-Verfügbarkeit */
    protected static ?bool $canUseExecCache = null;

    public function __construct() {
        $this->isWindows = strtolower(PHP_OS_FAMILY) === 'windows';
    }

    public function parse(array $data): array {
        $parsed = [];

        foreach ($data as $category => $executables) {
            if (!is_array($executables)) {
                continue;
            }

            foreach ($executables as $name => $executable) {
                if (!is_array($executable)) {
                    continue;
                }

                // WICHTIG: required robust normalisieren (bool/int/string)
                $required = $this->normalizeBool($executable['required'] ?? false);

                $executablePath = $this->getExecutablePath($executable);
                $arguments      = $this->getArguments($executable);
                $debugArguments = $this->getDebugArguments($executable);
                $files2Check    = $this->getFiles2Check($executable);
                $allFilesOk     = $this->checkRequiredFiles($files2Check);

                if ($required && empty($executablePath)) {
                    throw new Exception("Fehlender ausführbarer Pfad für '{$name}' in '{$category}' ({$executablePath}).");
                }

                if ($required && !$allFilesOk) {
                    throw new Exception("Erforderliche Zusatzdateien fehlen für '{$name}' in '{$category}'.");
                }

                $parsed[$category][$name] = [
                    'path'           => $executablePath, // aufgelöster Pfad oder null
                    'required'       => $required,
                    'description'    => $executable['description'] ?? '',
                    'arguments'      => $arguments,
                    'debugArguments' => $debugArguments,
                    'files2Check'    => $files2Check,
                    'package'        => $executable['package'] ?? null,
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

                $required = $this->normalizeBool($executable['required'] ?? false);
                $path     = $this->getExecutablePath($executable);

                if ($path === null && $required) {
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
                    if (!$this->isUsableFile((string)$file)) {
                        $errors[] = "Datei fehlt für '{$name}' in '{$category}': {$file}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Normalisiert boolsche Konfigwerte robust.
     * Fix für Fälle wie required="false" (String) => darf nicht truthy werden.
     */
    protected function normalizeBool(mixed $value, bool $default = false): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $b = filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $b ?? $default;
        }

        return $default;
    }

    /**
     * Zusatzdatei ist ok, wenn:
     * - Symlink vorhanden ODER
     * - Datei existiert (und idealerweise lesbar)
     */
    protected function isUsableFile(string $path): bool {
        if ($path === '') {
            return false;
        }

        if (is_link($path)) {
            return true;
        }

        if (!file_exists($path)) {
            return false;
        }

        // optional härter: in deiner Situation sinnvoll, weil magic-Dateien auch lesbar sein müssen
        return is_readable($path);
    }

    /**
     * Prüft, ob alle erforderlichen Dateien existieren und zugänglich sind.
     */
    protected function checkRequiredFiles(array $paths): bool {
        foreach ($paths as $path) {
            if (!$this->isUsableFile((string)$path)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Prüft, ob eine Datei existiert und ausführbar ist.
     */
    protected function isExecutable(?string $path): bool {
        if (empty($path)) {
            return false;
        } elseif ($path === basename($path)) {
            return $this->isCommandExecutableCrossPlatform($path);
        }

        // Windows: `is_executable()` ist unzuverlässig, daher nur `file_exists()` prüfen
        return $this->isWindows ? file_exists($path) : (file_exists($path) && is_executable($path));
    }

    /**
     * Plattformunabhängige Prüfung, ob ein Befehl ausführbar ist.
     */
    protected function isCommandExecutableCrossPlatform(string $command): bool {
        if ($this->isWindows) {
            $command = escapeshellarg($command);
            $result = shell_exec("where {$command} 2>NUL");
            return !empty($result);
        }

        $command = escapeshellarg($command);
        $result = shell_exec("command -v {$command} 2>/dev/null");
        return !empty($result);
    }

    /**
     * Ermittelt den vollständigen Pfad einer ausführbaren Datei.
     */
    protected function getExecutablePath(array $executable): ?string {
        $path = $executable['path'] ?? null;
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if ($this->isExecutable($path)) {
            return $path;
        }

        return $this->findExecutablePath($path);
    }

    /**
     * Gibt ein Array-Feld aus dem Executable zurück, oder ein leeres Array.
     */
    protected function getArrayField(array $executable, string $field): array {
        return isset($executable[$field]) && is_array($executable[$field]) ? $executable[$field] : [];
    }

    /**
     * Gibt die Liste der zu prüfenden Zusatzdateien zurück.
     */
    protected function getFiles2Check(array $executable): array {
        return $this->getArrayField($executable, 'files2Check');
    }

    /**
     * Gibt die Argumente für das ausführbare Programm zurück.
     */
    protected function getArguments(array $executable): array {
        return $this->getArrayField($executable, 'arguments');
    }

    /**
     * Gibt die Debug-Argumente für das ausführbare Programm zurück.
     */
    protected function getDebugArguments(array $executable): array {
        return $this->getArrayField($executable, 'debugArguments');
    }

    /**
     * Prüft ob exec nutzbar ist (shared hosting / hardened php.ini).
     * Ergebnis wird gecacht für bessere Performance.
     */
    protected function canUseExec(): bool {
        if (self::$canUseExecCache !== null) {
            return self::$canUseExecCache;
        }

        if (!function_exists('exec')) {
            return self::$canUseExecCache = false;
        }

        $disabled = (string)ini_get('disable_functions');
        if ($disabled === '') {
            return self::$canUseExecCache = true;
        }

        $disabledList = array_map('trim', explode(',', strtolower($disabled)));
        return self::$canUseExecCache = !in_array('exec', $disabledList, true);
    }

    /**
     * Sucht eine ausführbare Datei im `PATH`.
     * - Erst ohne exec (PATH manuell), damit es auch bei disabled exec funktioniert
     * - Danach optional which/where
     * - Windows: optional Common-Path Search
     */
    protected function findExecutablePath(?string $command): ?string {
        if (empty($command)) {
            return null;
        }

        // Prüfen auf absolute UNIX- oder Windows-Pfade
        $isAbsoluteUnixPath    = (bool)preg_match('/^\//', $command);
        $isAbsoluteWindowsPath = (bool)preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\]{2,}[^\\\\]+[\\\\][^\\\\]+)/', $command);

        if ($isAbsoluteUnixPath || $isAbsoluteWindowsPath) {
            return file_exists($command) ? $command : null;
        }

        // 1) PATH-Suche ohne exec (entscheidend für deinen Fall "file" auf Linux)
        $resolved = $this->resolveFromPath($command);
        if ($resolved !== null) {
            return $resolved;
        }

        // 2) Fallback über which/where (wenn exec erlaubt)
        if ($this->canUseExec()) {
            $output = [];
            $exitCode = 0;

            $lookupCommand = $this->isWindows ? 'where' : 'which';
            $nullDevice    = $this->isWindows ? 'nul' : '/dev/null';

            @exec($lookupCommand . ' ' . escapeshellarg($command) . ' 2>' . $nullDevice, $output, $exitCode);

            // Falls mehrere Treffer, teste nur die ersten 3
            $pathsToTest = array_slice($output, 0, 3);
            foreach ($pathsToTest as $line) {
                $line = trim((string)$line);
                if ($line !== '' && file_exists($line)) {
                    if ($this->isKnownSafeExecutable($line)) {
                        return $line;
                    }
                    if ($this->quickTestExecutability($line)) {
                        return $line;
                    }
                }
            }
        }

        // 3) Plattform-spezifische Fallback-Suche in Standard-Verzeichnissen
        if ($this->isWindows) {
            $foundPath = $this->searchInCommonDirectories($command);
            if ($foundPath !== null) {
                return $foundPath;
            }
        } else {
            // Linux/Unix: Suche in typischen Standardpfaden
            $foundPath = $this->searchInLinuxDirectories($command);
            if ($foundPath !== null) {
                return $foundPath;
            }
        }

        return null;
    }

    /**
     * Sucht eine ausführbare Datei in klassischen Linux/Unix-Verzeichnissen.
     */
    protected function searchInLinuxDirectories(string $command): ?string {
        if ($this->isWindows) {
            return null;
        }

        $standardPaths = [
            '/usr/bin',
            '/usr/local/bin',
            '/bin',
            '/usr/sbin',
            '/sbin',
            '/opt/bin',
            '/snap/bin',
            getenv('HOME') . '/.local/bin',
            getenv('HOME') . '/bin',
        ];

        foreach ($standardPaths as $dir) {
            if ($dir === false || $dir === '' || !is_dir($dir)) {
                continue;
            }

            $candidate = rtrim($dir, '/') . '/' . $command;
            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Plattformneutrale PATH-Auflösung ohne exec().
     */
    protected function resolveFromPath(string $command): ?string {
        $pathEnv = getenv('PATH') ?: '';
        if ($pathEnv === '') {
            return null;
        }

        $dirs = array_values(array_filter(array_map('trim', explode(PATH_SEPARATOR, $pathEnv))));
        if ($dirs === []) {
            return null;
        }

        // Windows: PATHEXT berücksichtigen
        $extensions = [''];
        if ($this->isWindows) {
            $pathext = getenv('PATHEXT') ?: '.EXE;.BAT;.CMD;.COM';
            $extensions = array_values(array_filter(array_map('trim', explode(';', $pathext))));
            if (!in_array('', $extensions, true)) {
                $extensions[] = '';
            }
        }

        foreach ($dirs as $dir) {
            foreach ($extensions as $ext) {
                $candidate = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $command . $ext;
                if (file_exists($candidate)) {
                    if ($this->isWindows || is_executable($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Prüft ob es sich um ein bekanntes, sicheres Executable handelt.
     * Verwendet Hash-Lookup für O(1) Performance.
     */
    protected function isKnownSafeExecutable(string $path): bool {
        $filename = strtolower(basename($path));
        return isset(self::KNOWN_SAFE_EXECUTABLES[$filename]);
    }

    /**
     * Schnelle Ausführbarkeits-Prüfung ohne tatsächliche Ausführung.
     */
    protected function quickTestExecutability(string $path): bool {
        if (!file_exists($path)) {
            return false;
        }

        if ($this->isWindows) {
            $guiPrograms = ['notepad.exe', 'calc.exe', 'mspaint.exe', 'wordpad.exe'];
            $fileName = strtolower(basename($path));

            if (in_array($fileName, $guiPrograms, true)) {
                return pathinfo($path, PATHINFO_EXTENSION) === 'exe';
            }

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

        $baseCommand = preg_replace('/\.exe$/i', '', $command);

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

        return $this->searchInProgramFiles($baseCommand);
    }

    /**
     * Sucht gezielt in Program Files nach Ordnern, die den Programmnamen enthalten.
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

                    if (stripos($folderName, $baseCommand) !== false) {
                        $result = $this->searchInProgramFolder($folderPath, $baseCommand);
                        if ($result !== null) {
                            return $result;
                        }
                    }
                }
            } catch (UnexpectedValueException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Sucht in einem spezifischen Programm-Ordner nach der ausführbaren Datei.
     */
    protected function searchInProgramFolder(string $programPath, string $baseCommand): ?string {
        $subDirs = [
            '',
            'bin',
            'tools',
            'exe',
            'app',
        ];

        foreach ($subDirs as $subDir) {
            $searchPath = $subDir === '' ? $programPath : $programPath . DIRECTORY_SEPARATOR . $subDir;

            if (!is_dir($searchPath)) {
                continue;
            }

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
