<?php
/*
 * Created on   : Thu Jan 23 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CommandBuilder.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit;

use ERRORToolkit\Traits\ErrorLog;

/**
 * Baut Shell-Befehle aus der Executable-Konfiguration.
 *
 * Ersetzt Platzhalter in Argumenten und escaped alle Werte sicher.
 * Unterstützt sowohl shellExecutables als auch javaExecutables.
 * Plattformübergreifend kompatibel (Linux/Windows).
 *
 * @example
 * $builder = new CommandBuilder($configLoader);
 * $command = $builder->build('pdftotext', ['[INPUT]' => '/path/to.pdf', '[OUTPUT]' => '/path/to.txt']);
 * // Ergebnis: "'pdftotext' -layout -enc UTF-8 '/path/to.pdf' '/path/to.txt'"
 * // (Pfad und eingesetzte Werte werden zur Sicherheit shell-escaped)
 */
class CommandBuilder {
    use ErrorLog;

    private ConfigLoader $configLoader;
    private string $defaultSection;
    private bool $isWindows;

    /**
     * @param ConfigLoader $configLoader Geladener ConfigLoader mit Executable-Konfiguration
     * @param string $defaultSection Standard-Sektion für Executables (z.B. 'shellExecutables')
     */
    public function __construct(ConfigLoader $configLoader, string $defaultSection = 'shellExecutables') {
        $this->configLoader = $configLoader;
        $this->defaultSection = $defaultSection;
        $this->isWindows = strtolower(PHP_OS_FAMILY) === 'windows';
    }

    /**
     * Baut einen Shell-Befehl aus der Konfiguration.
     *
     * @param string $name Name des Executables in der Konfiguration
     * @param array<string, string> $replacements Platzhalter-Ersetzungen (z.B. ['[INPUT]' => '/path/to/file'])
     * @param list<string> $extraArgs Zusätzliche Argumente die angehängt werden
     * @param string|null $section Config-Sektion (null = defaultSection)
     * @return string|null Der vollständige Befehl oder null wenn nicht konfiguriert
     */
    public function build(string $name, array $replacements = [], array $extraArgs = [], ?string $section = null): ?string {
        $section = $section ?? $this->defaultSection;
        $config = $this->getExecutableConfig($name, $section);

        if ($config === null) {
            $this->logDebug("Keine Konfiguration für '$name' in '$section' gefunden");
            return null;
        }

        $path = $config['path'] ?? null;
        if (empty($path)) {
            $this->logDebug("Kein Pfad für '$name' konfiguriert");
            return null;
        }

        $arguments = $config['arguments'] ?? [];
        $resolvedArgs = $this->resolveArguments($arguments, $replacements);

        // Extra-Argumente anhängen
        foreach ($extraArgs as $arg) {
            $resolvedArgs[] = escapeshellarg($arg);
        }

        $command = escapeshellarg($path);
        if (!empty($resolvedArgs)) {
            $command .= ' ' . implode(' ', $resolvedArgs);
        }

        $this->logDebug("Command gebaut: $command");
        return $command;
    }

    /**
     * Baut einen Java-Befehl (java -jar ...) aus der Konfiguration.
     *
     * @param string $name Name des Java-Executables (z.B. 'pdfbox')
     * @param array<string, string> $replacements Platzhalter-Ersetzungen
     * @param list<string> $extraArgs Zusätzliche Argumente
     * @param string $javaSection Sektion für Java-Executables
     * @return string|null Der vollständige Befehl oder null wenn nicht konfiguriert
     */
    public function buildJava(string $name, array $replacements = [], array $extraArgs = [], string $javaSection = 'javaExecutables'): ?string {
        // Java-Executable holen
        $javaConfig = $this->getExecutableConfig('java', $this->defaultSection);
        $javaPath = $javaConfig['path'] ?? 'java';

        // JAR-Konfiguration holen
        $jarConfig = $this->getExecutableConfig($name, $javaSection);
        if ($jarConfig === null) {
            $this->logDebug("Keine Java-Konfiguration für '$name' in '$javaSection' gefunden");
            return null;
        }

        $jarPath = $jarConfig['path'] ?? null;
        if (empty($jarPath)) {
            $this->logDebug("Kein JAR-Pfad für '$name' konfiguriert");
            return null;
        }

        $arguments = $jarConfig['arguments'] ?? [];
        $resolvedArgs = $this->resolveArguments($arguments, $replacements);

        // Extra-Argumente anhängen
        foreach ($extraArgs as $arg) {
            $resolvedArgs[] = escapeshellarg($arg);
        }

        $command = escapeshellarg($javaPath) . ' -jar ' . escapeshellarg($jarPath);
        if (!empty($resolvedArgs)) {
            $command .= ' ' . implode(' ', $resolvedArgs);
        }

        $this->logDebug("Java-Command gebaut: $command");
        return $command;
    }

    /**
     * Prüft ob ein Executable in der Konfiguration vorhanden und verfügbar ist.
     *
     * @param string $name Name des Executables
     * @param string|null $section Config-Sektion (null = defaultSection)
     * @return bool True wenn Executable konfiguriert und Pfad gefunden
     */
    public function isAvailable(string $name, ?string $section = null): bool {
        $config = $this->getExecutableConfig($name, $section ?? $this->defaultSection);
        return $config !== null && !empty($config['path']);
    }

    /**
     * Gibt die Konfiguration eines Executables zurück.
     *
     * @param string $name Name des Executables
     * @param string|null $section Config-Sektion (null = defaultSection)
     * @return array<string, mixed>|null Die Executable-Konfiguration oder null
     */
    public function getExecutableConfig(string $name, ?string $section = null): ?array {
        $section = $section ?? $this->defaultSection;
        $config = $this->configLoader->get($section, $name);

        if (!is_array($config)) {
            return null;
        }

        return $config;
    }

    /**
     * Gibt den Pfad eines Executables zurück.
     *
     * @param string $name Name des Executables
     * @param string|null $section Config-Sektion (null = defaultSection)
     * @return string|null Der Pfad oder null wenn nicht gefunden
     */
    public function getPath(string $name, ?string $section = null): ?string {
        $config = $this->getExecutableConfig($name, $section);
        return $config['path'] ?? null;
    }

    /**
     * Ersetzt Platzhalter in den Argumenten und escaped sie.
     *
     * Sicherheitsmodell: Das Argument-Template stammt aus der (vertrauenswürdigen)
     * Konfiguration, die eingesetzten Werte sind potenziell durch Nutzer bestimmt
     * (Dateinamen, Passwörter, Betreffzeilen). Die Escaping-Entscheidung wird daher
     * ausschließlich anhand des Templates getroffen, während eingesetzte Werte
     * grundsätzlich neutralisiert werden, damit sie die Argumentstruktur nicht
     * verändern (keine Shell-Operatoren/Redirects/Command-Injection).
     *
     * @param array<int, mixed> $arguments Original-Argumente mit Platzhaltern
     * @param array<string, string> $replacements Platzhalter-Ersetzungen
     * @return list<string> Aufgelöste und escapte Argumente
     */
    private function resolveArguments(array $arguments, array $replacements): array {
        $resolved = [];

        foreach ($arguments as $arg) {
            $arg = (string) $arg;

            // Fall B: Token besteht ausschließlich aus einem Platzhalter (z.B. "[INPUT]").
            if (array_key_exists($arg, $replacements)) {
                $value = (string) $replacements[$arg];
                $trimmed = trim($value);

                // Leerer Wert -> Argument weglassen (bisheriges Verhalten, z.B. [PERM] ohne Wert).
                if ($trimmed === '') {
                    continue;
                }

                // Explizites Leerstring-Sentinel des Aufrufers ('' oder "") -> Shell-Leerstring.
                if ($trimmed === "''" || $trimmed === '""') {
                    $resolved[] = "''";
                    continue;
                }

                // Vom Aufrufer bereits korrekt shell-escapte Token-Sequenz übernehmen
                // (z.B. implode(' ', array_map('escapeshellarg', $files)) für mehrere
                // Eingabedateien). Per Konstruktion injection-sicher.
                if ($this->isShellQuotedSequence($trimmed)) {
                    $resolved[] = $trimmed;
                    continue;
                }

                // Untrusted Wert -> vollständig als EIN Shell-Token escapen.
                $resolved[] = escapeshellarg($value);
                continue;
            }

            // Fall C: Vom Autor vor-gequoteter Einzelplatzhalter ('[X]' oder "[X]").
            $quotedInner = $this->unwrapQuotedPlaceholder($arg, $replacements);
            if ($quotedInner !== null) {
                $quote = $arg[0];
                $value = (string) $replacements[$quotedInner];
                // Wert quote-sicher einsetzen, die vom Autor gesetzten Quotes beibehalten.
                $resolved[] = $quote . $this->escapeInsideQuotes($value, $quote) . $quote;
                continue;
            }

            // Enthält das Template überhaupt einen Platzhalter?
            $hasPlaceholder = false;
            foreach ($replacements as $placeholder => $value) {
                if ($placeholder !== '' && str_contains($arg, $placeholder)) {
                    $hasPlaceholder = true;
                    break;
                }
            }

            // Fall A: Kein Platzhalter -> reiner Template-Token (Shell-Operatoren, feste
            // Flags, vom Autor gesetzte Literale). Vertrauenswürdig, alte Skip-Logik gilt.
            if (!$hasPlaceholder) {
                $resolved[] = $this->shouldSkipEscaping($arg) ? $arg : escapeshellarg($arg);
                continue;
            }

            // Fall D: Text mit eingebettetem Platzhalter (z.B. "--password=[PASS]").
            // Werte roh einsetzen, dann den GESAMTEN Token als ein Shell-Argument escapen,
            // sodass eingesetzte Werte nicht aus dem Argument ausbrechen können.
            $out = $arg;
            foreach ($replacements as $placeholder => $value) {
                if ($placeholder !== '') {
                    $out = str_replace($placeholder, (string) $value, $out);
                }
            }
            $resolved[] = escapeshellarg($out);
        }

        return $resolved;
    }

    /**
     * Erkennt einen vom Autor gequoteten Einzelplatzhalter ('[X]' oder "[X]") und gibt
     * den inneren Platzhalter-Schlüssel zurück, sofern dieser in den Ersetzungen existiert.
     *
     * @param array<string, string> $replacements
     * @return string|null Der innere Platzhalter oder null, wenn kein solcher Fall vorliegt.
     */
    private function unwrapQuotedPlaceholder(string $arg, array $replacements): ?string {
        if (strlen($arg) < 3) {
            return null;
        }

        $quote = $arg[0];
        if (($quote !== "'" && $quote !== '"') || $arg[strlen($arg) - 1] !== $quote) {
            return null;
        }

        $inner = substr($arg, 1, -1);

        return array_key_exists($inner, $replacements) ? $inner : null;
    }

    /**
     * Prüft, ob ein Wert eine bereits korrekt gequotete Shell-Token-Sequenz ist,
     * also aus einem oder mehreren durch einzelne Leerzeichen getrennten Tokens
     * besteht (typisches Ergebnis von implode(' ', array_map('escapeshellarg', ...))).
     *
     * escapeshellarg() ist plattformabhängig: unter POSIX entstehen '...'-Tokens,
     * unter Windows "..."-Tokens. Beide Formen müssen erkannt werden, sonst wird die
     * bereits escapte Sequenz erneut escaped und zu einem einzigen Argument verklebt.
     *
     * Solche Werte sind per Konstruktion injection-sicher (alles liegt innerhalb der
     * Quotes) und dürfen daher unverändert übernommen werden. Unter Windows entfernt
     * escapeshellarg() eingebettete " vollständig, weshalb "[^"]*" exakt passt.
     */
    private function isShellQuotedSequence(string $value): bool {
        $token = $this->isWindows
            ? '"[^"]*"'
            : "'(?:[^']|'\\\\'')*'";

        return (bool) preg_match('/^' . $token . '(?: ' . $token . ')*$/', $value);
    }

    /**
     * Escaped einen Wert für die Einbettung in einen bereits gequoteten Kontext,
     * ohne die umgebenden Quotes selbst hinzuzufügen.
     */
    private function escapeInsideQuotes(string $value, string $quote): string {
        if ($quote === "'") {
            // Bash-Standard: ' -> '\'' (Quote schließen, escaptes ', Quote öffnen)
            return str_replace("'", "'\\''", $value);
        }

        // Double-Quote-Kontext: Sonderzeichen entschärfen.
        return str_replace(['\\', '"', '`', '$'], ['\\\\', '\\"', '\\`', '\\$'], $value);
    }

    /**
     * Prüft ob ein Argument nicht escaped werden soll.
     *
     * Überspringt Escaping wenn:
     * - Shell-Operatoren enthalten sind (2>&1, |, >, <)
     * - Der Wert bereits escaped aussieht (beginnt mit ' oder ")
     * - Mehrere escapte Werte enthalten sind (z.B. '/file1' '/file2')
     * - Null-Devices verwendet werden (/dev/null, NUL)
     *
     * Berücksichtigt plattformspezifische Unterschiede (Linux/Windows).
     */
    private function shouldSkipEscaping(string $arg): bool {
        // Shell-Operatoren (plattformübergreifend)
        if (
            str_contains($arg, '2>&1')
            || str_contains($arg, '|')
            || str_contains($arg, '>')
            || str_contains($arg, '<')
        ) {
            return true;
        }

        // Null-Devices - plattformspezifisch
        if (!$this->isWindows && str_contains($arg, '/dev/null')) {
            return true;
        }
        if ($this->isWindows && preg_match('/\bNUL\b/i', $arg)) {
            return true;
        }

        // Warnung bei falscher Plattform-Verwendung von Null-Devices
        if ($this->isWindows && str_contains($arg, '/dev/null')) {
            $this->logWarning("'/dev/null' funktioniert nicht unter Windows. Verwende 'NUL' stattdessen.");
        }
        if (!$this->isWindows && preg_match('/\bNUL\b/i', $arg)) {
            $this->logWarning("'NUL' erzeugt unter Linux eine Datei. Verwende '/dev/null' stattdessen.");
        }

        // Windows-spezifisches Escape-Zeichen
        if ($this->isWindows && str_contains($arg, '^')) {
            return true;
        }

        // Bereits escaped - Linux-Style (Single Quotes)
        if (
            !$this->isWindows
            && str_starts_with($arg, "'") && str_ends_with($arg, "'")
        ) {
            return true;
        }

        // Bereits escaped - Windows oder Double Quotes (beide Plattformen)
        if (str_starts_with($arg, '"') && str_ends_with($arg, '"')) {
            return true;
        }

        // Mehrere escapte Werte (z.B. für tiffcp mit mehreren Input-Dateien)
        // Linux: '/path1' '/path2'
        if (!$this->isWindows && preg_match("/^'[^']*'(\s+'[^']*')+$/", $arg)) {
            return true;
        }

        // Windows: "path1" "path2"
        if ($this->isWindows && preg_match('/^"[^"]*"(\s+"[^"]*")+$/', $arg)) {
            return true;
        }

        return false;
    }

    /**
     * Erstellt einen CommandBuilder aus Config-Dateien.
     *
     * Convenience-Methode für schnelle Initialisierung.
     *
     * @param list<string> $configFiles Array von Config-Dateipfaden
     * @param string $defaultSection Standard-Sektion für Executables
     */
    public static function fromConfigFiles(array $configFiles, string $defaultSection = 'shellExecutables'): self {
        $loader = ConfigLoader::getInstance();
        $loader->loadConfigFiles($configFiles);

        return new self($loader, $defaultSection);
    }
}
