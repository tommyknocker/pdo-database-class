<?php
/**
 * Example: Loading data from JSON files
 *
 * Demonstrates loading data from JSON files into database tables.
 * Supports both JSON array format and NDJSON (newline-delimited JSON).
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== JSON File Loading Example (on $driver) ===\n\n";

// Create table using fluent API (cross-dialect)
$schema = $db->schema();
$driver = getCurrentDriver($db);
recreateTable($db, 'products', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(255),
    'price' => $schema->decimal(10, 2),
    'stock' => $schema->integer(),
    'category' => $schema->string(100),
]);

echo "✓ Table created\n\n";

// Example 1: Load from JSON array format
echo "1. Loading data from JSON array format...\n";
$jsonData1 = [
    ['name' => 'Laptop', 'price' => 1299.99, 'stock' => 15, 'category' => 'Electronics'],
    ['name' => 'Mouse', 'price' => 29.99, 'stock' => 150, 'category' => 'Electronics'],
    ['name' => 'Keyboard', 'price' => 89.99, 'stock' => 50, 'category' => 'Electronics'],
];

$tempFile1 = tempnam(sys_get_temp_dir(), 'json_');
file_put_contents($tempFile1, json_encode($jsonData1));

try {
    $result1 = $db->find()
        ->table('products')
        ->loadJson($tempFile1);
    
    echo "  ✓ Loaded {$result1} rows\n";
    
    // Show the results
    $products = $db->find()
        ->from('products')
        ->get();
    
    echo "  Products loaded:\n";
    foreach ($products as $product) {
        echo "    • {$product['name']}: \${$product['price']} (stock: {$product['stock']})\n";
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

unlink($tempFile1);
echo "\n";

// Example 2: Load from NDJSON format (newline-delimited JSON)
echo "2. Loading data from NDJSON format...\n";

$ndJsonData = [
    json_encode(['name' => 'Monitor', 'price' => 249.99, 'stock' => 30, 'category' => 'Electronics']),
    json_encode(['name' => 'Headphones', 'price' => 79.99, 'stock' => 100, 'category' => 'Audio']),
    json_encode(['name' => 'Desk', 'price' => 199.99, 'stock' => 20, 'category' => 'Furniture']),
];

$tempFile2 = tempnam(sys_get_temp_dir(), 'json_');
file_put_contents($tempFile2, implode("\n", $ndJsonData));

try {
    $result2 = $db->find()
        ->table('products')
        ->loadJson($tempFile2, [
            'format' => 'lines',
        ]);
    
    echo "  ✓ Loaded {$result2} rows\n";
    
    // Show total count
    $total = $db->find()
        ->from('products')
        ->select([Db::count()])
        ->getValue();
    
    echo "  Total products in database: {$total}\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

unlink($tempFile2);
echo "\n";

// Example 3: Load with specified columns
echo "3. Loading data with specified columns...\n";

$jsonData3 = [
    ['name' => 'Tablet', 'price' => 399.99, 'stock' => 25],
];

$tempFile3 = tempnam(sys_get_temp_dir(), 'json_');
file_put_contents($tempFile3, json_encode($jsonData3));

try {
    $result3 = $db->find()
        ->table('products')
        ->loadJson($tempFile3, [
            'columns' => ['name', 'price', 'stock'],
        ]);
    
    echo "  ✓ Loaded with specified columns\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

unlink($tempFile3);
echo "\n";

// Example 4: Load with batch size control
echo "4. Loading data with batch size control...\n";

$jsonData4 = [
    ['name' => 'Laptop Pro', 'price' => 1999.99, 'stock' => 10, 'category' => 'Electronics'],
    ['name' => 'Phone', 'price' => 699.99, 'stock' => 75, 'category' => 'Electronics'],
];

$tempFile4 = tempnam(sys_get_temp_dir(), 'json_');
file_put_contents($tempFile4, json_encode($jsonData4));

try {
    $result4 = $db->find()
        ->table('products')
        ->loadJson($tempFile4, [
            'batchSize' => 1,
        ]);
    
    echo "  ✓ Loaded with custom batch size\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

unlink($tempFile4);
echo "\n";

// Example 5: Show final results
echo "5. Final results...\n";
$allProducts = $db->find()
    ->from('products')
    ->orderBy('name')
    ->get();

echo "  Total products: " . count($allProducts) . "\n";
echo "  Categories: " . implode(', ', array_unique(array_column($allProducts, 'category'))) . "\n";

echo "\nJSON file loading example completed!\n";

