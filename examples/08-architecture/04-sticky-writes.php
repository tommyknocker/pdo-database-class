<?php

/**
 * Sticky Writes Example
 *
 * Demonstrates sticky writes functionality for read-after-write consistency.
 * When enabled, reads go to master for a specified duration after a write.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;

$driverEnv = getenv('PDODB_DRIVER') ?: 'mysql';
$config = getExampleConfig();
// Use driver from config (converts mssql to sqlsrv)
$driver = $config['driver'] ?? $driverEnv;

// For SQLite, use a temporary file instead of :memory: for read/write splitting
if ($driver === 'sqlite') {
    $config['path'] = sys_get_temp_dir() . '/pdodb_rw_test_' . uniqid() . '.db';
}

echo "=== Read/Write Splitting - Sticky Writes ===\n\n";

// ========================================
// 1. Setup
// ========================================
echo "1. Setting up read/write splitting with sticky writes\n";

// Create initial database instance
$db = new PdoDb($driver, $config);
$db->enableReadWriteSplitting(new RoundRobinLoadBalancer());

// Add connections
$writeConfig = $config;
$writeConfig['driver'] = $driver;
$writeConfig['type'] = 'write';
$db->addConnection('write', $writeConfig);

$readConfig = $config;
$readConfig['driver'] = $driver;
$readConfig['type'] = 'read';
$db->addConnection('read-1', $readConfig);

// Enable sticky writes for 3 seconds
$db->enableStickyWrites(3);

echo "✓ Sticky writes enabled (3 seconds)\n\n";

// ========================================
// 2. Create Test Table
// ========================================
echo "2. Creating test table\n";

$db->rawQuery('DROP TABLE IF EXISTS posts_rw');

if ($driver === 'sqlite') {
    $db->rawQuery('
        CREATE TABLE posts_rw (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            views INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
} elseif ($driver === 'pgsql') {
    $db->rawQuery('
        CREATE TABLE posts_rw (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            views INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
} elseif ($driver === 'sqlsrv') {
    $db->rawQuery('
        CREATE TABLE posts_rw (
            id INT IDENTITY(1,1) PRIMARY KEY,
            title NVARCHAR(255) NOT NULL,
            content NTEXT NOT NULL,
            views INT DEFAULT 0,
            created_at DATETIME DEFAULT GETDATE()
        )
    ');
} else {
    $db->rawQuery('
        CREATE TABLE posts_rw (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            views INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

echo "✓ Table created\n\n";

// ========================================
// 3. Demonstrate Sticky Writes
// ========================================
echo "3. Testing sticky writes behavior\n";

// Insert a post (write operation)
$postId = $db->find()
    ->table('posts_rw')
    ->insert([
        'title' => 'First Post',
        'content' => 'This is my first post!',
    ]);

echo "✓ INSERT (goes to master): Post ID = $postId\n";

// Read immediately after write
// With sticky writes, this SELECT will go to master (not replica)
// This ensures read-your-own-writes consistency
$post = $db->find()
    ->from('posts_rw')
    ->where('id', $postId)
    ->getOne();

echo "✓ SELECT immediately after write (goes to master due to sticky writes)\n";
echo "  Post: {$post['title']}\n\n";

// Update the post (another write)
$db->find()
    ->table('posts_rw')
    ->where('id', $postId)
    ->update(['views' => 100]);

echo "✓ UPDATE (goes to master)\n";

// Read again - still within sticky writes window
$post = $db->find()
    ->from('posts_rw')
    ->where('id', $postId)
    ->getOne();

echo "✓ SELECT within sticky window (goes to master)\n";
echo "  Views: {$post['views']}\n\n";

// Wait for sticky writes to expire
echo "Waiting for sticky writes to expire (3 seconds)...\n";
sleep(4);

// Now reads will go to replica again
$post = $db->find()
    ->from('posts_rw')
    ->where('id', $postId)
    ->getOne();

echo "✓ SELECT after sticky window expired (goes to replica)\n";
echo "  Post: {$post['title']}\n\n";

// ========================================
// 4. Disable Sticky Writes
// ========================================
echo "4. Disabling sticky writes\n";

$db->disableStickyWrites();

$router = $db->getConnectionRouter();
echo "Sticky writes enabled: " . ($router->isStickyWritesEnabled() ? 'Yes' : 'No') . "\n\n";

// ========================================
// 5. Transactions Always Use Master
// ========================================
echo "5. Testing transactions (always use master)\n";

$db->transaction(function ($db) use ($postId) {
    // All queries in transaction go to master, even SELECTs
    $post = $db->find()
        ->from('posts_rw')
        ->where('id', $postId)
        ->getOne();
    
    echo "  ✓ SELECT in transaction (goes to master): {$post['title']}\n";
    
    $db->find()
        ->table('posts_rw')
        ->where('id', $postId)
        ->update(['views' => 200]);
    
    echo "  ✓ UPDATE in transaction: Views set to 200\n";
});

echo "✓ Transaction completed\n\n";

// ========================================
// 6. Cleanup
// ========================================
echo "6. Cleanup\n";
$db->rawQuery('DROP TABLE IF EXISTS posts_rw');
echo "✓ Test table dropped\n";

// Clean up SQLite temp file
if ($driver === 'sqlite' && isset($config['path']) && file_exists($config['path'])) {
    unlink($config['path']);
    echo "✓ Temporary SQLite file removed\n";
}

echo "\n=== Example completed successfully ===\n";

