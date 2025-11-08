# Extensibility Examples

Examples demonstrating how to extend PdoDb with custom functionality: macros, plugins, and events.

## Examples

### 01-macros.php
Query Builder macros: custom query methods for extending QueryBuilder.

**Topics covered:**
- Register macros - Custom query methods
- Macro with arguments - Parameterized macros
- Macro chaining - Chain macros with QueryBuilder methods
- Check macro existence - Verify macro registration

### 02-plugins.php
Plugin system: extend PdoDb with custom plugins.

**Topics covered:**
- Simple plugin - Plugin with macros
- Plugin with scopes - Global scopes via plugin
- Plugin with events - Event listeners via plugin
- Complex plugin - Combining macros, scopes, and events
- Plugin management - Register, check, get, unregister plugins

### 03-events.php
Event dispatcher: PSR-14 event-driven architecture.

**Topics covered:**
- Event listeners - Subscribe to database events
- Event dispatching - Trigger events on database operations
- Event filtering - Filter events based on conditions
- Custom events - Create and dispatch custom events

## Usage

```bash
# Run examples
php 01-macros.php
php 02-plugins.php
php 03-events.php
```

## Extensibility Features

- **Macros** - Add custom methods to QueryBuilder
- **Plugins** - Package macros, scopes, and events together
- **Events** - Event-driven architecture for monitoring and middleware
- **Scopes** - Reusable query logic (see [ActiveRecord Scopes](../09-active-record/03-scopes.php))

## Related Examples

- [ActiveRecord](../09-active-record/) - Object-based database operations
- [Query Scopes](../09-active-record/03-scopes.php) - Reusable query logic
