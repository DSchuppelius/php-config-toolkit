<?php
/*
 * Created on   : Wed Mar 09 2025
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CrossPlatformExecutableConfigType.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace ConfigToolkit\ConfigTypes;

use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;
use Exception;

class CrossPlatformExecutableConfigType extends ConfigTypeAbstract {

    private string $osFamily;

    public function __construct() {
        $this->osFamily = PHP_OS_FAMILY; // Windows oder Linux
    }

    /**
     * Parsen der JSON-Konfiguration für ausführbare Programme
     */
    public function parse(array $data): array {
        $parsed = [];

        foreach ($data as $category => $executables) {
            foreach ($executables as $name => $executable) {
                $executablePath = $this->getExecutablePath($executable);
                $arguments = $this->getArguments($executable);
                $debugArguments = $this->getDebugArguments($executable);

                if ($executablePath === null) {
                    throw new Exception("Kein gültiger Pfad für ausführbare Datei: '$name' in Kategorie '$category'");
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
     * Prüft, ob diese Konfiguration zu einem ausführbaren Programm gehört
     */
    public function matches(array $data): bool {
        foreach ($data as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach ($section as $key => $value) {
                if (isset($value['path']) || (isset($value['windowsPath']) && isset($value['linuxPath']))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Validiert, ob die Konfiguration gültig ist
     */
    public function validate(array $data): array {
        $errors = [];

        foreach ($data as $category => $executables) {
            foreach ($executables as $name => $executable) {
                if (!isset($executable['path']) && !isset($executable['windowsPath']) && !isset($executable['linuxPath'])) {
                    $errors[] = "Kein gültiger Pfad für '$name' in '$category'. 'path' oder 'windowsPath' und 'linuxPath' erforderlich.";
                }
                if (!isset($executable['required']) || !is_bool($executable['required'])) {
                    $errors[] = "Fehlender oder ungültiger 'required' für '$name' in '$category'.";
                }
                if (!$this->hasValidArguments($executable)) {
                    $errors[] = "Fehlende oder ungültige Argumente für '$name' in '$category'.";
                }
            }
        }
        return $errors;
    }

    /**
     * Gibt den richtigen Pfad basierend auf dem OS zurück
     */
    private function getExecutablePath(array $executable): ?string {
        if ($this->osFamily === 'Windows') {
            return $executable['windowsPath'] ?? $executable['path'] ?? null;
        }
        return $executable['linuxPath'] ?? $executable['path'] ?? null;
    }

    /**
     * Gibt die richtigen Argumente für das OS zurück
     */
    private function getArguments(array $executable): array {
        if ($this->osFamily === 'Windows') {
            return $executable['windowsArguments'] ?? $executable['arguments'] ?? [];
        }
        return $executable['linuxArguments'] ?? $executable['arguments'] ?? [];
    }

    /**
     * Gibt die richtigen Debug-Argumente für das OS zurück
     */
    private function getDebugArguments(array $executable): array {
        if ($this->osFamily === 'Windows') {
            return $executable['windowsDebugArguments'] ?? $executable['debugArguments'] ?? [];
        }
        return $executable['linuxDebugArguments'] ?? $executable['debugArguments'] ?? [];
    }

    /**
     * Prüft, ob mindestens eine Argumentliste existiert
     */
    private function hasValidArguments(array $executable): bool {
        return isset($executable['arguments']) ||
            isset($executable['windowsArguments']) ||
            isset($executable['linuxArguments']);
    }
}
