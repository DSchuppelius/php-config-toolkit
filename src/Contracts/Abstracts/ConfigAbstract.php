<?php

declare(strict_types=1);

/*
 * Created on Tue Jun 03 2025
 *
 * @author Daniel Schuppelius
 * @copyright 2025 Daniel Schuppelius
 * @license MIT License
 * @package ConfigToolkit
 */

namespace ConfigToolkit\Contracts\Abstracts;

use Composer\InstalledVersions;
use ConfigToolkit\{CommandBuilder, ConfigLoader};
use ERRORToolkit\Traits\ErrorLog;
use Psr\Log\LogLevel;

/**
 * Abstrakte Basis-Konfigurationsklasse mit Singleton-Pattern.
 *
 * Stellt allgemeine Konfigurationsfunktionalität bereit:
 * - Singleton-Instanz-Management
 * - ConfigLoader und CommandBuilder Integration
 * - Logging-Konfiguration
 * - Versions-Info
 * - Debug-Modus
 *
 * @example
 * ```php
 * class Config extends ConfigAbstract {
 *     protected static function getDefaultConfigDir(): string {
 *         return __DIR__ . '/../../config';
 *     }
 *
 *     protected static function getProjectName(): string {
 *         return 'my-project';
 *     }
 * }
 *
 * $config = Config::getInstance();
 * $command = $config->buildCommand('pdftotext', ['[INPUT]' => 'file.pdf']);
 * ```
 *
 * @phpstan-consistent-constructor
 */
abstract class ConfigAbstract {
    use ErrorLog;

    protected const COMPOSER_FILE = __DIR__ . '/../../../../composer.json';
    protected const VERSION_FILE = __DIR__ . '/../../../../VERSION';

    /** @var array<class-string<static>, static> */
    protected static array $instances = [];

    protected ConfigLoader $configLoader;
    protected CommandBuilder $commandBuilder;
    protected ?bool $debugOverride = null;

    /**
     * Konstruktor - lädt Konfiguration aus dem angegebenen Verzeichnis.
     *
     * @param string|null $configDir Verzeichnis mit den JSON-Konfigurationsdateien
     * @param bool $throwOnError Bei true wird eine Exception geworfen wenn eine Konfigurationsdatei ungültig ist
     */
    protected function __construct(?string $configDir = null, bool $throwOnError = true) {
        $configDir = $configDir ?? static::getDefaultConfigDir();
        $configFiles = glob($configDir . '/*.json') ?: [];

        $this->configLoader = ConfigLoader::getInstance();
        $this->configLoader->loadConfigFiles($configFiles, $throwOnError);

        $this->commandBuilder = new CommandBuilder($this->configLoader);
    }

    /**
     * Gibt das Standard-Konfigurationsverzeichnis zurück.
     * Muss von konkreten Klassen implementiert werden.
     */
    abstract protected static function getDefaultConfigDir(): string;

    /**
     * Gibt den Projektnamen zurück (für Logging etc.).
     * Muss von konkreten Klassen implementiert werden.
     */
    abstract protected static function getProjectName(): string;

    /**
     * Gibt die Singleton-Instanz zurück.
     *
     * @param string|null $configDir Optionales Konfigurationsverzeichnis (nur beim ersten Aufruf relevant)
     */
    public static function getInstance(?string $configDir = null): static {
        if (!isset(static::$instances[static::class])) {
            static::$instances[static::class] = new static($configDir);
        }
        return static::$instances[static::class];
    }

    /**
     * Setzt die Singleton-Instanz zurück (nützlich für Tests).
     */
    public static function resetInstance(): void {
        unset(static::$instances[static::class]);
    }

    // ----------------------------------------------------------
    //          ConfigLoader Zugriff
    // ----------------------------------------------------------

    /**
     * Gibt den ConfigLoader zurück.
     */
    public function getConfigLoader(): ConfigLoader {
        return $this->configLoader;
    }

    /**
     * Holt einen Konfigurationswert.
     *
     * @param string $section Sektion in der Konfiguration
     * @param string|null $key Schlüssel (null = gesamte Sektion)
     * @param mixed $default Standardwert
     */
    public function getConfig(string $section, ?string $key = null, mixed $default = null): mixed {
        return $this->configLoader->get($section, $key, $default);
    }

    /**
     * Gibt eine komplette Konfigurationssektion zurück.
     *
     * @param string $section Sektion in der Konfiguration
     */
    public function getSection(string $section): array {
        return $this->configLoader->get($section, null, []);
    }

    // ----------------------------------------------------------
    //          CommandBuilder Zugriff
    // ----------------------------------------------------------

    /**
     * Gibt den CommandBuilder zurück.
     */
    public function getCommandBuilder(): CommandBuilder {
        return $this->commandBuilder;
    }

    /**
     * Prüft ob ein Executable verfügbar ist.
     *
     * @param string $name Name des Executables
     */
    public function isExecutableAvailable(string $name): bool {
        return $this->commandBuilder->isAvailable($name);
    }

    /**
     * Gibt den Pfad zu einem Executable zurück.
     *
     * @param string $name Name des Executables
     */
    public function getExecutablePath(string $name): ?string {
        return $this->commandBuilder->getPath($name);
    }

    /**
     * Baut einen Shell-Befehl mit ersetzten Platzhaltern.
     *
     * @param string $name Name des Executables
     * @param array $replacements Platzhalter-Ersetzungen (z.B. ['[INPUT]' => 'file.pdf'])
     * @param array $extraArgs Zusätzliche Argumente
     * @return string|null Der vollständige Befehl oder null wenn nicht konfiguriert/verfügbar
     */
    public function buildCommand(string $name, array $replacements = [], array $extraArgs = []): ?string {
        return $this->commandBuilder->build($name, $replacements, $extraArgs);
    }

    /**
     * Baut einen Java-Befehl (java -jar ...) mit ersetzten Platzhaltern.
     *
     * @param string $name Name des Java-Executables
     * @param array $replacements Platzhalter-Ersetzungen
     * @param array $extraArgs Zusätzliche Argumente
     * @return string|null Der vollständige Befehl oder null wenn nicht konfiguriert
     */
    public function buildJavaCommand(string $name, array $replacements = [], array $extraArgs = []): ?string {
        return $this->commandBuilder->buildJava($name, $replacements, $extraArgs);
    }

    // ----------------------------------------------------------
    //          Logging & Debugging
    // ----------------------------------------------------------

    /**
     * Gibt den konfigurierten Log-Level zurück.
     * Bei aktiviertem Debug-Modus wird immer DEBUG zurückgegeben.
     *
     * @return string PSR-3 LogLevel
     */
    public function getLogLevel(): string {
        if ($this->debugOverride === true) {
            return LogLevel::DEBUG;
        }
        return $this->configLoader->get('Logging', 'level', LogLevel::DEBUG);
    }

    /**
     * Gibt den konfigurierten Log-Pfad zurück.
     */
    public function getLogPath(): ?string {
        return $this->configLoader->get('Logging', 'path');
    }

    /**
     * Gibt den konfigurierten Log-Typ zurück.
     */
    public function getLogType(): string {
        return $this->configLoader->get('Logging', 'log', 'null');
    }

    /**
     * Prüft ob der Debug-Modus aktiviert ist.
     */
    public function isDebugEnabled(): bool {
        return $this->debugOverride ?? $this->configLoader->get('Debugging', 'debug', false);
    }

    /**
     * Aktiviert oder deaktiviert den Debug-Modus programmatisch.
     */
    public function setDebug(bool $debug): void {
        $this->debugOverride = $debug;
    }

    // ----------------------------------------------------------
    //          Version
    // ----------------------------------------------------------

    /**
     * Gibt die Projekt-Version zurück.
     *
     * Versucht zuerst über Composer's InstalledVersions,
     * dann aus einer VERSION-Datei, sonst 'unknown'.
     */
    public function getVersion(): string {
        $composerFile = static::getComposerFilePath();
        $versionFile = static::getVersionFilePath();

        if (file_exists($composerFile) && is_readable($composerFile)) {
            try {
                $content = file_get_contents($composerFile);
                if ($content !== false) {
                    $composer = json_decode($content, true);

                    if (
                        isset($composer['name']) &&
                        class_exists(InstalledVersions::class) &&
                        InstalledVersions::isInstalled($composer['name'])
                    ) {
                        return InstalledVersions::getPrettyVersion($composer['name']) ?? 'unknown';
                    }
                }
            } catch (\Throwable) {
                // Fallback zu VERSION-Datei
            }
        }

        if (file_exists($versionFile) && is_readable($versionFile)) {
            $version = file_get_contents($versionFile);
            if ($version !== false) {
                return trim($version);
            }
        }

        return 'unknown';
    }

    /**
     * Gibt den Pfad zur composer.json zurück.
     * Kann in konkreten Klassen überschrieben werden.
     */
    protected static function getComposerFilePath(): string {
        return dirname(static::getDefaultConfigDir()) . '/composer.json';
    }

    /**
     * Gibt den Pfad zur VERSION-Datei zurück.
     * Kann in konkreten Klassen überschrieben werden.
     */
    protected static function getVersionFilePath(): string {
        return dirname(static::getDefaultConfigDir()) . '/VERSION';
    }
}
