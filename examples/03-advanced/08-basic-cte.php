<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

$driverEnv = getenv('PDODB_DRIVER') ?: 'sqlite';
$config = getExampleConfig();
$driver = $config['driver'] ?? $driverEnv;

echo "=== Common Table Expressions (CTEs) Examples ===\n\n";
echo "Database: $driver\n\n";

$pdoDb = createExampleDb($config);

// Create test tables using fluent API (cross-dialect)
$schema = $pdoDb->schema();
$schema->dropTableIfExists('products');
$schema->dropTableIfExists('sales');
$schema->dropTableIfExists('employees');

$schema->createTable('products', [
    'id' => $schema->integer()->notNull(),
    'name' => $schema->string(100),
    'category' => $schema->string(50),
    'price' => $schema->decimal(10, 2),
], ['primaryKey' => ['id']]);

$schema->createTable('sales', [
    'id' => $schema->primaryKey(),
    'product_id' => $schema->integer(),
    'quantity' => $schema->integer(),
    'sale_date' => $schema->date(),
]);

$schema->createTable('employees', [
    'id' => $schema->integer()->notNull(),
    'name' => $schema->string(100),
    'manager_id' => $schema->integer(),
], ['primaryKey' => ['id']]);

// Insert sample data
$pdoDb->find()->table('products')->insertMulti([
    ['id' => 1, 'name' => 'Laptop', 'category' => 'Electronics', 'price' => 999.99],
    ['id' => 2, 'name' => 'Mouse', 'category' => 'Electronics', 'price' => 29.99],
    ['id' => 3, 'name' => 'Desk', 'category' => 'Furniture', 'price' => 299.99],
    ['id' => 4, 'name' => 'Chair', 'category' => 'Furniture', 'price' => 199.99],
    ['id' => 5, 'name' => 'Monitor', 'category' => 'Electronics', 'price' => 399.99],
]);

$pdoDb->find()->table('sales')->insertMulti([
    ['product_id' => 1, 'quantity' => 10, 'sale_date' => '2024-01-15'],
    ['product_id' => 1, 'quantity' => 5, 'sale_date' => '2024-02-20'],
    ['product_id' => 2, 'quantity' => 50, 'sale_date' => '2024-01-10'],
    ['product_id' => 3, 'quantity' => 8, 'sale_date' => '2024-01-25'],
    ['product_id' => 4, 'quantity' => 12, 'sale_date' => '2024-02-05'],
    ['product_id' => 5, 'quantity' => 15, 'sale_date' => '2024-02-15'],
]);

$pdoDb->find()->table('employees')->insertMulti([
    ['id' => 1, 'name' => 'Alice', 'manager_id' => null],
    ['id' => 2, 'name' => 'Bob', 'manager_id' => 1],
    ['id' => 3, 'name' => 'Charlie', 'manager_id' => 1],
    ['id' => 4, 'name' => 'David', 'manager_id' => 2],
    ['id' => 5, 'name' => 'Eve', 'manager_id' => 2],
]);

// Example 1: Simple CTE with Closure
echo "1. Simple CTE - High-value products:\n";
$results = $pdoDb->find()
    ->with('expensive_products', function ($q) {
        $q->from('products')->where('price', 200, '>');
    })
    ->from('expensive_products')
    ->orderBy('price', 'DESC')
    ->get();

foreach ($results as $product) {
    printf("  - %s: $%.2f\n", $product['name'], $product['price']);
}
echo "\n";

// Example 2: CTE with QueryBuilder instance
echo "2. CTE with QueryBuilder - Electronics category:\n";
$electronicsQuery = $pdoDb->find()
    ->from('products')
    ->where('category', 'Electronics');

$results = $pdoDb->find()
    ->with('electronics', $electronicsQuery)
    ->from('electronics')
    ->orderBy('name')
    ->get();

foreach ($results as $product) {
    printf("  - %s: $%.2f\n", $product['name'], $product['price']);
}
echo "\n";

// Example 3: CTE with QueryBuilder - Category summaries
echo "3. CTE with QueryBuilder - Category summaries:\n";
$statsQuery = $pdoDb->find()
    ->from('products')
    ->select([
        'category',
        'product_count' => Db::count('*'),
        'avg_price' => Db::avg('price'),
    ])
    ->groupBy('category');

$results = $pdoDb->find()
    ->with('category_stats', $statsQuery)
    ->from('category_stats')
    ->orderBy('product_count', 'DESC')
    ->get();

foreach ($results as $stat) {
    printf("  - %s: %d products, avg $%.2f\n", 
        $stat['category'], 
        $stat['product_count'], 
        $stat['avg_price']
    );
}
echo "\n";

// Example 4: Multiple CTEs
echo "4. Multiple CTEs - Sales analysis:\n";
$combinedQuery = $pdoDb->find()
    ->from('high_value_products AS p')
    ->join('high_quantity_sales AS s', 'p.id = s.product_id')
    ->select(['p.name', 'p.price', 's.quantity', 's.sale_date']);

$results = $pdoDb->find()
    ->with('high_value_products', function ($q) {
        $q->from('products')->where('price', 300, '>');
    })
    ->with('high_quantity_sales', function ($q) {
        $q->from('sales')->where('quantity', 10, '>');
    })
    ->with('combined', $combinedQuery)
    ->from('combined')
    ->orderBy('sale_date')
    ->get();

foreach ($results as $sale) {
    printf("  - %s (%.2f): %d units on %s\n", 
        $sale['name'], 
        $sale['price'], 
        $sale['quantity'], 
        $sale['sale_date']
    );
}
echo "\n";

// Example 5: CTE with column list
echo "5. CTE with explicit column list:\n";
$results = $pdoDb->find()
    ->with('product_summary', function ($q) {
        $q->from('products')
            ->select(['name', 'price'])
            ->where('category', 'Electronics');
    }, ['product_name', 'product_price'])
    ->from('product_summary')
    ->where('product_price', 100, '>')
    ->orderBy('product_price')
    ->get();

foreach ($results as $product) {
    printf("  - %s: $%.2f\n", $product['product_name'], $product['product_price']);
}
echo "\n";

// Example 6: CTE with JOIN
echo "6. CTE with JOIN - Sales with product details:\n";
$results = $pdoDb->find()
    ->with('recent_sales', function ($q) {
        $q->from('sales')
            ->where('sale_date', '2024-02-01', '>=')
            ->select(['product_id', 'quantity', 'sale_date']);
    })
    ->from('products')
    ->join('recent_sales', 'products.id = recent_sales.product_id')
    ->select(['products.name', 'recent_sales.quantity', 'recent_sales.sale_date'])
    ->orderBy('recent_sales.sale_date')
    ->get();

foreach ($results as $sale) {
    printf("  - %s: %d units on %s\n", 
        $sale['name'], 
        $sale['quantity'], 
        $sale['sale_date']
    );
}
echo "\n";

echo "=== All examples completed successfully ===\n";

