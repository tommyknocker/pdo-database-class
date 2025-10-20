<?php
/**
 * Example: Connection Pooling
 * 
 * Demonstrates managing multiple database connections
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

echo "=== Connection Pooling Example ===\n\n";

// Initialize without default connection
$db = new PdoDb();

echo "1. Setting up multiple connections...\n";

// Add first connection - user database
$db->addConnection('users_db', [
    'driver' => 'sqlite',
    'path' => ':memory:'
]);

// Add second connection - analytics database
$db->addConnection('analytics_db', [
    'driver' => 'sqlite',
    'path' => ':memory:'
]);

// Add third connection - logs database
$db->addConnection('logs_db', [
    'driver' => 'sqlite',
    'path' => ':memory:'
]);

echo "✓ Added 3 connections: users_db, analytics_db, logs_db\n\n";

// Setup users database
echo "2. Setting up users database...\n";
$db->connection('users_db');
$db->rawQuery("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT,
        email TEXT
    )
");

$db->find()->table('users')->insertMulti([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com'],
]);

echo "✓ Created users table and inserted 2 users\n\n";

// Setup analytics database
echo "3. Setting up analytics database...\n";
$db->connection('analytics_db');
$db->rawQuery("
    CREATE TABLE events (
        id INTEGER PRIMARY KEY,
        user_id INTEGER,
        event_type TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->find()->table('events')->insertMulti([
    ['user_id' => 1, 'event_type' => 'login'],
    ['user_id' => 1, 'event_type' => 'page_view'],
    ['user_id' => 2, 'event_type' => 'login'],
    ['user_id' => 1, 'event_type' => 'purchase'],
]);

echo "✓ Created events table and inserted 4 events\n\n";

// Setup logs database
echo "4. Setting up logs database...\n";
$db->connection('logs_db');
$db->rawQuery("
    CREATE TABLE app_logs (
        id INTEGER PRIMARY KEY,
        level TEXT,
        message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->find()->table('app_logs')->insert([
    'level' => 'INFO',
    'message' => 'Application started'
]);

echo "✓ Created logs table\n\n";

// Example 5: Query across different connections
echo "5. Querying different databases...\n\n";

// Get users
$db->connection('users_db');
$users = $db->find()->from('users')->get();
echo "  Users database:\n";
foreach ($users as $user) {
    echo "    • {$user['name']} ({$user['email']})\n";
}
echo "\n";

// Get analytics
$db->connection('analytics_db');
$stats = $db->find()
    ->from('events')
    ->select([
        'user_id',
        'event_count' => Db::count()
    ])
    ->groupBy('user_id')
    ->get();

echo "  Analytics database:\n";
foreach ($stats as $stat) {
    echo "    • User {$stat['user_id']}: {$stat['event_count']} events\n";
}
echo "\n";

// Get logs
$db->connection('logs_db');
$logs = $db->find()->from('app_logs')->get();
echo "  Logs database:\n";
foreach ($logs as $log) {
    echo "    • [{$log['level']}] {$log['message']}\n";
}
echo "\n";

// Example 6: Simulating microservices pattern
echo "6. Microservices pattern simulation...\n";

function getUserData($db, $userId) {
    $db->connection('users_db');
    return $db->find()->from('users')->where('id', $userId)->getOne();
}

function getUserEvents($db, $userId) {
    $db->connection('analytics_db');
    return $db->find()
        ->from('events')
        ->where('user_id', $userId)
        ->get();
}

function logActivity($db, $message) {
    $db->connection('logs_db');
    return $db->find()->table('app_logs')->insert([
        'level' => 'INFO',
        'message' => $message
    ]);
}

$userId = 1;
$user = getUserData($db, $userId);
$events = getUserEvents($db, $userId);
logActivity($db, "Fetched data for user $userId");

echo "  ✓ User: {$user['name']}\n";
echo "  ✓ Events: " . count($events) . "\n";
echo "  ✓ Activity logged\n\n";

// Example 7: Connection switching performance
echo "7. Rapid connection switching...\n";
$start = microtime(true);

for ($i = 0; $i < 10; $i++) {
    $db->connection('users_db');
    $db->connection('analytics_db');
    $db->connection('logs_db');
}

$elapsed = round((microtime(true) - $start) * 1000, 2);
echo "  ✓ 30 connection switches completed in {$elapsed}ms\n";
echo "  (Connection pooling is fast!)\n\n";

echo "Connection pooling example completed!\n";

