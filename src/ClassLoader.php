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

use ERRORToolkit\Exceptions\FileSystem\FolderNotFoundException;
use ERRORToolkit\Traits\ErrorLog;
use ReflectionClass;
use Exception;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Dynamischer Klassenlader für das Plugin-System.
 * Lädt automatisch alle Klassen aus einem Verzeichnis, die ein bestimmtes Interface implementieren.
 */
class ClassLoader {
    use ErrorLog;

    /** @var string Absoluter Pfad zum Verzeichnis mit den Klassen. */
    private string $directory;

    /** @var string Basis-Namespace für die Klassen. */
    private string $namespace;

    /** @var string Vollqualifizierter Name des erforderlichen Interfaces. */
    private string $interface;

    /** @var array<class-string> Liste der geladenen Klassennamen. */
    private array $classes = [];

    public function __construct(string $directory, string $namespace, string $interface, ?LoggerInterface $logger = null) {
        $this->directory = realpath($directory) ?: $directory;
        $this->namespace = $namespace;
        $this->interface = $interface;

        $this->initializeLogger($logger);
        $this->reloadClasses();
    }

    /**
     * Lädt alle Klassen aus dem konfigurierten Verzeichnis neu.
     * Löscht zuerst den Cache und scannt dann das Verzeichnis erneut.
     *
     * @throws FolderNotFoundException Wenn das Verzeichnis nicht existiert.
     */
    public function reloadClasses(): void {
        $this->logInfo("Lade Klassen aus Verzeichnis: $this->directory mit Namespace: $this->namespace");

        if (!is_dir($this->directory)) {
            $this->logError("Verzeichnis nicht gefunden: $this->directory");
            throw new FolderNotFoundException("Das Verzeichnis für Klassen konnte nicht aufgelöst werden: $this->directory");
        }

        $this->classes = [];
        $files = $this->getPhpFilesRecursive($this->directory);

        if (empty($files)) {
            $this->logWarning("Keine PHP-Dateien im Verzeichnis $this->directory gefunden.");
            return;
        }

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);
            $this->logDebug("Verarbeite Datei: $file => Klasse: $className");

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
                } elseif (!$reflectionClass->implementsInterface($this->interface)) {
                    $this->logWarning("Klasse implementiert nicht das Interface $this->interface: $className");
                    continue;
                }

                $this->classes[] = $className;
                $this->logDebug("Klasse erfolgreich geladen: $className");
            } catch (Exception $e) {
                $this->logError("Fehler beim Verarbeiten der Klasse $className: " . $e->getMessage());
            }
        }
    }

    /**
     * **NEU:** Rekursive Suche nach PHP-Dateien
     *
     * @param string $directory Das Verzeichnis, das rekursiv nach PHP-Dateien durchsucht wird.
     * @return array Liste der gefundenen PHP-Dateien
     */
    private function getPhpFilesRecursive(string $directory): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    /**
     * Ermittelt den vollqualifizierten Klassennamen aus einem Dateipfad.
     *
     * @param string $file Absoluter Pfad zur PHP-Datei.
     * @return string Vollqualifizierter Klassenname.
     */
    private function getClassNameFromFile(string $file): string {
        // Relativen Pfad zum Basispfad berechnen
        $relativePath = str_replace([$this->directory . DIRECTORY_SEPARATOR, '.php'], '', $file);
        // Standardisiere die Trennzeichen für den Namespace
        $relativePath = str_replace(['/', '\\'], '\\', $relativePath);

        return $this->namespace . '\\' . $relativePath;
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
