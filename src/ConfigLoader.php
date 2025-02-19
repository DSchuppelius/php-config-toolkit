<?php

declare(strict_types=1);

namespace ConfigToolkit;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use ConfigToolkit\Contracts\Interfaces\ConfigTypeInterface;
use Exception;
use ReflectionClass;

class ConfigLoader {
    private static ?self $instance = null;
    protected array $config = [];
    protected string $filePath;
    protected ?ConfigTypeInterface $configType = null;
    protected static array $configTypeClasses = [];

    private function __construct(string $filePath) {
        $this->filePath = $filePath;
        $this->loadAvailableConfigTypes();
        $this->loadConfig();
    }

    /**
     * Singleton-Pattern mit definiertem `filePath`
     */
    public static function getInstance(string $filePath = null): static {
        if (self::$instance === null) {
            $defaultPath = $filePath ?: __DIR__ . '/../config/config.json';
            self::$instance = new static($defaultPath);
        }
        return self::$instance;
    }

    /**
     * Lädt die Konfigurationsdatei und identifiziert den Typ
     */
    protected function loadConfig(): void {
        if (!file_exists($this->filePath)) {
            throw new Exception("Konfigurationsdatei nicht gefunden: {$this->filePath}");
        }

        $jsonContent = file_get_contents($this->filePath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Fehler beim Parsen der JSON-Konfiguration: " . json_last_error_msg());
        }

        $this->configType = $this->detectConfigType($data);
        $this->config = $this->configType->parse($data);
    }

    /**
     * Sucht automatisch alle Klassen im Namespace `ConfigToolkit\ConfigTypes` und registriert sie.
     */
    protected function loadAvailableConfigTypes(): void {
        if (!empty(self::$configTypeClasses)) {
            return; // Falls bereits geladen, nicht erneut scannen
        }

        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, 'ConfigToolkit\\ConfigTypes\\') && is_subclass_of($class, ConfigTypeAbstract::class)) {
                self::$configTypeClasses[] = $class;
            }
        }

        if (empty(self::$configTypeClasses)) {
            throw new Exception("Keine gültigen Konfigurationstypen gefunden.");
        }
    }

    /**
     * Erkennt den passenden Konfigurationstyp, indem alle registrierten Klassen geprüft werden.
     */
    protected function detectConfigType(array $data): ConfigTypeAbstract {
        foreach (self::$configTypeClasses as $class) {
            $reflection = new ReflectionClass($class);

            if (!$reflection->isAbstract() && $reflection->implementsInterface(ConfigTypeInterface::class)) {
                $instance = $reflection->newInstance();
                if ($instance->matches($data)) {
                    return $instance;
                }
            }
        }

        throw new Exception("Unbekannter Konfigurationstyp in Datei: {$this->filePath}");
    }

    /**
     * Gibt einen bestimmten Wert aus der geladenen Konfiguration zurück
     */
    public function get(string $section, string $key, $default = null) {
        return $this->config[$section][$key] ?? $default;
    }

    /**
     * Lädt die Konfigurationsdatei erneut
     */
    public function reload(): void {
        $this->loadConfig();
    }
}
