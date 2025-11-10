<?php
/**
 * Example: MERGE Statement Operations
 *
 * Demonstrates MERGE statement (INSERT/UPDATE based on match conditions) across different databases
 * PostgreSQL: Native MERGE support
 * MySQL: Emulated via INSERT ... ON DUPLICATE KEY UPDATE
 * SQLite: Emulated via INSERT OR REPLACE
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== MERGE Statement Operations Example (on $driver) ===\n\n";

// Setup target and source tables using fluent API (cross-dialect)
$schema = $db->schema();
recreateTable($db, 'products', [
    'id' => $schema->integer()->notNull(),
    'name' => $schema->string(100),
    'price' => $schema->decimal(10, 2),
    'stock' => $schema->integer()->defaultValue(0),
    'updated_at' => $schema->datetime()->defaultExpression('CURRENT_TIMESTAMP'),
], ['primaryKey' => ['id']]);

recreateTable($db, 'product_updates', [
    'id' => $schema->integer(),
    'name' => $schema->string(100),
    'price' => $schema->decimal(10, 2),
    'stock' => $schema->integer(),
]);

echo "✓ Tables created\n\n";

// Insert initial data into products
echo "1. Inserting initial products...\n";
$db->find()->table('products')->insert(['id' => 1, 'name' => 'Product A', 'price' => 10.00, 'stock' => 100]);
$db->find()->table('products')->insert(['id' => 3, 'name' => 'Product C', 'price' => 30.00, 'stock' => 300]);
echo "  ✓ Products inserted: id=1, id=3\n\n";

// Insert source data
echo "2. Inserting source data for MERGE...\n";
$db->find()->table('product_updates')->insert(['id' => 1, 'name' => 'Product A Updated', 'price' => 12.00, 'stock' => 150]);
$db->find()->table('product_updates')->insert(['id' => 2, 'name' => 'Product B New', 'price' => 20.00, 'stock' => 200]);
echo "  ✓ Source data inserted: id=1 (update), id=2 (new)\n\n";

// Example 1: Basic MERGE with table source
echo "3. Performing MERGE operation...\n";
$affected = $db->find()
    ->table('products')
    ->merge(
        'product_updates',
        'target.id = source.id',
        [
            'name' => Db::raw('source.name'),
            'price' => Db::raw('source.price'),
            'stock' => Db::raw('source.stock')
        ],
        [
            'id' => 999,
            'name' => 'Placeholder',
            'price' => 0,
            'stock' => 0
        ]
    );

echo "  ✓ MERGE completed: {$affected} row(s) affected\n\n";

// Verify results
echo "4. Verifying MERGE results...\n";
$product1 = $db->find()->from('products')->where('id', 1)->getOne();
echo "  ✓ Product 1 (updated): name='{$product1['name']}', price={$product1['price']}, stock={$product1['stock']}\n";

$product2 = $db->find()->from('products')->where('id', 2)->getOne();
if ($product2) {
    echo "  ✓ Product 2 (inserted): name='{$product2['name']}', price={$product2['price']}, stock={$product2['stock']}\n";
}

$product3 = $db->find()->from('products')->where('id', 3)->getOne();
if ($product3) {
    echo "  ✓ Product 3 (unchanged): name='{$product3['name']}', price={$product3['price']}\n";
}

echo "\n=== MERGE Example Complete ===\n";

