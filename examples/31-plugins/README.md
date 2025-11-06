# Plugin System Examples

This directory contains examples demonstrating the plugin system for extending PdoDb functionality.

## Files

- **01-plugin-examples.php** - Comprehensive examples of plugin system usage

## Running Examples

```bash
# SQLite (default)
php examples/31-plugins/01-plugin-examples.php

# MySQL
PDODB_DRIVER=mysql php examples/31-plugins/01-plugin-examples.php

# PostgreSQL
PDODB_DRIVER=pgsql php examples/31-plugins/01-plugin-examples.php

# MariaDB
PDODB_DRIVER=mariadb php examples/31-plugins/01-plugin-examples.php
```

## What's Demonstrated

### 1. Simple Plugin with Macros
Shows how to create a plugin that registers QueryBuilder macros for reusable query logic.

### 2. Plugin with Global Scopes
Demonstrates registering global scopes that are automatically applied to all queries.

### 3. Plugin with Event Listeners
Shows how to listen to database events (like QueryExecutedEvent) for monitoring and logging.

### 4. Complex Plugin with Multiple Features
Example of a plugin that combines macros, scopes, and event listeners for a complete feature set.

### 5. Plugin Management
Demonstrates plugin registration, retrieval, and management methods.

## Key Concepts

- **PluginInterface**: Interface that all plugins must implement
- **AbstractPlugin**: Base class for convenience (provides default getName() implementation)
- **registerPlugin()**: Method to register a plugin with PdoDb instance
- **hasPlugin()**: Check if a plugin is registered
- **getPlugin()**: Retrieve a plugin by name
- **getPlugins()**: Get all registered plugins
- **unregisterPlugin()**: Remove a plugin from registry (does not undo changes)

## Plugin Features

Plugins can:
- Register QueryBuilder macros via `QueryBuilder::macro()`
- Register global scopes via `$db->addScope()`
- Register event listeners via `$db->getEventDispatcher()->addListener()`
- Configure any plugin-specific settings

## See Also

- [Documentation: Plugin System](documentation/05-advanced-features/plugins.md)
- [Query Builder Macros Examples](../29-macros/)
- [Query Scopes Examples](../28-query-scopes/)

