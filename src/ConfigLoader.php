<?php

declare(strict_types=1);

namespace ConfigToolkit;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;

class ConfigLoader {
    private static ?self $instance = null;

    private ?LoggerInterface $logger = null;

    protected array $config = [];
    protected array $filePaths = [];
    protected ?ConfigTypeInterface $configType = null;
    protected static array $configTypeClasses = [];
    protected static string $configTypesDirectory = __DIR__ . '/ConfigTypes';
    protected static string $configTypesNamespace = 'ConfigToolkit\\ConfigTypes';

    private static array $logLevelMap = [
        LogLevel::EMERGENCY => LOG_EMERG,
        LogLevel::ALERT     => LOG_ALERT,
        LogLevel::CRITICAL  => LOG_CRIT,
        LogLevel::ERROR     => LOG_ERR,
        LogLevel::WARNING   => LOG_WARNING,
        LogLevel::NOTICE    => LOG_NOTICE,
        LogLevel::INFO      => LOG_INFO,
        LogLevel::DEBUG     => LOG_DEBUG,
    ];

    private function __construct() {
        if (function_exists('openlog')) {
            if (defined('LOG_LOCAL0')) {
                openlog("php-config-toolkit", LOG_PID | LOG_PERROR, LOG_LOCAL0);
            } else {
                openlog("php-config-toolkit", LOG_PID | LOG_PERROR, LOG_USER);
            }
        }
        $this->loadAvailableConfigTypes();
    }

    /**
     * Logging-Funktion mit PSR-3 Kompatibilität
     */
    private function logMessage(string $level, string $message): void {
        if ($this->logger) {
            $this->logger->log($level, $message);
        } else {
            $syslogLevel = self::$logLevelMap[$level] ?? LOG_INFO;

            if (function_exists('syslog')) {
                syslog($syslogLevel, $message);
            } else {
                $tempDir = sys_get_temp_dir();
                $logFile = $tempDir . DIRECTORY_SEPARATOR . "php-config-toolkit.log";
                file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [$level] $message" . PHP_EOL, FILE_APPEND);
            }
        }
    }

    /**
     * Singleton-Pattern, ohne sofortige Konfigurationsdatei
     */
    public static function getInstance(): static {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public function setLogger(LoggerInterface $logger): void {
        $this->logger = $logger;
    }

    /**
     * Lädt eine einzelne Konfigurationsdatei
     */
    public function loadConfigFile(string $filePath, bool $throwException = false): void {
        if (!file_exists($filePath)) {
            $this->logMessage(Loglevel::WARNING, "Konfigurationsdatei nicht gefunden: {$filePath}");
            throw new Exception("Konfigurationsdatei nicht gefunden: {$filePath}");
        }

        $jsonContent = file_get_contents($filePath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logMessage(LogLevel::ERROR, "Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
            throw new Exception("Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
        }

        try {
            $this->configType = $this->detectConfigType($data);
            $parsedConfig = $this->configType->parse($data);

            // Merge der Konfiguration, spätere Dateien überschreiben frühere
            $this->config = array_replace_recursive($this->config, $parsedConfig);
            $this->logMessage(LogLevel::INFO, "Konfigurationsdatei geladen: $filePath");
        } catch (Exception $e) {
            $this->logMessage(LogLevel::ERROR, "Fehler beim Laden der Konfigurationsdatei $filePath: " . $e->getMessage());
            if ($throwException) {
                throw $e;
            }
        }
    }

    /**
     * Lädt mehrere Konfigurationsdateien auf einmal
     */
    public function loadConfigFiles(array $filePaths): void {
        foreach ($filePaths as $filePath) {
            $this->loadConfigFile($filePath);
        }
    }

    /**
     * Dynamisches Laden der Konfigurationsklassen aus dem `configTypes`-Verzeichnis
     */
    protected function loadAvailableConfigTypes(): void {
        if (!empty(self::$configTypeClasses)) {
            return; // Falls bereits geladen, nicht erneut scannen
        }

        $configTypesDir = realpath(self::$configTypesDirectory);

        if ($configTypesDir === false) {
            throw new Exception("Das Verzeichnis für Konfigurationstypen konnte nicht aufgelöst werden: " . self::$configTypesDirectory);
        }

        $files = scandir($configTypesDir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $className = self::$configTypesNamespace . '\\' . pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($className)) {
                $reflectionClass = new ReflectionClass($className);

                if (
                    $reflectionClass->isInstantiable()
                    && $reflectionClass->implementsInterface(ConfigTypeInterface::class)
                    && !$reflectionClass->isAbstract()
                ) {
                    self::$configTypeClasses[] = $className;
                    $this->logMessage(LogLevel::INFO, "ConfigType geladen: $className");
                } else {
                    $this->logMessage(Loglevel::WARNING, "ConfigType übersprungen: $className (nicht instanziierbar oder ungültig)");
                }
            } else {
                $this->logMessage(Loglevel::WARNING, "Klasse existiert nicht oder konnte nicht geladen werden: $className");
            }
        }

        if (empty(self::$configTypeClasses)) {
            $this->logMessage(LogLevel::ERROR, "Keine gültigen Konfigurationstypen gefunden.");
            throw new Exception("Keine gültigen Konfigurationstypen gefunden.");
        }
    }

    /**
     * Erkennt den passenden Konfigurationstyp, indem alle registrierten Klassen geprüft werden.
     */
    protected function detectConfigType(array $data): ConfigTypeAbstract {
        foreach (self::$configTypeClasses as $class) {
            $instance = new $class();
            if ($instance->matches($data)) {
                return $instance;
            }
        }
        $this->logMessage(Loglevel::ERROR, "Unbekannter Konfigurationstyp in der aktuellen Datei");
        throw new Exception("Unbekannter Konfigurationstyp in der aktuellen Datei");
    }

    /**
     * Gibt einen bestimmten Wert aus der geladenen Konfiguration zurück
     */
    public function get(string $section, string $key, $default = null) {
        return $this->config[$section][$key] ?? $default;
    }

    /**
     * Lädt alle Konfigurationsdateien erneut
     */
    public function reload(): void {
        $this->config = [];
        $this->loadConfigFiles($this->filePaths);
    }

    public static function resetInstance(): void {
        self::$instance = null;
    }
}