<?php

declare(strict_types=1);

/**
 * Example: Database Dump and Restore
 *
 * This example demonstrates how to use the CLI dump and restore commands
 * to export and import database schema and data.
 */

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;

if (PHP_SAPI !== 'cli') {
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

// Setup database connection
// For SQLite, use a temporary file instead of :memory: so CLI commands can access the same database
$driver = mb_strtolower(getenv('PDODB_DRIVER') ?: 'sqlite', 'UTF-8');
$dbPath = null;
if ($driver === 'sqlite') {
    // Use a temporary file for SQLite so CLI commands can access the same database
    $dbPath = sys_get_temp_dir() . '/pdodb_dump_example_' . uniqid() . '.sqlite';
    $db = new PdoDb('sqlite', ['path' => $dbPath]);
    // Ensure CLI commands use the same SQLite database file
    putenv('PDODB_PATH=' . $dbPath);
} else {
    $db = createExampleDb();
}
$driver = $db->connection?->getDriverName() ?? 'sqlite';
$schema = $db->schema();

// Set driver and non-interactive mode for CLI commands
// This ensures BaseCliCommand loads config from examples/config.{driver}.php or environment variables
putenv('PDODB_DRIVER=' . $driver);
putenv('PDODB_NON_INTERACTIVE=1');

// Create CLI application
$app = new Application();
$run = static function (array $argv) use ($app): string {
    ob_start();
    $app->run($argv);
    return ob_get_clean();
};

// Create sample tables and data
echo "Creating sample database...\n";

// Drop tables if they exist using DDL API
$schema->dropTableIfExists('posts');
$schema->dropTableIfExists('users');

// Create users table using Schema Builder
$schema->createTable('users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100)->notNull(),
    'email' => $schema->string(255)->unique(),
    'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
]);

// Create posts table using Schema Builder with foreign key
// For SQLite, foreign key must be in CREATE TABLE, so we use a workaround
if ($driver === 'sqlite') {
    // SQLite requires foreign key in CREATE TABLE
    $schema->createTable('posts', [
        'id' => $schema->primaryKey(),
        'user_id' => $schema->integer()->notNull(),
        'title' => $schema->string(255)->notNull(),
        'content' => $schema->text(),
    ], [
        'foreignKeys' => [
            [
                'name' => 'fk_posts_user_id',
                'columns' => ['user_id'],
                'refTable' => 'users',
                'refColumns' => ['id'],
                'onDelete' => 'CASCADE',
                'onUpdate' => 'CASCADE',
            ],
        ],
    ]);
} else {
    // For other databases, create table first, then add foreign key
    $schema->createTable('posts', [
        'id' => $schema->primaryKey(),
        'user_id' => $schema->integer()->notNull(),
        'title' => $schema->string(255)->notNull(),
        'content' => $schema->text(),
    ]);
    $schema->addForeignKey('fk_posts_user_id', 'posts', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE');
}

// Add index
$schema->createIndex('idx_user_id', 'posts', 'user_id');

// Insert sample data using Query Builder
$db->find()->table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

$db->find()->table('users')->insert([
    'name' => 'Jane Smith',
    'email' => 'jane@example.com',
]);

$db->find()->table('posts')->insert([
    'user_id' => 1,
    'title' => 'First Post',
    'content' => 'Content of first post',
]);

$db->find()->table('posts')->insert([
    'user_id' => 1,
    'title' => 'Second Post',
    'content' => 'Content of second post',
]);

$db->find()->table('posts')->insert([
    'user_id' => 2,
    'title' => 'Third Post',
    'content' => 'Content of third post',
]);

echo "Sample database created with tables and data.\n\n";

// Full dump of our test tables using CLI command
$usersDumpFile = sys_get_temp_dir() . '/users_dump_' . uniqid() . '.sql';
$postsDumpFile = sys_get_temp_dir() . '/posts_dump_' . uniqid() . '.sql';

echo "=== Full Database Dump (test tables) ===\n";
echo ">> pdodb dump users --output={$usersDumpFile}\n";
$usersDump = $run(['pdodb', 'dump', 'users', '--output=' . $usersDumpFile]);
echo $usersDump;

echo ">> pdodb dump posts --output={$postsDumpFile}\n";
$postsDump = $run(['pdodb', 'dump', 'posts', '--output=' . $postsDumpFile]);
echo $postsDump;

// Combine dumps
$fullDump = file_get_contents($usersDumpFile) . "\n" . file_get_contents($postsDumpFile);
echo substr($fullDump, 0, 500) . "...\n\n";

// Schema only dump
echo "=== Schema Only Dump ===\n";
echo ">> pdodb dump users --schema-only\n";
$schemaDump = $run(['pdodb', 'dump', 'users', '--schema-only']);
echo substr($schemaDump, 0, 300) . "...\n\n";

// Data only dump
echo "=== Data Only Dump ===\n";
echo ">> pdodb dump users --data-only\n";
$dataDump = $run(['pdodb', 'dump', 'users', '--data-only']);
echo substr($dataDump, 0, 300) . "...\n\n";

// Dump specific table
echo "=== Dump Single Table (users) ===\n";
echo ">> pdodb dump users\n";
$tableDump = $run(['pdodb', 'dump', 'users']);
echo substr($tableDump, 0, 200) . "...\n\n";

// Restore from dump using CLI command
echo "=== Restore from Dump ===\n";
$dumpFile = sys_get_temp_dir() . '/pdodb_restore_example_' . uniqid() . '.sql';
file_put_contents($dumpFile, $fullDump);

// Drop tables before restore using CLI commands to ensure same connection
// Drop in correct order (posts first due to foreign key, then users)
$run(['pdodb', 'table', 'drop', 'posts', '--if-exists', '--force']);
$run(['pdodb', 'table', 'drop', 'users', '--if-exists', '--force']);

echo ">> pdodb dump restore {$dumpFile} --force\n";
$restoreOutput = $run(['pdodb', 'dump', 'restore', $dumpFile, '--force']);
echo $restoreOutput;

// Verify restored data using Query Builder
$users = $db->find()->from('users')->get();
echo "Restored users: " . count($users) . "\n";

$posts = $db->find()->from('posts')->get();
echo "Restored posts: " . count($posts) . "\n\n";

// Cleanup
if ($driver === 'sqlite' && $dbPath && $dbPath !== ':memory:' && file_exists($dbPath)) {
    @unlink($dbPath);
}
@unlink($dumpFile);
@unlink($usersDumpFile);
@unlink($postsDumpFile);

echo "Example completed successfully!\n";

