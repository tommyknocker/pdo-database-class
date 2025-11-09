<?php
/**
 * Example: Plugin System.
 *
 * Demonstrates how to create and use plugins to extend PdoDb functionality.
 *
 * Usage:
 *   php examples/31-plugins/01-plugin-examples.php
 *   PDODB_DRIVER=mysql php examples/31-plugins/01-plugin-examples.php
 *   PDODB_DRIVER=pgsql php examples/31-plugins/01-plugin-examples.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\plugin\AbstractPlugin;
use tommyknocker\pdodb\query\QueryBuilder;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Plugin System Example (on $driver) ===\n\n";

// Create table
$db->rawQuery('DROP TABLE IF EXISTS products');
$driver = getCurrentDriver($db);

if ($driver === 'sqlite') {
    $db->rawQuery('
        CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price REAL NOT NULL,
            status TEXT DEFAULT \'active\',
            category_id INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT NULL
        )
    ');
} elseif ($driver === 'pgsql') {
    $db->rawQuery('
        CREATE TABLE products (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            status VARCHAR(50) DEFAULT \'active\',
            category_id INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL
        )
    ');
} elseif ($driver === 'sqlsrv') {
    $db->rawQuery('
        CREATE TABLE products (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            status NVARCHAR(50) DEFAULT \'active\',
            category_id INT,
            created_at DATETIME DEFAULT GETDATE(),
            deleted_at DATETIME NULL
        )
    ');
} else {
    // MySQL/MariaDB
    $db->rawQuery('
        CREATE TABLE products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            status VARCHAR(50) DEFAULT \'active\',
            category_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL
        )
    ');
}

// Example 1: Simple Plugin with Macros
echo "1. Simple Plugin with Macros:\n";
echo "   Registering plugin that adds query macros...\n\n";

class ProductMacrosPlugin extends AbstractPlugin
{
    public function register(PdoDb $db): void
    {
        // Register macros for QueryBuilder
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

// Insert test data
$db->find()->table('products')->insert([
    'name' => 'Laptop',
    'price' => 999.99,
    'status' => 'active',
    'category_id' => 1,
]);

$db->find()->table('products')->insert([
    'name' => 'Phone',
    'price' => 599.99,
    'status' => 'active',
    'category_id' => 1,
]);

$db->find()->table('products')->insert([
    'name' => 'Tablet',
    'price' => 299.99,
    'status' => 'inactive',
    'category_id' => 2,
]);

// Use macros
$activeProducts = $db->find()
    ->table('products')
    ->active()
    ->get();

echo "   Active products: " . count($activeProducts) . "\n";

$expensiveProducts = $db->find()
    ->table('products')
    ->active()
    ->wherePrice('>', 500.00)
    ->get();

echo "   Expensive active products (> \$500): " . count($expensiveProducts) . "\n\n";

// Example 2: Plugin with Scopes
echo "2. Plugin with Global Scopes:\n";
echo "   Registering plugin that adds global scopes...\n\n";

class SoftDeletePlugin extends AbstractPlugin
{
    public function register(PdoDb $db): void
    {
        // Register global scope that automatically filters deleted records
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

// Scope is automatically applied to all queries
$allProducts = $db->find()
    ->table('products')
    ->get();

echo "   Products with soft-delete scope applied: " . count($allProducts) . "\n\n";

// Example 3: Plugin with Event Listeners
echo "3. Plugin with Event Listeners:\n";
echo "   Registering plugin that listens to query events...\n\n";

$queryCount = 0;
$queryTimes = [];

class QueryMonitorPlugin extends AbstractPlugin
{
    private int $queryCount;
    private array $queryTimes;

    public function __construct(int &$queryCount, array &$queryTimes)
    {
        $this->queryCount = &$queryCount;
        $this->queryTimes = &$queryTimes;
    }

    public function register(PdoDb $db): void
    {
        $dispatcher = $db->getEventDispatcher();
        if ($dispatcher !== null) {
            $dispatcher->addListener(
                QueryExecutedEvent::class,
                function (QueryExecutedEvent $event) {
                    $this->queryCount++;
                    $this->queryTimes[] = $event->getExecutionTime();
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
$dispatcher = new EventDispatcher();
$db->setEventDispatcher($dispatcher);

$db->registerPlugin(new QueryMonitorPlugin($queryCount, $queryTimes));

// Execute some queries
$db->find()->table('products')->get();
$db->find()->table('products')->where('status', 'active')->get();

echo "   Queries monitored: $queryCount\n";
if (count($queryTimes) > 0) {
    $avgTime = array_sum($queryTimes) / count($queryTimes);
    echo "   Average query time: " . round($avgTime * 1000, 2) . " ms\n";
}
echo "\n";

// Example 4: Complex Plugin with Multiple Features
echo "4. Complex Plugin with Multiple Features:\n";
echo "   Registering plugin that combines macros, scopes, and events...\n\n";

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

        // Event listeners (if dispatcher is available)
        $dispatcher = $db->getEventDispatcher();
        if ($dispatcher !== null) {
            $dispatcher->addListener(
                QueryExecutedEvent::class,
                function (QueryExecutedEvent $event) {
                    // Log slow queries (example)
                    if ($event->getExecutionTime() > 0.1) {
                        error_log("Slow query detected: " . substr($event->getSql(), 0, 50));
                    }
                }
            );
        }
    }

    public function getName(): string
    {
        return 'ecommerce';
    }
}

$db->registerPlugin(new ECommercePlugin());

// Use all features
$featuredProducts = $db->find()
    ->table('products')
    ->featured()
    ->inCategory(1)
    ->get();

echo "   Featured products in category 1: " . count($featuredProducts) . "\n\n";

// Example 5: Plugin Management
echo "5. Plugin Management:\n";
echo "   Managing registered plugins...\n\n";

// Check if plugin is registered
if ($db->hasPlugin('product-macros')) {
    echo "   ✓ 'product-macros' plugin is registered\n";
}

// Get plugin instance
$plugin = $db->getPlugin('product-macros');
if ($plugin !== null) {
    echo "   ✓ Retrieved plugin: " . $plugin->getName() . "\n";
}

// Get all plugins
$plugins = $db->getPlugins();
echo "   Registered plugins: " . count($plugins) . "\n";
foreach ($plugins as $name => $pluginInstance) {
    echo "     - $name\n";
}

// Unregister plugin (macros remain registered)
$db->unregisterPlugin('product-macros');
echo "   ✓ Unregistered 'product-macros' plugin\n";
echo "   Macros still work (they remain registered): " . (QueryBuilder::hasMacro('active') ? 'Yes' : 'No') . "\n";

echo "\n=== Plugin System Example Complete ===\n";

