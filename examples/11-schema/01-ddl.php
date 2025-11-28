<?php
/**
 * Example: DDL Query Builder Basics
 *
 * Demonstrates how to use DDL Query Builder for creating and managing
 * database schema operations without writing raw SQL.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== DDL Query Builder Basics (on $driver) ===\n\n";

// Get DDL Query Builder
$schema = $db->schema();

// Drop table if exists (cleanup)
// For MySQL/MariaDB, disable foreign key checks to avoid constraint violations
if ($driver === 'mysql' || $driver === 'mariadb') {
    $db->rawQuery("SET FOREIGN_KEY_CHECKS=0");
}
$schema->dropTableIfExists('users');
if ($driver === 'mysql' || $driver === 'mariadb') {
    $db->rawQuery("SET FOREIGN_KEY_CHECKS=1");
}

// Example 1: Create table with ColumnSchema fluent API
echo "1. Creating table with ColumnSchema fluent API:\n";
$schema->createTable('users', [
    'id' => $schema->primaryKey(),
    'username' => $schema->string(100)->notNull(),
    'email' => $schema->string(255)->notNull(),
    'password_hash' => $schema->string(255)->notNull(),
    'status' => $schema->integer()->defaultValue(1),
    'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
    'updated_at' => $schema->timestamp(),
]);

echo "   ✓ Table 'users' created\n";

// Verify table exists
if ($schema->tableExists('users')) {
    echo "   ✓ Table exists confirmed\n";
}

// Example 2: Create table with array definitions
echo "\n2. Creating table with array definitions:\n";
$schema->dropTableIfExists('posts');
$schema->createTable('posts', [
    'id' => ['type' => 'INT', 'autoIncrement' => true, 'null' => false],
    'user_id' => ['type' => 'INT', 'null' => false],
    'title' => ['type' => 'VARCHAR', 'length' => 255, 'null' => false],
    'content' => ['type' => 'TEXT'],
    'published' => ['type' => 'BOOLEAN', 'default' => false],
]);

echo "   ✓ Table 'posts' created\n";

// Example 3: Create indexes
echo "\n3. Creating indexes:\n";
$schema->createIndex('idx_users_email', 'users', 'email', true); // unique
$schema->createIndex('idx_posts_user_id', 'posts', 'user_id');
$schema->createIndex('idx_posts_title', 'posts', 'title');

echo "   ✓ Indexes created\n";

// Example 4: Add column
echo "\n4. Adding column to existing table:\n";
$schema->addColumn('users', 'phone', $schema->string(20));
echo "   ✓ Column 'phone' added to 'users' table\n";

// Example 5: Rename column
echo "\n5. Renaming column:\n";
$schema->renameColumn('users', 'phone', 'phone_number');
echo "   ✓ Column 'phone' renamed to 'phone_number'\n";

// Example 6: Drop column
echo "\n6. Dropping column:\n";
// Column exists because we just renamed it above
$schema->dropColumn('users', 'phone_number');
echo "   ✓ Column 'phone_number' dropped\n";

// Example 7: Rename table
echo "\n7. Renaming table:\n";
$schema->dropTableIfExists('articles'); // Cleanup in case of previous run
$schema->renameTable('posts', 'articles');
echo "   ✓ Table 'posts' renamed to 'articles'\n";

// Example 8: Drop table
echo "\n8. Dropping table:\n";
$schema->dropTable('articles');
echo "   ✓ Table 'articles' dropped\n";

// Example 9: Truncate table
echo "\n9. Truncating table:\n";
$db->find()->table('users')->insert([
    'username' => 'test',
    'email' => 'test@example.com',
    'password_hash' => 'hash',
]);
echo "   ✓ Inserted test row\n";

$schema->truncateTable('users');
$count = $db->find()->from('users')->select(['count' => Db::count()])->getValue('count');
echo "   ✓ Table truncated (row count: {$count})\n";

// Cleanup
$schema->dropTable('users');

echo "\n=== All DDL operations completed successfully! ===\n";

