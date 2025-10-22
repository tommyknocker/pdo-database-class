<?php
/**
 * Example 02: Simple CRUD Operations (Multi-database compatible)
 * 
 * Demonstrates Create, Read, Update, Delete operations
 * Works with MySQL, PostgreSQL, and SQLite
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Get database from environment or default to SQLite
$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Simple CRUD Operations (on $driver) ===\n\n";

// Create table with proper syntax for current driver
recreateTable($db, 'users', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT NOT NULL',
    'email' => 'TEXT UNIQUE',
    'age' => 'INTEGER',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

echo "✓ Table 'users' created\n\n";

// CREATE - Insert single row
echo "1. CREATE - Inserting a new user...\n";
$userId = $db->find()->table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30
]);

echo "✓ User created with ID: $userId\n\n";

// READ - Get single row
echo "2. READ - Fetching user by ID...\n";
$user = $db->find()
    ->from('users')
    ->where('id', $userId)
    ->getOne();

echo "✓ Found user:\n";
echo "  Name: {$user['name']}\n";
echo "  Email: {$user['email']}\n";
echo "  Age: {$user['age']}\n\n";

// UPDATE - Modify existing row
echo "3. UPDATE - Updating user age...\n";
$affected = $db->find()
    ->table('users')
    ->where('id', $userId)
    ->update(['age' => 31]);

echo "✓ Updated $affected row(s)\n\n";

// Verify update
$user = $db->find()->from('users')->where('id', $userId)->getOne();
echo "  New age: {$user['age']}\n\n";

// DELETE - Remove row
echo "4. DELETE - Removing user...\n";
$deleted = $db->find()
    ->table('users')
    ->where('id', $userId)
    ->delete();

echo "✓ Deleted $deleted row(s)\n\n";

// Verify deletion
$count = $db->find()->from('users')->select(Db::raw('COUNT(*)'))->getValue();
echo "  Remaining users: $count\n\n";

echo "All CRUD operations completed successfully on $driver!\n";

