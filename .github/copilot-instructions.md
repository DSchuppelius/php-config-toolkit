# PHP Config Toolkit - AI Assistant Instructions

## Architecture Overview

This is a **JSON-based configuration management toolkit** built with a **plugin-based architecture** for handling different configuration file formats.

### Core Components

- **ConfigLoader** (`src/ConfigLoader.php`): Singleton that dynamically loads and parses JSON config files using type-specific handlers
- **ConfigValidator** (`src/ConfigValidator.php`): Validates configs by auto-detecting the appropriate ConfigType class
- **ClassLoader** (`src/ClassLoader.php`): Dynamically discovers and loads ConfigType classes from `src/ConfigTypes/`
- **ConfigType System**: Plugin architecture where each config format has its own handler extending `ConfigTypeAbstract`

### Key Architectural Patterns

**Plugin Detection Pattern**: Config types use static `matches()` methods to identify if they can handle a specific JSON structure:
```php
public static function matches(array $data): bool {
    // Check if data structure matches this config type
}
```

**Type Casting System**: All config types inherit value casting from `ConfigTypeAbstract::castValue()` supporting `float`, `int`, `timestamp`, `date`, `datetime`, `bool`, and default `string` types.

**Structured Config Format**: The primary config format uses sections with key-value-enabled objects:
```json
{
  "Section": [
    {"key": "setting", "value": "data", "type": "text", "enabled": true}
  ]
}
```

## Development Workflows

### Running Tests
```bash
composer test
# or
vendor/bin/phpunit
```

### Adding New Config Types
1. Create class in `src/ConfigTypes/` extending `ConfigTypeAbstract`
2. Implement `matches()`, `parse()`, and `validate()` methods
3. ClassLoader automatically discovers and registers it
4. Add test configs in `tests/test-configs/` and corresponding tests

### Testing Pattern
- Test configs in `tests/test-configs/` with naming convention: `{type}_config.json`
- Tests use `ConfigLoader::getInstance()` singleton pattern
- Each config type should have dedicated test methods in `ConfigLoaderTest.php`

## Project-Specific Conventions

### German Code Comments
All docblocks and comments are in German - maintain this convention:
```php
/**
 * Validiert eine JSON-Konfigurationsdatei anhand der passenden ConfigTypeAbstract-Klasse.
 */
```

### Error Handling
- Use `ERRORToolkit\Traits\ErrorLog` trait for logging
- Throw `Exception` for critical errors (file not found, invalid JSON)
- Return error arrays from `validate()` methods, not exceptions

### File Structure Rules
- All source files use strict types: `declare(strict_types=1);`
- PSR-4 autoloading with `ConfigToolkit\` namespace
- ConfigType classes must be in `src/ConfigTypes/` to be auto-discovered
- Interface contracts in `src/Contracts/` (Abstracts and Interfaces subdirectories)

### Config Type Hierarchy
When creating new config types, check existing ones first to avoid conflicts:
- `PostmanConfigType` - Postman collection exports
- `AdvancedStructuredConfigType` - Complex nested structures  
- `StructuredConfigType` - Standard key-value sections (fallback)
- `ExecutableConfigType` & `CrossPlatformExecutableConfigType` - Executable definitions

### Integration Notes
- Depends on `dschuppelius/php-error-toolkit` for error handling traits
- Uses PSR-3 logging interfaces
- Designed as a library (type: "library" in composer.json)
- Minimum PHP 8.0 required for match expressions and union types

When extending this toolkit, prioritize the plugin pattern - create new ConfigType classes rather than modifying core components.