<?php
/**
 * Example 03: WHERE Conditions
 * 
 * Demonstrates various WHERE clause patterns and operators
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== WHERE Conditions Examples (on $driver) ===\n\n";

// Setup
$schema = $db->schema();
recreateTable($db, 'products', [
    'id' => $schema->primaryKey(),
    'name' => $schema->text(),
    'category' => $schema->text(),
    'price' => $schema->decimal(10, 2),
    'stock' => $schema->integer(),
    'active' => $schema->integer()->defaultValue(1),
    'threshold' => $schema->integer()->defaultValue(10),
]);

$products = [
    ['name' => 'Laptop', 'category' => 'Electronics', 'price' => 999.99, 'stock' => 15, 'active' => 1, 'threshold' => 20],
    ['name' => 'Mouse', 'category' => 'Electronics', 'price' => 29.99, 'stock' => 50, 'active' => 1, 'threshold' => 30],
    ['name' => 'Keyboard', 'category' => 'Electronics', 'price' => 79.99, 'stock' => 30, 'active' => 1, 'threshold' => 10],
    ['name' => 'Desk', 'category' => 'Furniture', 'price' => 299.99, 'stock' => 5, 'active' => 1, 'threshold' => 10],
    ['name' => 'Chair', 'category' => 'Furniture', 'price' => 199.99, 'stock' => 0, 'active' => 0, 'threshold' => 10],
];

$db->find()->table('products')->insertMulti($products);
echo "✓ Inserted " . count($products) . " products\n\n";

// Example 1: Simple equality
echo "1. Simple equality (=)...\n";
$electronics = $db->find()
    ->from('products')
    ->where('category', 'Electronics')
    ->get();
echo "  Found " . count($electronics) . " electronics\n\n";

// Example 2: Comparison operators
echo "2. Comparison operators (>, <, >=, <=)...\n";
$expensive = $db->find()
    ->from('products')
    ->where('price', 100, '>')
    ->get();
echo "  Found " . count($expensive) . " products over $100\n\n";

// Example 3: Multiple conditions (AND)
echo "3. Multiple AND conditions...\n";
$cheapElectronics = $db->find()
    ->from('products')
    ->where('category', 'Electronics')
    ->andWhere('price', 50, '<')
    ->get();
echo "  Found " . count($cheapElectronics) . " cheap electronics\n\n";

// Example 4: OR conditions
echo "4. OR conditions...\n";
$lowStockOrInactive = $db->find()
    ->from('products')
    ->where('stock', 10, '<')
    ->orWhere('active', 0)
    ->get();
echo "  Found " . count($lowStockOrInactive) . " products (low stock OR inactive)\n\n";

// Example 5: IN operator (with array)
echo "5. IN operator (with array)...\n";
$specific = $db->find()
    ->from('products')
    ->whereIn('name', ['Laptop', 'Mouse', 'Keyboard'])
    ->get();
echo "  Found " . count($specific) . " specific products\n\n";

// You can also use the helper function
$specific2 = $db->find()
    ->from('products')
    ->where(Db::in('name', ['Laptop', 'Mouse', 'Keyboard']))
    ->get();
echo "  (Helper function result: " . count($specific2) . " products)\n\n";

// Example 6: BETWEEN operator
echo "6. BETWEEN operator...\n";
$midRange = $db->find()
    ->from('products')
    ->whereBetween('price', 50, 300)
    ->get();
echo "  Found " . count($midRange) . " mid-range products ($50-$300)\n\n";

// You can also use the helper function
$midRange2 = $db->find()
    ->from('products')
    ->where(Db::between('price', 50, 300))
    ->get();
echo "  (Helper function result: " . count($midRange2) . " products)\n\n";

// Example 7: LIKE pattern matching
echo "7. LIKE pattern matching...\n";
$matching = $db->find()
    ->from('products')
    ->where(Db::like('name', '%top%'))
    ->get();
echo "  Found " . count($matching) . " products with 'top' in name\n\n";

// Example 8: IS NULL / IS NOT NULL
echo "8. IS NULL / IS NOT NULL...\n";
$hasStock = $db->find()
    ->from('products')
    ->whereNotNull('stock')
    ->andWhere('stock', 0, '>')
    ->get();
echo "  Found " . count($hasStock) . " products in stock\n\n";

// You can also use the helper function
$hasStock2 = $db->find()
    ->from('products')
    ->where(Db::isNotNull('stock'))
    ->andWhere('stock', 0, '>')
    ->get();
echo "  (Helper function result: " . count($hasStock2) . " products)\n\n";

// Example 9: NOT operator
echo "9. NOT operator...\n";
$notElectronics = $db->find()
    ->from('products')
    ->where(Db::not(Db::like('category', 'Electronics')))
    ->get();
echo "  Found " . count($notElectronics) . " non-electronics\n\n";

// Example 10: Complex conditions with raw SQL
echo "10. Complex conditions (using raw SQL)...\n";
$complex = $db->find()
    ->from('products')
    ->select(['name', 'price', 'stock'])
    ->where('active', 1)
    ->andWhere(Db::raw('(price < 100 OR stock > 40)'))
    ->get();

echo "  Found " . count($complex) . " products (active AND (cheap OR high stock)):\n";
foreach ($complex as $p) {
    echo "    • {$p['name']}: \${$p['price']} (stock: {$p['stock']})\n";
}

// Example 11: Enhanced WHERE methods (AND variants)
echo "\n11. Enhanced WHERE methods (AND variants)...\n";
$activeAndInStock = $db->find()
    ->from('products')
    ->where('active', 1)
    ->andWhereNotNull('stock')
    ->andWhereBetween('price', 50, 300)
    ->andWhereIn('category', ['Electronics', 'Furniture'])
    ->get();
echo "  Found " . count($activeAndInStock) . " active products with stock in price range\n\n";

// Example 12: Enhanced WHERE methods (OR variants)
echo "12. Enhanced WHERE methods (OR variants)...\n";
$mixedConditions = $db->find()
    ->from('products')
    ->where('active', 1)
    ->orWhereNull('stock')
    ->orWhereBetween('price', 200, 500)
    ->orWhereIn('name', ['Laptop', 'Desk'])
    ->get();
echo "  Found " . count($mixedConditions) . " products matching OR conditions\n\n";

// Example 13: Column comparison
echo "13. Column comparison...\n";
$needsRestock = $db->find()
    ->from('products')
    ->whereColumn('stock', '<=', 'threshold')
    ->get();
echo "  Found " . count($needsRestock) . " products that need restocking (stock <= threshold)\n\n";

$profitable = $db->find()
    ->from('products')
    ->whereColumn('price', '>', 'threshold')
    ->get();
echo "  Found " . count($profitable) . " products with price > threshold\n\n";

// Example 14: AND/OR with column comparison
echo "14. AND/OR with column comparison...\n";
$complex = $db->find()
    ->from('products')
    ->where('active', 1)
    ->andWhereColumn('stock', '>', 'threshold')
    ->get();
echo "  Found " . count($complex) . " active products with stock > threshold\n\n";

echo "\nAll WHERE examples completed!\n";

