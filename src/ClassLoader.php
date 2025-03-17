<?php
/*
 * Created on   : Sun Mar 09 2025
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ClassLoader.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit;

use ERRORToolkit\Traits\ErrorLog;
use ReflectionClass;
use Exception;
use Psr\Log\LoggerInterface;

class ClassLoader {
    use ErrorLog;

    private string $directory;
    private string $namespace;
    private string $interface;

    private array $classes = [];

    public function __construct(string $directory, string $namespace, string $interface, ?LoggerInterface $logger = null) {
        $this->directory = realpath($directory) ?: $directory;
        $this->namespace = $namespace;
        $this->interface = $interface;

        $this->initializeLogger($logger);
        $this->reloadClasses();
    }

    public function reloadClasses(): void {
        $this->logInfo("Lade Klassen aus Verzeichnis: $this->directory mit Namespace: $this->namespace");

        if (!is_dir($this->directory)) {
            $this->logError("Verzeichnis nicht gefunden: $this->directory");
            throw new Exception("Das Verzeichnis für Klassen konnte nicht aufgelöst werden: $this->directory");
        }

        $this->classes = [];
        $files = glob($this->directory . '/*.php');

        if ($files === false) {
            $this->logError("Fehler beim Lesen des Verzeichnisses: $this->directory");
            throw new Exception("Konnte das Verzeichnis nicht lesen: $this->directory");
        }

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $className = $this->namespace . '\\' . pathinfo($file, PATHINFO_FILENAME);

            if (!class_exists($className)) {
                if (file_exists($file)) {
                    require_once $file;
                }

                if (!class_exists($className)) {
                    $this->logWarning("Klasse nicht gefunden oder nicht autoloaded: $className");
                    continue;
                }
            }

            try {
                $reflectionClass = new ReflectionClass($className);

                if (!$reflectionClass->isInstantiable()) {
                    $this->logWarning("Klasse ist nicht instanziierbar (z.B. abstrakt): $className");
                    continue;
                }

                if (!$reflectionClass->implementsInterface($this->interface)) {
                    $this->logWarning("Klasse implementiert nicht das Interface $this->interface: $className");
                    continue;
                }

                $this->classes[] = $className;
                $this->logInfo("Klasse erfolgreich geladen: $className");
            } catch (Exception $e) {
                $this->logError("Fehler beim Verarbeiten der Klasse $className: " . $e->getMessage());
            }
        }

        if (empty($this->classes)) {
            $this->logWarning("Keine passenden Klassen im Verzeichnis $this->directory gefunden.");
        }
    }

    /**
     * Gibt die geladenen Klassen zurück
     *
     * @return array Liste der geladenen Klassen
     */
    public function getClasses(): array {
        return $this->classes;
    }
}
