<?php
/**
 * Example: Advanced DDL Query Builder (Yii2-style)
 *
 * Demonstrates advanced schema builder features including constraints,
 * partial indexes, fulltext indexes, and other production-ready features.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Advanced DDL Query Builder (Yii2-style) (on $driver) ===\n\n";

// Get DDL Query Builder
$schema = $db->schema();

// Drop tables if exist (cleanup)
$schema->dropTableIfExists('products');
$schema->dropTableIfExists('categories');
$schema->dropTableIfExists('orders');

// Example 1: Create table with constraints
echo "1. Creating table with constraints:\n";
$schema->createTable('categories', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(255)->notNull(),
    'slug' => $schema->string(255)->notNull(),
    'status' => $schema->integer()->defaultValue(1),
]);

// Add unique constraint (Yii2-style)
$schema->addUnique('uq_categories_slug', 'categories', 'slug');
echo "   ✓ Table 'categories' created with unique constraint\n";

// Example 2: Create table with CHECK constraint
echo "\n2. Creating table with CHECK constraint:\n";
$schema->createTable('products', [
    'id' => $schema->primaryKey(),
    'category_id' => $schema->integer()->notNull(),
    'name' => $schema->string(255)->notNull(),
    'price' => $schema->decimal(10, 2)->notNull(),
    'stock' => $schema->integer()->defaultValue(0),
]);

// Add CHECK constraint (Yii2-style)
try {
    $schema->addCheck('chk_products_price', 'products', 'price > 0');
    echo "   ✓ CHECK constraint added (price > 0)\n";
} catch (\Exception $e) {
    echo "   ⊘ CHECK constraint not supported: " . $e->getMessage() . "\n";
}

// Example 3: Create indexes with sorting
echo "\n3. Creating indexes with sorting:\n";
$schema->createIndex('idx_products_category', 'products', 'category_id');
$schema->createIndex('idx_products_name_price', 'products', [
    'name' => 'ASC',
    'price' => 'DESC',
]);
echo "   ✓ Indexes created with sorting\n";

// Example 4: Create partial index (WHERE clause)
echo "\n4. Creating partial index:\n";
try {
    $schema->createIndex('idx_products_active', 'products', 'name', false, 'status = 1');
    echo "   ✓ Partial index created (WHERE status = 1)\n";
} catch (\Exception $e) {
    echo "   ⊘ Partial indexes not supported: " . $e->getMessage() . "\n";
}

// Example 5: Create index with INCLUDE columns (PostgreSQL/MSSQL)
echo "\n5. Creating index with INCLUDE columns:\n";
try {
    $schema->createIndex('idx_products_search', 'products', 'name', false, null, ['price', 'stock']);
    echo "   ✓ Index with INCLUDE columns created\n";
} catch (\Exception $e) {
    echo "   ⊘ INCLUDE columns not supported on this database: " . $e->getMessage() . "\n";
}

// Example 6: Create functional index (on expression)
echo "\n6. Creating functional index:\n";
try {
    $schema->createIndex('idx_products_lower_name', 'products', [Db::raw('LOWER(name)')]);
    echo "   ✓ Functional index created (LOWER(name))\n";
} catch (\Exception $e) {
    echo "   ⊘ Functional indexes not supported: " . $e->getMessage() . "\n";
}

// Example 7: Create fulltext index
echo "\n7. Creating fulltext index:\n";
try {
    $schema->createFulltextIndex('ft_idx_products_search', 'products', ['name']);
    echo "   ✓ Fulltext index created\n";
} catch (\Exception $e) {
    echo "   ⊘ Fulltext indexes not supported: " . $e->getMessage() . "\n";
}

// Example 8: Check if constraints exist
echo "\n8. Checking constraint existence:\n";
if ($schema->uniqueExists('uq_categories_slug', 'categories')) {
    echo "   ✓ Unique constraint 'uq_categories_slug' exists\n";
}
if ($schema->checkExists('chk_products_price', 'products')) {
    echo "   ✓ CHECK constraint 'chk_products_price' exists\n";
}
if ($schema->indexExists('idx_products_category', 'products')) {
    echo "   ✓ Index 'idx_products_category' exists\n";
}

// Example 9: Get lists of constraints and indexes
echo "\n9. Getting lists of constraints and indexes:\n";
$indexes = $schema->getIndexes('products');
echo "   ✓ Found " . count($indexes) . " indexes on 'products'\n";

$uniqueConstraints = $schema->getUniqueConstraints('categories');
echo "   ✓ Found " . count($uniqueConstraints) . " unique constraints on 'categories'\n";

// Example 10: Rename index
echo "\n10. Renaming index:\n";
try {
    $schema->renameIndex('idx_products_category', 'products', 'idx_products_category_id');
    echo "   ✓ Index renamed from 'idx_products_category' to 'idx_products_category_id'\n";
    // Rename back
    $schema->renameIndex('idx_products_category_id', 'products', 'idx_products_category');
    echo "   ✓ Index renamed back\n";
} catch (\Exception $e) {
    echo "   ⊘ Renaming indexes not supported: " . $e->getMessage() . "\n";
}

// Example 11: Add primary key constraint separately
echo "\n11. Adding primary key constraint:\n";
$schema->dropTableIfExists('orders');
$schema->createTable('orders', [
    'order_id' => $schema->integer()->notNull(),
    'product_id' => $schema->integer()->notNull(),
    'quantity' => $schema->integer()->notNull(),
]);

try {
    $schema->addPrimaryKey('pk_orders', 'orders', ['order_id', 'product_id']);
    echo "   ✓ Composite primary key added\n";
} catch (\Exception $e) {
    echo "   ⊘ Adding primary key failed: " . $e->getMessage() . "\n";
}

// Cleanup
$schema->dropTableIfExists('orders');
$schema->dropTableIfExists('products');
$schema->dropTableIfExists('categories');

echo "\n=== All advanced DDL operations completed! ===\n";

