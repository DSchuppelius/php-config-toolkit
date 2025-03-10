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

class CrossPlatformExecutableConfigType extends ExecutableConfigType {
    public function matches(array $data): bool {
        $hasCrossPlatformEntry = false;

        foreach ($data as $section) {
            if (!is_array($section)) {
                continue;
            }

            foreach ($section as $key => $value) {
                $hasWindowsPath = isset($value['windowsPath']);
                $hasLinuxPath = isset($value['linuxPath']);
                $hasGenericPath = isset($value['path']);

                // Prüfen, ob es mindestens einen Cross-Platform-Eintrag gibt
                if ($hasWindowsPath && $hasLinuxPath) {
                    $hasCrossPlatformEntry = true;

                    // Falls `windowsPath` & `linuxPath` existieren, darf KEIN `path` existieren
                    if ($hasGenericPath) {
                        return false; // Fehlerhafte Konfiguration
                    }
                }

                // Falls KEIN `windowsPath` & `linuxPath` existiert, MUSS `path` existieren
                if (!$hasWindowsPath && !$hasLinuxPath && !$hasGenericPath) {
                    return false; // Fehlerhafte Konfiguration
                }
            }
        }

        return $hasCrossPlatformEntry; // True, wenn mindestens ein `windowsPath` & `linuxPath` existiert
    }


    /**
     * Gibt den richtigen Pfad für das ausführbare Programm basierend auf dem OS zurück
     */
    protected function getExecutablePath(array $executable): ?string {
        $path = $this->isWindows
            ? ($executable['windowsPath'] ?? $executable['path'] ?? null)
            : ($executable['linuxPath'] ?? $executable['path'] ?? null);

        return parent::findExecutablePath($path);
    }

    /**
     * Gibt die richtigen Argumente für das aktuelle OS zurück
     */
    protected function getArguments(array $executable): array {
        return $this->isWindows
            ? ($executable['windowsArguments'] ?? $executable['arguments'] ?? [])
            : ($executable['linuxArguments'] ?? $executable['arguments'] ?? []);
    }

    /**
     * Gibt die richtigen Debug-Argumente für das aktuelle OS zurück
     */
    protected function getDebugArguments(array $executable): array {
        return $this->isWindows
            ? ($executable['windowsDebugArguments'] ?? $executable['debugArguments'] ?? [])
            : ($executable['linuxDebugArguments'] ?? $executable['debugArguments'] ?? []);
    }
}