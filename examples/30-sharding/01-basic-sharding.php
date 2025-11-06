<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;

$driver = $_ENV['PDODB_DRIVER'] ?? 'sqlite';

// Create main database connection
$db = match ($driver) {
    'mysql' => new PdoDb('mysql', [
        'host' => $_ENV['MYSQL_HOST'] ?? 'localhost',
        'dbname' => $_ENV['MYSQL_DATABASE'] ?? 'test',
        'user' => $_ENV['MYSQL_USER'] ?? 'root',
        'password' => $_ENV['MYSQL_PASSWORD'] ?? '',
    ]),
    'mariadb' => new PdoDb('mariadb', [
        'host' => $_ENV['MARIADB_HOST'] ?? 'localhost',
        'dbname' => $_ENV['MARIADB_DATABASE'] ?? 'test',
        'user' => $_ENV['MARIADB_USER'] ?? 'root',
        'password' => $_ENV['MARIADB_PASSWORD'] ?? '',
    ]),
    'pgsql' => new PdoDb('pgsql', [
        'host' => $_ENV['POSTGRES_HOST'] ?? 'localhost',
        'dbname' => $_ENV['POSTGRES_DATABASE'] ?? 'test',
        'user' => $_ENV['POSTGRES_USER'] ?? 'postgres',
        'password' => $_ENV['POSTGRES_PASSWORD'] ?? '',
    ]),
    default => new PdoDb('sqlite', ['path' => ':memory:']),
};

// For SQLite, we'll use multiple in-memory databases to simulate sharding
if ($driver === 'sqlite') {
    // Create shard connections
    $db->addConnection('shard1', ['driver' => 'sqlite', 'path' => ':memory:']);
    $db->addConnection('shard2', ['driver' => 'sqlite', 'path' => ':memory:']);
    $db->addConnection('shard3', ['driver' => 'sqlite', 'path' => ':memory:']);

    // Create tables in each shard
    foreach (['shard1', 'shard2', 'shard3'] as $shard) {
        $db->connection($shard);
        $db->rawQuery('
            CREATE TABLE users (
                user_id INTEGER PRIMARY KEY,
                name TEXT,
                email TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    // Configure range-based sharding using existing connections
    $db->shard('users')
        ->shardKey('user_id')
        ->strategy('range')
        ->ranges([
            'shard1' => [0, 1000],
            'shard2' => [1001, 2000],
            'shard3' => [2001, 3000],
        ])
        ->useConnections(['shard1', 'shard2', 'shard3'])
        ->register();
}

echo "=== Basic Sharding Example ===\n\n";

// Insert data - automatically routed to appropriate shard
echo "Inserting users...\n";
$db->find()->table('users')->insert([
    'user_id' => 500,
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);

$db->find()->table('users')->insert([
    'user_id' => 1500,
    'name' => 'Bob',
    'email' => 'bob@example.com'
]);

$db->find()->table('users')->insert([
    'user_id' => 2500,
    'name' => 'Charlie',
    'email' => 'charlie@example.com'
]);

echo "Users inserted successfully!\n\n";

// Query data - automatically routed to appropriate shard
echo "Querying users by user_id:\n";
$user1 = $db->find()
    ->from('users')
    ->where('user_id', 500)
    ->getOne();

echo "User 500: {$user1['name']} ({$user1['email']})\n";

$user2 = $db->find()
    ->from('users')
    ->where('user_id', 1500)
    ->getOne();

echo "User 1500: {$user2['name']} ({$user2['email']})\n";

$user3 = $db->find()
    ->from('users')
    ->where('user_id', 2500)
    ->getOne();

echo "User 2500: {$user3['name']} ({$user3['email']})\n\n";

// Update data - automatically routed to appropriate shard
echo "Updating user 500...\n";
$affected = $db->find()
    ->table('users')
    ->where('user_id', 500)
    ->update(['name' => 'Alice Updated']);

echo "Updated {$affected} row(s)\n\n";

// Verify update
$updatedUser = $db->find()
    ->from('users')
    ->where('user_id', 500)
    ->getOne();

echo "Updated user: {$updatedUser['name']}\n\n";

// Delete data - automatically routed to appropriate shard
echo "Deleting user 2500...\n";
$deleted = $db->find()
    ->table('users')
    ->where('user_id', 2500)
    ->delete();

echo "Deleted {$deleted} row(s)\n\n";

echo "Sharding example completed successfully!\n";

