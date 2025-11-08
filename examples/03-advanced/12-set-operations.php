<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$config = getExampleConfig();
$db = createExampleDb($config);

echo "=== Set Operations (UNION, INTERSECT, EXCEPT) ===\n\n";

// Create test tables
$db->rawQuery('DROP TABLE IF EXISTS products_eu');
$db->rawQuery('DROP TABLE IF EXISTS products_us');
$db->rawQuery('DROP TABLE IF EXISTS orders_2023');
$db->rawQuery('DROP TABLE IF EXISTS orders_2024');

$db->rawQuery('CREATE TABLE products_eu (id INTEGER PRIMARY KEY, name TEXT, price DECIMAL(10,2))');
$db->rawQuery('CREATE TABLE products_us (id INTEGER PRIMARY KEY, name TEXT, price DECIMAL(10,2))');
$db->rawQuery('CREATE TABLE orders_2023 (id INTEGER PRIMARY KEY, product_id INTEGER, amount DECIMAL(10,2))');
$db->rawQuery('CREATE TABLE orders_2024 (id INTEGER PRIMARY KEY, product_id INTEGER, amount DECIMAL(10,2))');

// Insert sample data
$db->find()->table('products_eu')->insertMulti([
    ['id' => 1, 'name' => 'Laptop', 'price' => 999.99],
    ['id' => 2, 'name' => 'Mouse', 'price' => 29.99],
    ['id' => 3, 'name' => 'Keyboard', 'price' => 79.99],
]);

$db->find()->table('products_us')->insertMulti([
    ['id' => 1, 'name' => 'Laptop', 'price' => 999.99],
    ['id' => 4, 'name' => 'Monitor', 'price' => 299.99],
    ['id' => 5, 'name' => 'Webcam', 'price' => 89.99],
]);

$db->find()->table('orders_2023')->insertMulti([
    ['id' => 1, 'product_id' => 1, 'amount' => 1999.98],
    ['id' => 2, 'product_id' => 2, 'amount' => 29.99],
]);

$db->find()->table('orders_2024')->insertMulti([
    ['id' => 3, 'product_id' => 1, 'amount' => 999.99],
    ['id' => 4, 'product_id' => 4, 'amount' => 299.99],
]);

// Example 1: UNION - Combine results from multiple queries (removes duplicates)
echo "1. UNION - All products from EU and US (duplicates removed):\n";
$results = $db->find()
    ->from('products_eu')
    ->select(['name', 'price'])
    ->union(function ($qb) {
        $qb->from('products_us')->select(['name', 'price']);
    })
    ->orderBy('name')
    ->get();

foreach ($results as $row) {
    printf("   %s - $%.2f\n", $row['name'], $row['price']);
}
echo "\n";

// Example 2: UNION ALL - Keep all duplicates
echo "2. UNION ALL - All products (including duplicates):\n";
$results = $db->find()
    ->from('products_eu')
    ->select(['name'])
    ->unionAll(function ($qb) {
        $qb->from('products_us')->select(['name']);
    })
    ->get();

echo "   Total products: " . count($results) . "\n";
foreach ($results as $row) {
    printf("   - %s\n", $row['name']);
}
echo "\n";

// Example 3: INTERSECT - Find common products
echo "3. INTERSECT - Products available in both EU and US:\n";
$results = $db->find()
    ->from('products_eu')
    ->select(['name'])
    ->intersect(function ($qb) {
        $qb->from('products_us')->select(['name']);
    })
    ->get();

foreach ($results as $row) {
    printf("   - %s\n", $row['name']);
}
echo "\n";

// Example 4: EXCEPT - Find products only in EU
echo "4. EXCEPT - Products only in EU (not in US):\n";
$results = $db->find()
    ->from('products_eu')
    ->select(['name'])
    ->except(function ($qb) {
        $qb->from('products_us')->select(['name']);
    })
    ->orderBy('name')
    ->get();

foreach ($results as $row) {
    printf("   - %s\n", $row['name']);
}
echo "\n";

// Example 5: Multiple UNION operations
echo "5. Multiple UNION - All orders from both years:\n";
$results = $db->find()
    ->from('orders_2023')
    ->select(['product_id', 'amount'])
    ->unionAll(function ($qb) {
        $qb->from('orders_2024')->select(['product_id', 'amount']);
    })
    ->orderBy('product_id')
    ->get();

echo "   Total combined orders: " . count($results) . "\n";
foreach ($results as $row) {
    printf("   Product #%d: $%.2f\n", $row['product_id'], $row['amount']);
}
echo "\n";

// Example 6: UNION with aggregation
echo "6. Aggregation per table (using separate queries):\n";
$orders2023 = $db->find()->from('orders_2023')->select(['total_revenue' => Db::sum('amount')])->getOne();
$orders2024 = $db->find()->from('orders_2024')->select(['total_revenue' => Db::sum('amount')])->getOne();
printf("   2023: $%.2f\n", $orders2023['total_revenue']);
printf("   2024: $%.2f\n", $orders2024['total_revenue']);
echo "\n";

echo "✓ Set operations examples completed!\n";

