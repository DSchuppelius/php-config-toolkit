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
 * 
 * @example
 * $builder = new CommandBuilder($configLoader);
 * $command = $builder->build('pdftotext', ['[INPUT]' => '/path/to.pdf', '[OUTPUT]' => '/path/to.txt']);
 * // Ergebnis: "pdftotext -layout -enc UTF-8 '/path/to.pdf' '/path/to.txt'"
 */
class CommandBuilder {
    use ErrorLog;

    private ConfigLoader $configLoader;
    private string $defaultSection;

    /**
     * @param ConfigLoader $configLoader Geladener ConfigLoader mit Executable-Konfiguration
     * @param string $defaultSection Standard-Sektion für Executables (z.B. 'shellExecutables')
     */
    public function __construct(ConfigLoader $configLoader, string $defaultSection = 'shellExecutables') {
        $this->configLoader = $configLoader;
        $this->defaultSection = $defaultSection;
    }

    /**
     * Baut einen Shell-Befehl aus der Konfiguration.
     * 
     * @param string $name Name des Executables in der Konfiguration
     * @param array $replacements Platzhalter-Ersetzungen (z.B. ['[INPUT]' => '/path/to/file'])
     * @param array $extraArgs Zusätzliche Argumente die angehängt werden
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

        $command = escapeshellcmd($path);
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
     * @param array $replacements Platzhalter-Ersetzungen
     * @param array $extraArgs Zusätzliche Argumente
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

        $command = escapeshellcmd($javaPath) . ' -jar ' . escapeshellarg($jarPath);
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
     * @return array|null Die Executable-Konfiguration oder null
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
     * @param array $arguments Original-Argumente mit Platzhaltern
     * @param array $replacements Platzhalter-Ersetzungen
     * @return array Aufgelöste und escapte Argumente
     */
    private function resolveArguments(array $arguments, array $replacements): array {
        $resolved = [];

        foreach ($arguments as $arg) {
            $resolvedArg = $arg;

            foreach ($replacements as $placeholder => $value) {
                $resolvedArg = str_replace($placeholder, $value, $resolvedArg);
            }

            // Nur escapen wenn nicht bereits ein Shell-Redirect o.ä. enthalten
            if (!$this->containsShellOperator($resolvedArg)) {
                $resolved[] = escapeshellarg($resolvedArg);
            } else {
                $resolved[] = $resolvedArg;
            }
        }

        return $resolved;
    }

    /**
     * Prüft ob ein Argument Shell-Operatoren enthält (die nicht escaped werden sollen).
     */
    private function containsShellOperator(string $arg): bool {
        // Typische Shell-Operatoren die nicht escaped werden sollen
        return str_contains($arg, '2>&1')
            || str_contains($arg, '|')
            || str_contains($arg, '>')
            || str_contains($arg, '<');
    }

    /**
     * Erstellt einen CommandBuilder aus Config-Dateien.
     * 
     * Convenience-Methode für schnelle Initialisierung.
     * 
     * @param array $configFiles Array von Config-Dateipfaden
     * @param string $defaultSection Standard-Sektion für Executables
     * @return self
     */
    public static function fromConfigFiles(array $configFiles, string $defaultSection = 'shellExecutables'): self {
        $loader = ConfigLoader::getInstance();
        $loader->loadConfigFiles($configFiles);

        return new self($loader, $defaultSection);
    }
}
