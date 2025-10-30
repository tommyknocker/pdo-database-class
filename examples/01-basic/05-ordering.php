<?php
/**
 * Example 05: Ordering Results
 * 
 * Demonstrates various ways to order query results
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Ordering Examples (on $driver) ===\n\n";

// Setup
recreateTable($db, 'products', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT',
    'category' => 'TEXT',
    'price' => 'REAL',
    'stock' => 'INTEGER',
    'rating' => 'REAL'
]);

$products = [
    ['name' => 'Laptop Pro', 'category' => 'Electronics', 'price' => 1299.99, 'stock' => 15, 'rating' => 4.8],
    ['name' => 'Mouse Wireless', 'category' => 'Electronics', 'price' => 29.99, 'stock' => 50, 'rating' => 4.5],
    ['name' => 'Keyboard Mechanical', 'category' => 'Electronics', 'price' => 89.99, 'stock' => 30, 'rating' => 4.7],
    ['name' => 'Monitor 27"', 'category' => 'Electronics', 'price' => 399.99, 'stock' => 20, 'rating' => 4.6],
    ['name' => 'Desk Standing', 'category' => 'Furniture', 'price' => 499.99, 'stock' => 5, 'rating' => 4.9],
    ['name' => 'Chair Ergonomic', 'category' => 'Furniture', 'price' => 299.99, 'stock' => 8, 'rating' => 4.8],
    ['name' => 'Lamp LED', 'category' => 'Furniture', 'price' => 49.99, 'stock' => 25, 'rating' => 4.4],
];

$db->find()->table('products')->insertMulti($products);
echo "✓ Inserted " . count($products) . " products\n\n";

// Example 1: Single column ordering (ASC)
echo "1. Order by price (ascending)...\n";
$byPriceAsc = $db->find()
    ->from('products')
    ->select(['name', 'price'])
    ->orderBy('price', 'ASC')
    ->get();

foreach ($byPriceAsc as $p) {
    echo "  • {$p['name']}: \${$p['price']}\n";
}
echo "\n";

// Example 2: Single column ordering (DESC)
echo "2. Order by rating (descending)...\n";
$byRatingDesc = $db->find()
    ->from('products')
    ->select(['name', 'rating'])
    ->orderBy('rating', 'DESC')
    ->limit(3)
    ->get();

foreach ($byRatingDesc as $p) {
    echo "  • {$p['name']}: {$p['rating']} ⭐\n";
}
echo "\n";

// Example 3: Multiple columns (chained calls)
echo "3. Order by category ASC, then price DESC (chained)...\n";
$multipleChained = $db->find()
    ->from('products')
    ->select(['name', 'category', 'price'])
    ->orderBy('category', 'ASC')
    ->orderBy('price', 'DESC')
    ->get();

foreach ($multipleChained as $p) {
    echo "  • [{$p['category']}] {$p['name']}: \${$p['price']}\n";
}
echo "\n";

// Example 4: Array with explicit directions
echo "4. Order by category DESC, rating ASC (array with explicit directions)...\n";
$arrayExplicit = $db->find()
    ->from('products')
    ->select(['name', 'category', 'rating'])
    ->orderBy(['category' => 'DESC', 'rating' => 'ASC'])
    ->get();

foreach ($arrayExplicit as $p) {
    echo "  • [{$p['category']}] {$p['name']}: {$p['rating']} ⭐\n";
}
echo "\n";

// Example 5: Array with default direction
echo "5. Order by stock and name (array with default DESC)...\n";
$arrayDefault = $db->find()
    ->from('products')
    ->select(['name', 'stock'])
    ->orderBy(['stock', 'name'], 'DESC')
    ->get();

foreach ($arrayDefault as $p) {
    echo "  • {$p['name']}: {$p['stock']} in stock\n";
}
echo "\n";

// Example 6: Comma-separated string with directions
echo "6. Order by category ASC, price DESC, name ASC (comma-separated)...\n";
$commaSeparated = $db->find()
    ->from('products')
    ->select(['name', 'category', 'price'])
    ->orderBy('category ASC, price DESC, name ASC')
    ->get();

foreach ($commaSeparated as $p) {
    echo "  • [{$p['category']}] {$p['name']}: \${$p['price']}\n";
}
echo "\n";

// Example 7: Comma-separated with partial directions
echo "7. Order by price DESC, name (comma-separated, name defaults to ASC)...\n";
$commaPartial = $db->find()
    ->from('products')
    ->select(['name', 'price'])
    ->orderBy('price DESC, name')
    ->limit(5)
    ->get();

foreach ($commaPartial as $p) {
    echo "  • {$p['name']}: \${$p['price']}\n";
}
echo "\n";

// Example 8: Order by expression (CASE WHEN)
echo "8. Order by custom priority (Electronics first, then Furniture)...\n";
$byPriority = $db->find()
    ->from('products')
    ->select(['name', 'category', 'price'])
    ->orderBy(Db::case(["category = 'Electronics'" => '1'], '2'))
    ->orderBy('price', 'DESC')
    ->get();

foreach ($byPriority as $p) {
    echo "  • [{$p['category']}] {$p['name']}: \${$p['price']}\n";
}
echo "\n";

// Example 9: Combining different methods
echo "9. Mixed ordering methods (array + chained)...\n";
$mixed = $db->find()
    ->from('products')
    ->select(['name', 'category', 'stock'])
    ->orderBy(['category' => 'ASC'])
    ->orderBy('stock', 'DESC')
    ->get();

foreach ($mixed as $p) {
    echo "  • [{$p['category']}] {$p['name']}: {$p['stock']} in stock\n";
}
echo "\n";

// Example 10: Order by multiple columns with limit and offset
echo "10. Paginated results (order by rating DESC, limit 3, offset 1)...\n";
$paginated = $db->find()
    ->from('products')
    ->select(['name', 'rating'])
    ->orderBy('rating', 'DESC')
    ->limit(3)
    ->offset(1)
    ->get();

foreach ($paginated as $p) {
    echo "  • {$p['name']}: {$p['rating']} ⭐\n";
}
echo "\n";

// Example 11: DISTINCT - Get unique categories
echo "11. DISTINCT - Unique categories...\n";
$categories = $db->find()
    ->from('products')
    ->select(['category'])
    ->distinct()
    ->orderBy('category')
    ->get();

echo "  Available categories:\n";
foreach ($categories as $cat) {
    echo "  • {$cat['category']}\n";
}
echo "\n";

// Example 12: DISTINCT with multiple columns
echo "12. DISTINCT - Unique category + high stock combinations...\n";
$uniqueCombinations = $db->find()
    ->from('products')
    ->select(['category', 'stock'])
    ->where('stock', 10, '>')
    ->distinct()
    ->orderBy('category')
    ->orderBy('stock')
    ->get();

foreach ($uniqueCombinations as $combo) {
    echo "  • {$combo['category']}: {$combo['stock']} in stock\n";
}

echo "\nAll ordering examples completed!\n";

