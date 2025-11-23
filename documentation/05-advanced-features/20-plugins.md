# Plugin System

The plugin system allows you to extend PdoDb functionality with custom macros, scopes, event listeners, and more. This enables community-driven extensions and makes it easy to add reusable functionality.

## Overview

Plugins provide a clean way to package and distribute extensions to PdoDb. A plugin can:
- Register QueryBuilder macros for reusable query methods
- Register global scopes that are automatically applied to queries
- Register event listeners for monitoring and logging
- Configure any plugin-specific settings

## Creating a Plugin

### Using PluginInterface

All plugins must implement the `PluginInterface`:

```php
use tommyknocker\pdodb\plugin\PluginInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

class MyCustomPlugin implements PluginInterface
{
    public function register(PdoDb $db): void
    {
        // Register macros, scopes, event listeners, etc.
        QueryBuilder::macro('customMethod', function (QueryBuilder $query) {
            // Your custom logic
            return $query;
        });
    }

    public function getName(): string
    {
        return 'my-custom-plugin';
    }
}
```

### Using AbstractPlugin

For convenience, you can extend `AbstractPlugin` which provides a default implementation:

```php
use tommyknocker\pdodb\plugin\AbstractPlugin;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;

class MyCustomPlugin extends AbstractPlugin
{
    public function register(PdoDb $db): void
    {
        QueryBuilder::macro('customMethod', function (QueryBuilder $query) {
            return $query;
        });
    }

    // getName() defaults to class name, override if needed
    public function getName(): string
    {
        return 'my-custom-plugin';
    }
}
```

## Registering Plugins

Register a plugin with your PdoDb instance:

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', $config);

// Register plugin
$db->registerPlugin(new MyCustomPlugin());

// Plugin's register() method is called immediately
// Now you can use the plugin's features
```

## Plugin Features

### Registering Macros

Plugins can register QueryBuilder macros:

```php
class ProductMacrosPlugin extends AbstractPlugin
{
    public function register(PdoDb $db): void
    {
        QueryBuilder::macro('active', function (QueryBuilder $query) {
            return $query->where('status', 'active');
        });

        QueryBuilder::macro('wherePrice', function (QueryBuilder $query, string $operator, float $price) {
            return $query->where('price', $price, $operator);
        });

        QueryBuilder::macro('recent', function (QueryBuilder $query, int $days = 7) {
            $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            return $query->where('created_at', $date, '>=');
        });
    }

    public function getName(): string
    {
        return 'product-macros';
    }
}

$db->registerPlugin(new ProductMacrosPlugin());

// Use macros
$products = $db->find()
    ->table('products')
    ->active()
    ->wherePrice('>', 100.00)
    ->recent(30)
    ->get();
```

### Registering Global Scopes

Plugins can register global scopes that are automatically applied to all queries:

```php
class SoftDeletePlugin extends AbstractPlugin
{
    public function register(PdoDb $db): void
    {
        // Automatically filter out deleted records
        $db->addScope('notDeleted', function (QueryBuilder $query) {
            return $query->whereNull('deleted_at');
        });
    }

    public function getName(): string
    {
        return 'soft-delete';
    }
}

$db->registerPlugin(new SoftDeletePlugin());

// All queries automatically exclude deleted records
$users = $db->find()->table('users')->get();
```

### Registering Event Listeners

Plugins can listen to database events:

```php
use tommyknocker\pdodb\events\QueryExecutedEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class QueryMonitorPlugin extends AbstractPlugin
{
    public function register(PdoDb $db): void
    {
        $dispatcher = $db->getEventDispatcher();
        if ($dispatcher !== null) {
            $dispatcher->addListener(
                QueryExecutedEvent::class,
                function (QueryExecutedEvent $event) {
                    // Log slow queries
                    if ($event->getExecutionTime() > 0.1) {
                        error_log("Slow query: " . $event->getSql());
                    }
                }
            );
        }
    }

    public function getName(): string
    {
        return 'query-monitor';
    }
}

// Set up event dispatcher
$db->setEventDispatcher(new EventDispatcher());
$db->registerPlugin(new QueryMonitorPlugin());
```

### Combining Multiple Features

A plugin can combine macros, scopes, and event listeners:

```php
class ECommercePlugin extends AbstractPlugin
{
    public function register(PdoDb $db): void
    {
        // Macros
        QueryBuilder::macro('featured', function (QueryBuilder $query) {
            return $query->where('status', 'featured');
        });

        QueryBuilder::macro('inCategory', function (QueryBuilder $query, int $categoryId) {
            return $query->where('category_id', $categoryId);
        });

        // Scopes
        $db->addScope('available', function (QueryBuilder $query) {
            return $query->where('status', 'active')
                ->whereNull('deleted_at');
        });

        // Event listeners
        $dispatcher = $db->getEventDispatcher();
        if ($dispatcher !== null) {
            $dispatcher->addListener(
                QueryExecutedEvent::class,
                function (QueryExecutedEvent $event) {
                    // Custom logic
                }
            );
        }
    }

    public function getName(): string
    {
        return 'ecommerce';
    }
}
```

## Plugin Management

### Checking if a Plugin is Registered

```php
if ($db->hasPlugin('my-custom-plugin')) {
    // Plugin is registered
}
```

### Getting a Plugin Instance

```php
$plugin = $db->getPlugin('my-custom-plugin');
if ($plugin !== null) {
    echo "Plugin: " . $plugin->getName();
}
```

### Getting All Registered Plugins

```php
$plugins = $db->getPlugins();
foreach ($plugins as $name => $plugin) {
    echo "Registered: $name\n";
}
```

### Unregistering a Plugin

```php
$db->unregisterPlugin('my-custom-plugin');
```

**Note**: Unregistering a plugin removes it from the registry but does not undo any changes it made (macros, scopes, and event listeners remain registered).

## Best Practices

### Plugin Names

- Use descriptive, unique names for your plugins
- Use kebab-case (e.g., `my-custom-plugin`)
- Avoid generic names that might conflict

### Plugin Structure

- Keep plugins focused on a single feature or domain
- Use dependency injection when needed
- Make plugins configurable if needed

### Error Handling

- Handle missing dependencies gracefully (e.g., event dispatcher)
- Validate inputs before registering macros or scopes
- Document any requirements or dependencies

### Testing

- Test plugins in isolation
- Test plugin interactions with PdoDb
- Test plugin registration and unregistration

## Example: Complete Plugin

```php
use tommyknocker\pdodb\plugin\AbstractPlugin;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryBuilder;
use tommyknocker\pdodb\events\QueryExecutedEvent;

class CompletePlugin extends AbstractPlugin
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function register(PdoDb $db): void
    {
        // Register macros
        QueryBuilder::macro('active', function (QueryBuilder $query) {
            return $query->where('status', 'active');
        });

        // Register scopes
        if ($this->config['enableSoftDelete'] ?? false) {
            $db->addScope('notDeleted', function (QueryBuilder $query) {
                return $query->whereNull('deleted_at');
            });
        }

        // Register event listeners
        $dispatcher = $db->getEventDispatcher();
        if ($dispatcher !== null && ($this->config['enableLogging'] ?? false)) {
            $dispatcher->addListener(
                QueryExecutedEvent::class,
                function (QueryExecutedEvent $event) {
                    // Log queries
                }
            );
        }
    }

    public function getName(): string
    {
        return 'complete-plugin';
    }
}

// Usage
$plugin = new CompletePlugin([
    'enableSoftDelete' => true,
    'enableLogging' => true,
]);

$db->registerPlugin($plugin);
```

## See Also

- [Query Builder Macros](18-query-macros.md) - More about macros
- [Query Scopes](19-query-scopes.md) - More about scopes
- [Examples](../../examples/10-extensibility/02-plugins.php) - Working examples
