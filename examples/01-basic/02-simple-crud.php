<?php
/**
 * Example 02: Simple CRUD Operations
 * 
 * Demonstrates Create, Read, Update, Delete operations
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Using SQLite in-memory for simplicity
$db = new PdoDb('sqlite', ['path' => ':memory:']);

echo "=== Simple CRUD Operations ===\n\n";

// Create table
$db->rawQuery("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE,
        age INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

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

// READ - Get all rows
echo "3. READ - Fetching all users...\n";
$allUsers = $db->find()->from('users')->get();
echo "✓ Found " . count($allUsers) . " user(s)\n\n";

// UPDATE - Modify existing row
echo "4. UPDATE - Updating user's age...\n";
$affected = $db->find()
    ->table('users')
    ->where('id', $userId)
    ->update(['age' => 31]);

echo "✓ Updated $affected row(s)\n";

// Verify update
$user = $db->find()->from('users')->where('id', $userId)->getOne();
echo "  New age: {$user['age']}\n\n";

// DELETE - Remove row
echo "5. DELETE - Removing user...\n";
$deleted = $db->find()
    ->table('users')
    ->where('id', $userId)
    ->delete();

echo "✓ Deleted $deleted row(s)\n";

// Verify deletion
$count = $db->rawQueryValue('SELECT COUNT(*) FROM users');
echo "  Remaining users: $count\n\n";

// Bonus: Insert multiple rows at once
echo "6. BONUS - Inserting multiple users...\n";
$users = [
    ['name' => 'Alice Smith', 'email' => 'alice@example.com', 'age' => 25],
    ['name' => 'Bob Johnson', 'email' => 'bob@example.com', 'age' => 35],
    ['name' => 'Carol White', 'email' => 'carol@example.com', 'age' => 28],
];

$inserted = $db->find()->table('users')->insertMulti($users);
echo "✓ Inserted $inserted users\n";

$allUsers = $db->find()->from('users')->select(['name', 'age'])->get();
foreach ($allUsers as $user) {
    echo "  • {$user['name']} (age {$user['age']})\n";
}

echo "\nAll CRUD operations completed!\n";

