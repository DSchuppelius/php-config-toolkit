# PHP Config Toolkit

[![PHP Version](https://img.shields.io/badge/PHP-8.0%20--%208.5-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Ein JSON-basiertes Konfigurationsverwaltungs-Toolkit mit Plugin-basierter Architektur für verschiedene Konfigurationsdateiformate.

## Features

- **Singleton-Pattern**: Zentraler `ConfigLoader` für konsistente Konfigurationsverwaltung
- **Plugin-System**: Automatische Erkennung und Laden von ConfigType-Klassen
- **CommandBuilder**: Baut Shell-Befehle aus Executable-Konfigurationen mit Platzhalter-Ersetzung
- **Typ-Casting**: Unterstützt `float`, `int`, `timestamp`, `date`, `datetime`, `bool`, `array`, `json` und `string`
- **Validierung**: Automatische Validierung gegen den passenden ConfigType
- **Mehrere Konfigurationsformate**: Strukturierte Configs, Executable-Definitionen, Postman-Collections u.v.m.

## Installation

```bash
composer require dschuppelius/php-config-toolkit
```

## Anforderungen

- PHP 8.0 - 8.5
- [dschuppelius/php-error-toolkit](https://github.com/DSchuppelius/php-error-toolkit) ^1.2

## Verwendung

### Konfiguration laden

```php
use ConfigToolkit\ConfigLoader;

$loader = ConfigLoader::getInstance();
$loader->loadConfigFile('config.json');

// Wert abrufen
$value = $loader->get('Section', 'key');

// Ganze Sektion abrufen
$section = $loader->get('Database');
```

### CommandBuilder für Shell-Befehle

Der `CommandBuilder` baut Shell-Befehle aus der Executable-Konfiguration und ersetzt Platzhalter automatisch:

```php
use ConfigToolkit\ConfigLoader;
use ConfigToolkit\CommandBuilder;

// Config laden
$loader = ConfigLoader::getInstance();
$loader->loadConfigFile('executables.json');

// CommandBuilder initialisieren
$builder = new CommandBuilder($loader);

// Shell-Befehl bauen
$command = $builder->build('pdftotext', [
    '[PDF-FILE]' => '/path/to/document.pdf',
    '[TEXT-FILE]' => '/path/to/output.txt'
]);
// Ergebnis: "pdftotext -layout -enc UTF-8 '/path/to/document.pdf' '/path/to/output.txt'"

// Java-Befehl bauen
$command = $builder->buildJava('pdfbox', [
    '[INPUT]' => '/path/to/input.pdf',
    '[OUTPUT]' => '/path/to/output.txt'
]);
// Ergebnis: "java -jar '/path/to/pdfbox.jar' export:text -i '/path/to/input.pdf' -o '/path/to/output.txt'"

// Verfügbarkeit prüfen
if ($builder->isAvailable('pdftotext')) {
    // Tool ist konfiguriert und verfügbar
}

// Pfad abrufen
$path = $builder->getPath('tesseract');

// Convenience: Direkt aus Config-Dateien
$builder = CommandBuilder::fromConfigFiles(['config/executables.json']);
```

### Konfiguration validieren

```php
use ConfigToolkit\ConfigValidator;

$errors = ConfigValidator::validate('config.json');

if (empty($errors)) {
    echo "Konfiguration ist gültig!";
} else {
    foreach ($errors as $error) {
        echo "Fehler: $error\n";
    }
}
```

### ConfigAbstract - Projekt-Konfigurationsklassen

Die abstrakte `ConfigAbstract`-Klasse ermöglicht es, eigene Projekt-Konfigurationsklassen mit minimalem Aufwand zu erstellen. Sie stellt Singleton-Pattern, ConfigLoader/CommandBuilder-Integration und Standard-Funktionalität bereit.

**Eigene Config-Klasse erstellen:**

```php
use ConfigToolkit\Contracts\Abstracts\ConfigAbstract;

class Config extends ConfigAbstract {
    protected static function getDefaultConfigDir(): string {
        return __DIR__ . '/../config';
    }

    protected static function getProjectName(): string {
        return 'my-project';
    }
}

// Verwendung
$config = Config::getInstance();

// Konfigurationswerte abrufen
$value = $config->getConfig('Database', 'host', 'localhost');
$section = $config->getSection('Logging');

// Shell-Befehle bauen
$command = $config->buildCommand('pdftotext', [
    '[PDF-FILE]' => '/path/to/file.pdf',
    '[TEXT-FILE]' => '/tmp/output.txt'
]);

// Java-Befehle bauen
$javaCmd = $config->buildJavaCommand('pdfbox', [
    '[INPUT]' => '/path/to/input.pdf'
]);

// Verfügbarkeit prüfen
if ($config->isExecutableAvailable('tesseract')) {
    // OCR ist verfügbar
}

// Debug-Modus
$config->setDebug(true);
$isDebug = $config->isDebugEnabled();

// Logging
$level = $config->getLogLevel(); // 'debug', 'info', etc.
$path = $config->getLogPath();

// Version abrufen (aus composer.json oder VERSION-Datei)
$version = $config->getVersion();
```

**Verfügbare Methoden:**

| Methode | Beschreibung |
| ------- | ------------ |
| `getInstance()` | Gibt die Singleton-Instanz zurück |
| `resetInstance()` | Setzt die Singleton-Instanz zurück (für Tests) |
| `getConfigLoader()` | Gibt den internen ConfigLoader zurück |
| `getCommandBuilder()` | Gibt den internen CommandBuilder zurück |
| `getConfig($section, $key, $default)` | Holt einen Konfigurationswert |
| `getSection($section)` | Gibt eine komplette Sektion zurück |
| `buildCommand($name, $replacements, $extra)` | Baut einen Shell-Befehl |
| `buildJavaCommand($name, $replacements, $extra)` | Baut einen Java-Befehl |
| `isExecutableAvailable($name)` | Prüft ob ein Executable verfügbar ist |
| `getExecutablePath($name)` | Gibt den Pfad eines Executables zurück |
| `getLogLevel()` | Gibt den konfigurierten Log-Level zurück |
| `getLogPath()` | Gibt den Log-Pfad zurück |
| `getLogType()` | Gibt den Log-Typ zurück |
| `isDebugEnabled()` | Prüft ob Debug-Modus aktiv ist |
| `setDebug($bool)` | Aktiviert/Deaktiviert Debug-Modus |
| `getVersion()` | Gibt die Projekt-Version zurück |

---

## Beispiel-Konfigurationsdateien

### Strukturierte Konfiguration (StructuredConfigType)

Für allgemeine Anwendungseinstellungen mit key/value-Paaren:

```json
{
  "Database": [
    {"key": "host", "value": "localhost", "type": "text", "enabled": true},
    {"key": "port", "value": "3306", "type": "int", "enabled": true},
    {"key": "username", "value": "app_user", "type": "text", "enabled": true},
    {"key": "debug", "value": "true", "type": "bool", "enabled": true}
  ],
  "Cache": [
    {"key": "driver", "value": "redis", "type": "text", "enabled": true},
    {"key": "ttl", "value": "3600", "type": "int", "enabled": true},
    {"key": "prefix", "value": "myapp_", "type": "text", "enabled": false}
  ],
  "Logging": [
    {"key": "level", "value": "debug", "type": "text", "enabled": true},
    {"key": "path", "value": "/var/log/app.log", "type": "text", "enabled": true}
  ]
}
```

### Executable-Konfiguration (ExecutableConfigType)

Für Shell-Tools und externe Programme:

```json
{
  "shellExecutables": {
    "pdftotext": {
      "path": "pdftotext",
      "required": true,
      "description": "PDF to Text Converter",
      "package": "poppler-utils",
      "installer": "apt",
      "arguments": ["-layout", "-enc", "UTF-8", "[PDF-FILE]", "[TEXT-FILE]"]
    },
    "pdfinfo": {
      "path": "pdfinfo",
      "required": true,
      "description": "PDF Metadata Extractor",
      "package": "poppler-utils",
      "arguments": ["[PDF-FILE]"]
    },
    "tesseract": {
      "path": "tesseract",
      "required": false,
      "description": "OCR Engine",
      "package": "tesseract-ocr",
      "arguments": ["[INPUT]", "[OUTPUT]", "-l", "[LANG]", "--psm", "[PSM]"]
    },
    "convert": {
      "path": "convert",
      "required": false,
      "description": "ImageMagick Converter",
      "package": "imagemagick",
      "arguments": ["[INPUT]", "[OUTPUT]"]
    }
  },
  "pythonExecutables": {
    "pdf2docx": {
      "path": "pdf2docx",
      "required": false,
      "description": "PDF zu DOCX Konverter",
      "package": "pdf2docx",
      "installer": "pipx",
      "arguments": ["convert", "[INPUT]", "[OUTPUT]"]
    },
    "yt-dlp": {
      "path": "yt-dlp",
      "required": false,
      "description": "YouTube Video Downloader",
      "package": "yt-dlp",
      "installer": "pipx",
      "arguments": ["[URL]", "-o", "[OUTPUT]"]
    }
  },
  "nodeExecutables": {
    "prettier": {
      "path": "prettier",
      "required": false,
      "description": "Code Formatter",
      "package": "prettier",
      "installer": "npm",
      "arguments": ["--write", "[FILE]"]
    }
  },
  "javaExecutables": {
    "pdfbox": {
      "path": "/usr/local/lib/pdfbox-app.jar",
      "required": false,
      "description": "Apache PDFBox Tool",
      "arguments": ["export:text", "-i", "[INPUT]", "-o", "[OUTPUT]"]
    }
  }
}
```

#### Unterstützte Installer

Das `installer`-Feld gibt an, mit welchem Paketmanager das Tool installiert werden soll. **Wenn nicht angegeben, wird `apt` als Standard verwendet.**

| Installer | Beschreibung |
| --------- | ------------ |
| `apt`, `apt-get` | Debian/Ubuntu Paketmanager (Standard) |
| `dnf`, `yum` | Fedora/RHEL/CentOS Paketmanager |
| `pacman` | Arch Linux Paketmanager |
| `zypper` | openSUSE Paketmanager |
| `brew` | macOS/Linux Homebrew |
| `pip`, `pip3` | Python Pip |
| `pipx` | Python Pipx (isolierte Umgebungen) |
| `npm`, `yarn` | Node.js Paketmanager |
| `composer` | PHP Composer |
| `gem` | Ruby Gems |
| `cargo` | Rust Cargo |
| `go` | Go Modules |
| `snap` | Snap Packages |
| `flatpak` | Flatpak Packages |
| `winget` | Windows Package Manager |
| `choco` | Windows Chocolatey |
| `scoop` | Windows Scoop |
| `manual` | Manuelle Installation (keine automatische Installation) |

### Cross-Platform Executable-Konfiguration (CrossPlatformExecutableConfigType)

Für plattformspezifische Pfade (Windows/Linux):

```json
{
  "shellExecutables": {
    "pdf-decrypt": {
      "linuxPath": "qpdf",
      "windowsPath": "C:\\Program Files\\qpdf\\bin\\qpdf.exe",
      "required": false,
      "description": "PDF Decryption Tool",
      "package": "qpdf",
      "linuxArguments": ["--password=[PASS]", "--decrypt", "[INPUT]", "[OUTPUT]"],
      "windowsArguments": ["--password=[PASS]", "--decrypt", "[INPUT]", "[OUTPUT]"]
    },
    "tiff2pdf": {
      "linuxPath": "tiff2pdf",
      "windowsPath": "tiff2pdf.exe",
      "required": false,
      "description": "TIFF to PDF Converter",
      "linuxArguments": ["-o", "[OUTPUT]", "[INPUT]"],
      "windowsArguments": ["-o", "[OUTPUT]", "[INPUT]"]
    }
  }
}
```

### Erweiterte Strukturierte Konfiguration (AdvancedStructuredConfigType)

Für komplexere Strukturen mit verschachtelten Werten:

```json
{
  "PDFSettings": [
    {"key": "tesseract_lang", "value": "deu+eng", "type": "text", "enabled": true},
    {"key": "tesseract_psm", "value": "3", "type": "int", "enabled": true},
    {"key": "pdftoppm_dpi", "value": "300", "type": "int", "enabled": true}
  ],
  "Debugging": [
    {"key": "debug", "value": "false", "type": "bool", "enabled": true},
    {"key": "verbose", "value": "false", "type": "bool", "enabled": true}
  ],
  "Paths": [
    {"key": "temp_dir", "value": "/tmp/app", "type": "text", "enabled": true},
    {"key": "output_dir", "value": "/var/output", "type": "text", "enabled": true}
  ]
}
```

---

## Unterstützte ConfigTypes

| ConfigType | Beschreibung | Erkennungsmerkmal |
| ---------- | ------------ | ----------------- |
| `StructuredConfigType` | Standard key/value/enabled-Struktur | Arrays mit `key`, `value`, `enabled` |
| `AdvancedStructuredConfigType` | Erweiterte Struktur mit flachen Arrays | Verschachtelte Arrays ohne Executable-Keys |
| `ExecutableConfigType` | Executable-Pfade mit Argumenten | `path` vorhanden, kein `windowsPath`/`linuxPath` |
| `CrossPlatformExecutableConfigType` | Plattformspezifische Executables | `windowsPath` UND `linuxPath` vorhanden |
| `PostmanConfigType` | Postman Collection-Exporte | `info` und `item` Keys |

## Platzhalter in Executable-Konfigurationen

Platzhalter werden in eckigen Klammern definiert und beim Aufruf ersetzt:

| Platzhalter | Beschreibung |
| ----------- | ------------ |
| `[INPUT]` | Eingabedatei |
| `[OUTPUT]` | Ausgabedatei |
| `[PDF-FILE]` | PDF-Dateipfad |
| `[TEXT-FILE]` | Textdatei-Ausgabe |
| `[LANG]` | Sprache (z.B. für OCR) |
| `[PSM]` | Page Segmentation Mode |
| `[PASS]` | Passwort |
| `[DPI]` | Auflösung in DPI |

Sie können beliebige eigene Platzhalter definieren - der CommandBuilder ersetzt alle übergebenen Key-Value-Paare.

## Eigene ConfigTypes erstellen

1. Erstelle eine neue Klasse in `src/ConfigTypes/` die `ConfigTypeAbstract` erweitert
2. Implementiere die Methoden `matches()`, `parse()` und `validate()`
3. Der ClassLoader erkennt und registriert die Klasse automatisch

```php
use ConfigToolkit\Contracts\Abstracts\ConfigTypeAbstract;

class MyConfigType extends ConfigTypeAbstract {
    public static function matches(array $data): bool {
        // Prüfe ob diese Klasse die Datenstruktur verarbeiten kann
        return isset($data['mySpecialKey']);
    }

    public function parse(array $data): array {
        // Daten in nutzbares Array umwandeln
        return $data;
    }

    public function validate(array $data): array {
        // Fehler-Array zurückgeben (leer wenn valide)
        return [];
    }
}
```

## Tests ausführen

```bash
composer test
# oder
vendor/bin/phpunit
```

## Lizenz

MIT License - siehe [LICENSE](LICENSE) für Details.

## Autor

Daniel Jörg Schuppelius - [schuppelius.org](https://schuppelius.org)
