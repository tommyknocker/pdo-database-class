<?php
/**
 * Example: Database Migrations Basics
 *
 * Demonstrates how to use the migration system for version-controlled
 * database schema changes.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Database Migrations Basics (on $driver) ===\n\n";

// Create migrations directory if it doesn't exist
$migrationPath = __DIR__ . '/migrations';
if (!is_dir($migrationPath)) {
    mkdir($migrationPath, 0755, true);
}

// Cleanup previous migrations
$db->rawQuery("DROP TABLE IF EXISTS __migrations");
$db->rawQuery("DROP TABLE IF EXISTS test_users");
$db->rawQuery("DROP TABLE IF EXISTS test_posts");

// Remove old migration files
$oldFiles = glob($migrationPath . '/m*.php');
foreach ($oldFiles as $file) {
    unlink($file);
}

// Create MigrationRunner
$runner = new MigrationRunner($db, $migrationPath);

echo "1. Creating a new migration:\n";
$filename = $runner->create('create_users_table');
echo "   ✓ Migration file created: " . basename($filename) . "\n";

// Read and modify the migration file
$content = file_get_contents($filename);
$content = str_replace(
    '// TODO: Implement migration up logic',
    "\$this->schema()->createTable('test_users', [
        'id' => \$this->schema()->primaryKey(),
        'username' => \$this->schema()->string(100)->notNull(),
        'email' => \$this->schema()->string(255)->notNull(),
    ]);",
    $content
);
$content = str_replace(
    '// TODO: Implement migration down logic',
    "\$this->schema()->dropTable('test_users');",
    $content
);
file_put_contents($filename, $content);

echo "\n2. Checking for new migrations:\n";
$newMigrations = $runner->getNewMigrations();
echo "   Found " . count($newMigrations) . " new migration(s)\n";
foreach ($newMigrations as $version) {
    echo "   - {$version}\n";
}

echo "\n3. Applying migrations:\n";
$applied = $runner->migrate();
echo "   Applied " . count($applied) . " migration(s):\n";
foreach ($applied as $version) {
    echo "   ✓ {$version}\n";
}

// Verify table was created
if ($db->find()->table('test_users')->tableExists()) {
    echo "\n   ✓ Table 'test_users' created successfully\n";
}

echo "\n4. Checking migration history:\n";
$history = $runner->getMigrationHistory();
echo "   Migration history:\n";
foreach ($history as $record) {
    echo "   - {$record['version']} (batch: {$record['batch']})\n";
}

echo "\n5. Creating another migration:\n";
$filename2 = $runner->create('add_posts_table');
$content2 = file_get_contents($filename2);
$content2 = str_replace(
    '// TODO: Implement migration up logic',
    "\$this->schema()->createTable('test_posts', [
        'id' => \$this->schema()->primaryKey(),
        'user_id' => \$this->schema()->integer()->notNull(),
        'title' => \$this->schema()->string(255)->notNull(),
        'content' => \$this->schema()->text(),
    ]);",
    $content2
);
$content2 = str_replace(
    '// TODO: Implement migration down logic',
    "\$this->schema()->dropTable('test_posts');",
    $content2
);
file_put_contents($filename2, $content2);

echo "   ✓ Migration file created: " . basename($filename2) . "\n";

echo "\n6. Applying new migrations:\n";
$applied2 = $runner->migrate();
foreach ($applied2 as $version) {
    echo "   ✓ {$version}\n";
}

if ($db->find()->table('test_posts')->tableExists()) {
    echo "\n   ✓ Table 'test_posts' created successfully\n";
}

echo "\n7. Rolling back last migration:\n";
$rolledBack = $runner->migrateDown(1);
foreach ($rolledBack as $version) {
    echo "   ✓ Rolled back: {$version}\n";
}

if (!$db->find()->table('test_posts')->tableExists()) {
    echo "\n   ✓ Table 'test_posts' removed (rollback successful)\n";
}

// Cleanup
$db->rawQuery("DROP TABLE IF EXISTS test_users");
$db->rawQuery("DROP TABLE IF EXISTS __migrations");

// Remove migration files
foreach (glob($migrationPath . '/m*.php') as $file) {
    unlink($file);
}
if (is_dir($migrationPath)) {
    rmdir($migrationPath);
}

echo "\n=== Migration operations completed successfully! ===\n";

