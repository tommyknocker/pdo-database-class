<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

$driver = getenv('PDODB_DRIVER') ?: 'sqlite';
$config = getExampleConfig();

echo "=== Materialized Common Table Expressions (CTEs) Examples ===\n\n";
echo "Database: $driver\n\n";

$pdoDb = createExampleDb($config);
$query = $pdoDb->find();
$dialect = $query->getDialect();

// Check if materialized CTE is supported
if (!$dialect->supportsMaterializedCte()) {
    echo "⚠️  Materialized CTE is not supported by {$driver} dialect.\n";
    echo "This example will be skipped.\n\n";
    exit(0);
}

// Create test tables based on driver
if ($driver === 'mysql' || $driver === 'mariadb') {
    $pdoDb->rawQuery('DROP TABLE IF EXISTS orders');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS order_items');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS customers');
    
    $pdoDb->rawQuery('
        CREATE TABLE orders (
            id INT PRIMARY KEY AUTO_INCREMENT,
            customer_id INT,
            order_date DATE,
            total DECIMAL(10,2)
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE order_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            order_id INT,
            product_name VARCHAR(100),
            quantity INT,
            price DECIMAL(10,2)
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE customers (
            id INT PRIMARY KEY,
            name VARCHAR(100),
            email VARCHAR(100)
        )
    ');
} elseif ($driver === 'pgsql') {
    $pdoDb->rawQuery('DROP TABLE IF EXISTS orders CASCADE');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS order_items CASCADE');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS customers CASCADE');
    
    $pdoDb->rawQuery('
        CREATE TABLE orders (
            id SERIAL PRIMARY KEY,
            customer_id INTEGER,
            order_date DATE,
            total DECIMAL(10,2)
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE order_items (
            id SERIAL PRIMARY KEY,
            order_id INTEGER,
            product_name VARCHAR(100),
            quantity INTEGER,
            price DECIMAL(10,2)
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE customers (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            email VARCHAR(100)
        )
    ');
} else {
    $pdoDb->rawQuery('DROP TABLE IF EXISTS orders');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS order_items');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS customers');
    
    $pdoDb->rawQuery('
        CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER,
            order_date TEXT,
            total REAL
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER,
            product_name TEXT,
            quantity INTEGER,
            price REAL
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE customers (
            id INTEGER PRIMARY KEY,
            name TEXT,
            email TEXT
        )
    ');
}

// Insert sample data
$pdoDb->find()->table('customers')->insertMulti([
    ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
    ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
    ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
]);

$pdoDb->find()->table('orders')->insertMulti([
    ['customer_id' => 1, 'order_date' => '2024-01-15', 'total' => 150.00],
    ['customer_id' => 1, 'order_date' => '2024-02-10', 'total' => 250.00],
    ['customer_id' => 2, 'order_date' => '2024-01-20', 'total' => 300.00],
    ['customer_id' => 3, 'order_date' => '2024-02-05', 'total' => 100.00],
]);

$pdoDb->find()->table('order_items')->insertMulti([
    ['order_id' => 1, 'product_name' => 'Widget A', 'quantity' => 2, 'price' => 50.00],
    ['order_id' => 1, 'product_name' => 'Widget B', 'quantity' => 1, 'price' => 50.00],
    ['order_id' => 2, 'product_name' => 'Widget C', 'quantity' => 5, 'price' => 50.00],
    ['order_id' => 3, 'product_name' => 'Widget D', 'quantity' => 3, 'price' => 100.00],
    ['order_id' => 4, 'product_name' => 'Widget E', 'quantity' => 1, 'price' => 100.00],
]);

// Example 1: Basic materialized CTE
echo "1. Basic Materialized CTE - High-value orders:\n";
echo "   (Result is cached and computed once)\n";
$results = $pdoDb->find()
    ->withMaterialized('high_value_orders', function ($q) {
        $q->from('orders')->where('total', 200, '>');
    })
    ->from('high_value_orders')
    ->orderBy('total', 'DESC')
    ->get();

foreach ($results as $order) {
    printf("  - Order ID %d: $%.2f on %s\n", 
        $order['id'], 
        $order['total'], 
        $order['order_date']
    );
}

// Show SQL to verify MATERIALIZED keyword
$sqlData = $pdoDb->find()
    ->withMaterialized('high_value_orders', function ($q) {
        $q->from('orders')->where('total', 200, '>');
    })
    ->from('high_value_orders')
    ->toSQL();

echo "\n   Generated SQL:\n";
if ($driver === 'pgsql') {
    echo "   " . str_replace("\n", "\n   ", $sqlData['sql']) . "\n";
    if (str_contains($sqlData['sql'], 'MATERIALIZED')) {
        echo "   ✓ SQL contains MATERIALIZED keyword\n";
    }
} elseif ($driver === 'mysql' || $driver === 'mariadb') {
    echo "   " . str_replace("\n", "\n   ", $sqlData['sql']) . "\n";
    if (str_contains($sqlData['sql'], 'MATERIALIZE')) {
        echo "   ✓ SQL contains MATERIALIZE optimizer hint\n";
    }
}
echo "\n";

// Example 2: Materialized CTE with multiple references
echo "2. Materialized CTE referenced multiple times:\n";
echo "   (CTE is computed once and reused)\n";
$results = $pdoDb->find()
    ->withMaterialized('customer_stats', function ($q) {
        $q->from('orders')
            ->select([
                'customer_id',
                'order_count' => Db::count('*'),
                'total_spent' => Db::sum('total'),
                'avg_order' => Db::avg('total'),
            ])
            ->groupBy('customer_id');
    })
    ->from('customers')
    ->join('customer_stats', 'customers.id = customer_stats.customer_id')
    ->select([
        'customers.name',
        'customers.email',
        'customer_stats.order_count',
        'customer_stats.total_spent',
        'customer_stats.avg_order',
    ])
    ->where('customer_stats.total_spent', 200, '>')
    ->orderBy('customer_stats.total_spent', 'DESC')
    ->get();

foreach ($results as $customer) {
    printf("  - %s (%s): %d orders, $%.2f total, $%.2f avg\n", 
        $customer['name'], 
        $customer['email'],
        $customer['order_count'],
        $customer['total_spent'],
        $customer['avg_order']
    );
}
echo "\n";

// Example 3: Materialized CTE with explicit column list
echo "3. Materialized CTE with explicit column list:\n";
$results = $pdoDb->find()
    ->withMaterialized('order_summary', function ($q) {
        $q->from('orders')
            ->select(['id', 'total', 'order_date'])
            ->where('total', 150, '>');
    }, ['order_id', 'amount', 'date'])
    ->from('order_summary')
    ->where('amount', 200, '>')
    ->orderBy('amount')
    ->get();

foreach ($results as $order) {
    printf("  - Order %d: $%.2f on %s\n", 
        $order['order_id'], 
        $order['amount'], 
        $order['date']
    );
}
echo "\n";

// Example 4: Multiple materialized CTEs
echo "4. Multiple Materialized CTEs:\n";
$results = $pdoDb->find()
    ->withMaterialized('recent_orders', function ($q) {
        $q->from('orders')
            ->where('order_date', '2024-02-01', '>=');
    })
    ->withMaterialized('order_details', function ($q) {
        $q->from('order_items')
            ->select([
                'order_id',
                'item_count' => Db::count('*'),
                'total_items' => Db::sum('quantity'),
            ])
            ->groupBy('order_id');
    })
    ->from('recent_orders')
    ->join('order_details', 'recent_orders.id = order_details.order_id')
    ->select([
        'recent_orders.id',
        'recent_orders.total',
        'order_details.item_count',
        'order_details.total_items',
    ])
    ->orderBy('recent_orders.order_date')
    ->get();

foreach ($results as $order) {
    printf("  - Order %d: $%.2f with %d items (%d total units)\n", 
        $order['id'], 
        $order['total'], 
        $order['item_count'],
        $order['total_items']
    );
}
echo "\n";

// Example 5: Performance comparison (conceptual)
echo "5. When to use Materialized CTE:\n";
echo "   - Expensive computations that are referenced multiple times\n";
echo "   - Complex aggregations in CTEs\n";
echo "   - Queries with multiple CTEs where optimization matters\n";
echo "\n";

echo "=== All materialized CTE examples completed successfully ===\n";

