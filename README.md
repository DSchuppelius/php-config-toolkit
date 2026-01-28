# PHP Config Toolkit

[![PHP Version](https://img.shields.io/badge/PHP-8.0%20--%208.5-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Ein JSON-basiertes Konfigurationsverwaltungs-Toolkit mit Plugin-basierter Architektur für verschiedene Konfigurationsdateiformate.

## Features

- **Singleton-Pattern**: Zentraler `ConfigLoader` für konsistente Konfigurationsverwaltung
- **Plugin-System**: Automatische Erkennung und Laden von ConfigType-Klassen
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

### Beispiel-Konfigurationsdatei

```json
{
  "Database": [
    {"key": "host", "value": "localhost", "type": "text", "enabled": true},
    {"key": "port", "value": "3306", "type": "int", "enabled": true},
    {"key": "debug", "value": "true", "type": "bool", "enabled": true}
  ],
  "Cache": [
    {"key": "ttl", "value": "3600", "type": "int", "enabled": true}
  ]
}
```

### Executable-Konfiguration mit Package-Manager

Das Toolkit unterstützt die Definition von Executables mit verschiedenen Paketmanagern über das `installer`-Feld:

```json
{
  "pythonTools": {
    "pdf2docx": {
      "path": "pdf2docx",
      "required": false,
      "description": "PDF zu DOCX Konverter",
      "package": "pdf2docx",
      "installer": "pipx"
    },
    "black": {
      "path": "black",
      "required": false,
      "package": "black",
      "installer": "pip"
    }
  },
  "systemTools": {
    "imagemagick": {
      "path": "convert",
      "required": false,
      "package": "imagemagick",
      "installer": "apt"
    }
  },
  "nodeTools": {
    "prettier": {
      "path": "prettier",
      "required": false,
      "package": "prettier",
      "installer": "npm"
    }
  }
}
```

#### Unterstützte Installer

| Installer | Beschreibung |
|-----------|--------------|
| `apt`, `apt-get` | Debian/Ubuntu Paketmanager |
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
```

## Unterstützte ConfigTypes

| ConfigType | Beschreibung |
|------------|--------------|
| `StructuredConfigType` | Standard key/value/enabled-Struktur (Fallback) |
| `AdvancedStructuredConfigType` | Erweiterte Struktur mit flachen Arrays |
| `ExecutableConfigType` | Executable-Pfade mit Argumenten |
| `CrossPlatformExecutableConfigType` | Plattformspezifische Executables (Windows/Linux) |
| `PostmanConfigType` | Postman Collection-Exporte |

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
