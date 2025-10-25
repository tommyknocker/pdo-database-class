<?php
/**
 * Example: Comparison Helper Functions
 * 
 * Demonstrates comparison operations and conditions
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Comparison Helper Functions Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'products', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT',
    'price' => 'REAL',
    'category' => 'TEXT',
    'description' => 'TEXT',
    'tags' => 'TEXT',
    'rating' => 'REAL',
    'in_stock' => 'BOOLEAN'
]);

$db->find()->table('products')->insertMulti([
    ['name' => 'Laptop Pro', 'price' => 999.99, 'category' => 'Electronics', 'description' => 'High-performance laptop', 'tags' => 'computer,portable', 'rating' => 4.5, 'in_stock' => 1],
    ['name' => 'Wireless Mouse', 'price' => 29.99, 'category' => 'Electronics', 'description' => 'Ergonomic wireless mouse', 'tags' => 'computer,accessory', 'rating' => 4.2, 'in_stock' => 1],
    ['name' => 'Programming Book', 'price' => 19.99, 'category' => 'Education', 'description' => 'Learn PHP programming', 'tags' => 'learning,programming', 'rating' => 4.8, 'in_stock' => 0],
    ['name' => 'Office Desk', 'price' => 299.99, 'category' => 'Furniture', 'description' => 'Modern office desk', 'tags' => 'furniture,office', 'rating' => 4.0, 'in_stock' => 1],
    ['name' => 'Coffee Mug', 'price' => 12.99, 'category' => 'Kitchen', 'description' => 'Ceramic coffee mug', 'tags' => 'kitchen,drink', 'rating' => 3.5, 'in_stock' => 1],
]);

echo "✓ Test data inserted\n\n";

// Example 1: LIKE operations
echo "1. LIKE operations...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::like('name', '%top%'))
    ->select(['name', 'price'])
    ->get();

echo "  Products with 'top' in name:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: \${$row['price']}\n";
}
echo "\n";

// Example 2: BETWEEN operations
echo "2. BETWEEN operations...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::between('price', 20, 100))
    ->select(['name', 'price'])
    ->get();

echo "  Products between \$20 and \$100:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: \${$row['price']}\n";
}
echo "\n";

// Example 3: IN operations
echo "3. IN operations...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::in('category', ['Electronics', 'Education']))
    ->select(['name', 'category'])
    ->get();

echo "  Products in Electronics or Education categories:\n";
foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['category']})\n";
}
echo "\n";

// Example 4: NOT operations
echo "4. NOT operations...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::not(Db::like('name', '%Book%')))
    ->select(['name'])
    ->get();

echo "  Products NOT containing 'Book' in name:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}\n";
}
echo "\n";

// Example 5: NOT BETWEEN operations
echo "5. NOT BETWEEN operations...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::notBetween('price', 50, 200))
    ->select(['name', 'price'])
    ->get();

echo "  Products NOT between \$50 and \$200:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: \${$row['price']}\n";
}
echo "\n";

// Example 6: NOT IN operations
echo "6. NOT IN operations...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::notIn('category', ['Furniture', 'Kitchen']))
    ->select(['name', 'category'])
    ->get();

echo "  Products NOT in Furniture or Kitchen categories:\n";
foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['category']})\n";
}
echo "\n";

// Example 7: Case-insensitive LIKE (ILIKE for PostgreSQL)
echo "7. Case-insensitive LIKE operations...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::ilike('description', '%wireless%'))
    ->select(['name', 'description'])
    ->get();

echo "  Products with 'wireless' in description (case-insensitive):\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: {$row['description']}\n";
}
echo "\n";

// Example 8: Complex conditions
echo "8. Complex conditions...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::between('price', 50, 500))
    ->andWhere(Db::not(Db::in('category', ['Furniture'])))
    ->andWhere(Db::like('tags', '%computer%'))
    ->select(['name', 'price', 'category', 'tags'])
    ->get();

echo "  Complex filter: price \$50-\$500, NOT Furniture, tags contain 'computer':\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: \${$row['price']} ({$row['category']}) - {$row['tags']}\n";
}
echo "\n";

// Example 9: Multiple LIKE conditions
echo "9. Multiple LIKE conditions...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::like('name', '%Pro%'))
    ->orWhere(Db::like('description', '%high%'))
    ->select(['name', 'description'])
    ->get();

echo "  Products with 'Pro' in name OR 'high' in description:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: {$row['description']}\n";
}
echo "\n";

// Example 10: Rating-based filtering
echo "10. Rating-based filtering...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::between('rating', 4.0, 5.0))
    ->andWhere(Db::in('in_stock', [1, true]))
    ->select(['name', 'rating', 'in_stock'])
    ->orderBy('rating', 'DESC')
    ->get();

echo "  High-rated products (4.0+) that are in stock:\n";
foreach ($results as $row) {
    $stock = $row['in_stock'] ? 'Yes' : 'No';
    echo "  • {$row['name']}: {$row['rating']} stars (in stock: {$stock})\n";
}
echo "\n";

// Example 11: Tag-based filtering
echo "11. Tag-based filtering...\n";
$results = $db->find()
    ->from('products')
    ->where(Db::like('tags', '%programming%'))
    ->orWhere(Db::like('tags', '%computer%'))
    ->select(['name', 'tags'])
    ->get();

echo "  Products with 'programming' OR 'computer' tags:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: {$row['tags']}\n";
}
echo "\n";

// Example 12: Price range analysis
echo "12. Price range analysis...\n";
$priceRanges = [
    'Budget' => [0, 25],
    'Mid-range' => [25, 100],
    'Premium' => [100, 500],
    'Luxury' => [500, 9999]
];

foreach ($priceRanges as $range => $bounds) {
    $results = $db->find()
        ->from('products')
        ->where(Db::between('price', $bounds[0], $bounds[1]))
        ->select([Db::count()])
        ->getValue();
    
    echo "  {$range} (\${$bounds[0]}-\${$bounds[1]}): {$results} products\n";
}

echo "\nComparison helper functions example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use LIKE for pattern matching\n";
echo "  • Use ILIKE for case-insensitive pattern matching\n";
echo "  • Use BETWEEN for range queries\n";
echo "  • Use IN for multiple value matching\n";
echo "  • Use NOT to negate conditions\n";
echo "  • Combine conditions with AND/OR for complex filtering\n";
echo "  • Use comparison functions in WHERE, HAVING, and ORDER BY clauses\n";
