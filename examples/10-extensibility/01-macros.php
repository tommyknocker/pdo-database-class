<?php
/**
 * Example: Query Builder Macros.
 *
 * Demonstrates custom query method macros for extending QueryBuilder.
 *
 * Usage:
 *   php examples/29-macros/01-macro-examples.php
 *   PDODB_DRIVER=mysql php examples/29-macros/01-macro-examples.php
 *   PDODB_DRIVER=pgsql php examples/29-macros/01-macro-examples.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\query\QueryBuilder;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Query Builder Macros Example (on $driver) ===\n\n";

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
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
            created_at DATETIME DEFAULT GETDATE()
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

echo "✓ Table created\n\n";

// Example 1: Basic Macro Registration
echo "1. Basic Macro Registration\n";
echo "-----------------------------\n";

QueryBuilder::macro('active', function (QueryBuilder $query) {
    return $query->where('status', 'active');
});

QueryBuilder::macro('inactive', function (QueryBuilder $query) {
    return $query->where('status', 'inactive');
});

// Check if macro exists
echo "Macro 'active' exists: " . (QueryBuilder::hasMacro('active') ? 'Yes' : 'No') . "\n";
echo "Macro 'inactive' exists: " . (QueryBuilder::hasMacro('inactive') ? 'Yes' : 'No') . "\n";

// Use the macro
$db->find()->table('products')->insert([
    'name' => 'Product 1',
    'price' => 100.00,
    'status' => 'active',
]);

$db->find()->table('products')->insert([
    'name' => 'Product 2',
    'price' => 200.00,
    'status' => 'inactive',
]);

$activeProducts = $db->find()
    ->table('products')
    ->active()
    ->get();

echo "Active products: " . count($activeProducts) . "\n";
foreach ($activeProducts as $product) {
    echo "  - {$product['name']} (status: {$product['status']})\n";
}

echo "\n";

// Example 2: Macro with Arguments
echo "2. Macro with Arguments\n";
echo "------------------------\n";

QueryBuilder::macro('wherePrice', function (QueryBuilder $query, string $operator, float $price) {
    return $query->where('price', $price, $operator);
});

QueryBuilder::macro('inCategory', function (QueryBuilder $query, int $categoryId) {
    return $query->where('category_id', $categoryId);
});

// Insert test data
$db->find()->table('products')->insert([
    'name' => 'Expensive Product',
    'price' => 500.00,
    'status' => 'active',
    'category_id' => 1,
]);

$db->find()->table('products')->insert([
    'name' => 'Cheap Product',
    'price' => 50.00,
    'status' => 'active',
    'category_id' => 1,
]);

$db->find()->table('products')->insert([
    'name' => 'Another Product',
    'price' => 150.00,
    'status' => 'active',
    'category_id' => 2,
]);

// Use macros with arguments
$expensiveProducts = $db->find()
    ->table('products')
    ->wherePrice('>', 100.00)
    ->get();

echo "Products with price > 100: " . count($expensiveProducts) . "\n";
foreach ($expensiveProducts as $product) {
    echo "  - {$product['name']} (price: {$product['price']})\n";
}

$category1Products = $db->find()
    ->table('products')
    ->inCategory(1)
    ->get();

echo "\nProducts in category 1: " . count($category1Products) . "\n";
foreach ($category1Products as $product) {
    echo "  - {$product['name']}\n";
}

echo "\n";

// Example 3: Complex Macro with Multiple Conditions
echo "3. Complex Macro with Multiple Conditions\n";
echo "------------------------------------------\n";

QueryBuilder::macro('available', function (QueryBuilder $query) {
    return $query
        ->where('status', 'active')
        ->andWhere('price', 0, '>');
});

$availableProducts = $db->find()
    ->table('products')
    ->available()
    ->get();

echo "Available products (active and price > 0): " . count($availableProducts) . "\n";
foreach ($availableProducts as $product) {
    echo "  - {$product['name']} (price: {$product['price']})\n";
}

echo "\n";

// Example 4: Macro with Default Parameters
echo "4. Macro with Default Parameters\n";
echo "----------------------------------\n";

QueryBuilder::macro('recent', function (QueryBuilder $query, int $days = 7) {
    $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    return $query->where('created_at', $date, '>=');
});

// Insert a recent product
$db->find()->table('products')->insert([
    'name' => 'New Product',
    'price' => 75.00,
    'status' => 'active',
    'created_at' => date('Y-m-d H:i:s'),
]);

// Use macro with default parameter
$recentProducts = $db->find()
    ->table('products')
    ->recent() // Uses default 7 days
    ->get();

echo "Recent products (last 7 days): " . count($recentProducts) . "\n";

// Use macro with custom parameter
$veryRecentProducts = $db->find()
    ->table('products')
    ->recent(1) // Last 1 day
    ->get();

echo "Very recent products (last 1 day): " . count($veryRecentProducts) . "\n";

echo "\n";

// Example 5: Chaining Macros
echo "5. Chaining Macros\n";
echo "------------------\n";

$result = $db->find()
    ->table('products')
    ->active()
    ->inCategory(1)
    ->wherePrice('>', 100.00)
    ->orderBy('price', 'DESC')
    ->limit(5)
    ->get();

echo "Chained macros result: " . count($result) . " products\n";
foreach ($result as $product) {
    echo "  - {$product['name']} (price: {$product['price']}, category: {$product['category_id']})\n";
}

echo "\n";

// Example 6: Macro Returning Non-QueryBuilder Value
echo "6. Macro Returning Non-QueryBuilder Value\n";
echo "-------------------------------------------\n";

QueryBuilder::macro('countActive', function (QueryBuilder $query) {
    return $query->where('status', 'active')->getValue('COUNT(*)');
});

$activeCount = $db->find()
    ->table('products')
    ->countActive();

echo "Count of active products: $activeCount\n";

echo "\n";

// Example 7: Macro Overwriting
echo "7. Macro Overwriting\n";
echo "--------------------\n";

// Register original macro
QueryBuilder::macro('special', function (QueryBuilder $query) {
    return $query->where('status', 'active');
});

// Overwrite with new implementation
QueryBuilder::macro('special', function (QueryBuilder $query) {
    return $query->where('status', 'inactive');
});

$specialProducts = $db->find()
    ->table('products')
    ->special()
    ->get();

echo "Products using overwritten macro: " . count($specialProducts) . "\n";
foreach ($specialProducts as $product) {
    echo "  - {$product['name']} (status: {$product['status']})\n";
}

echo "\n";

// Example 8: Error Handling - Non-existent Macro
echo "8. Error Handling - Non-existent Macro\n";
echo "----------------------------------------\n";

try {
    $db->find()->table('products')->nonExistentMacro();
    echo "ERROR: Should have thrown exception\n";
} catch (\RuntimeException $e) {
    echo "✓ Correctly caught exception: " . $e->getMessage() . "\n";
}

echo "\n";

// Cleanup
echo "Cleaning up...\n";
$db->rawQuery('DROP TABLE IF EXISTS products');
echo "✓ Done\n";

