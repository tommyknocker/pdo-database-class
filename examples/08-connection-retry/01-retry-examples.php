<?php
/**
 * Connection Retry Example
 * 
 * Demonstrates how to use the connection retry functionality
 * to handle temporary database connection issues automatically.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

echo "=== Connection Retry Example ===\n\n";

// Example 1: Basic retry configuration
echo "1. Basic Retry Configuration:\n";
$db = new PdoDb('sqlite', [
    'path' => ':memory:',
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 1000,
        'backoff_multiplier' => 2,
        'max_delay_ms' => 10000,
        'retryable_errors' => [
            2002, 2003, 2006,  // MySQL connection errors
            '57P01', '57P03',  // PostgreSQL connection errors
        ]
    ]
]);

// Verify we got a RetryableConnection
$connection = $db->connection;
echo "Connection type: " . get_class($connection) . "\n";

if ($connection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
    echo "Retry enabled: " . ($connection->getRetryConfig()['enabled'] ? 'Yes' : 'No') . "\n";
    echo "Max attempts: " . $connection->getRetryConfig()['max_attempts'] . "\n";
    echo "Delay: " . $connection->getRetryConfig()['delay_ms'] . "ms\n";
    echo "Backoff multiplier: " . $connection->getRetryConfig()['backoff_multiplier'] . "\n";
    echo "Max delay: " . $connection->getRetryConfig()['max_delay_ms'] . "ms\n";
    echo "Retryable errors: " . implode(', ', $connection->getRetryConfig()['retryable_errors']) . "\n";
} else {
    echo "Retry not available on this connection type\n";
}
echo "\n";

// Example 2: Normal operations work as expected
echo "2. Normal Operations:\n";
$db->rawQuery("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->rawQuery("INSERT INTO users (name, email) VALUES (?, ?)", ['Alice', 'alice@example.com']);
$db->rawQuery("INSERT INTO users (name, email) VALUES (?, ?)", ['Bob', 'bob@example.com']);

$users = $db->find()->from('users')->get();
echo "Found " . count($users) . " users:\n";
foreach ($users as $user) {
    echo "  - {$user['name']} ({$user['email']})\n";
}
echo "\n";

// Example 3: Query Builder operations
echo "3. Query Builder Operations:\n";
$activeUsers = $db->find()
    ->from('users')
    ->where('name', 'Alice')
    ->get();

echo "Query Builder found " . count($activeUsers) . " user(s) named Alice\n";

$userCount = $db->find()
    ->from('users')
    ->select([Db::count()])
    ->getValue();

echo "Total users: $userCount\n\n";

// Example 4: Different retry configurations
echo "4. Different Retry Configurations:\n";

// Aggressive retry (many attempts, short delays)
$aggressiveConfig = [
    'path' => ':memory:',
    'retry' => [
        'enabled' => true,
        'max_attempts' => 5,
        'delay_ms' => 100,
        'backoff_multiplier' => 1.5,
        'max_delay_ms' => 2000,
        'retryable_errors' => [2002, 2003, 2006]
    ]
];

$aggressiveDb = new PdoDb('sqlite', $aggressiveConfig);
$aggressiveConnection = $aggressiveDb->connection;
if ($aggressiveConnection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
    echo "Aggressive retry: " . $aggressiveConnection->getRetryConfig()['max_attempts'] . " attempts, " . 
         $aggressiveConnection->getRetryConfig()['delay_ms'] . "ms delay\n";
}

// Conservative retry (few attempts, longer delays)
$conservativeConfig = [
    'path' => ':memory:',
    'retry' => [
        'enabled' => true,
        'max_attempts' => 2,
        'delay_ms' => 5000,
        'backoff_multiplier' => 3,
        'max_delay_ms' => 30000,
        'retryable_errors' => [2002, 2003, 2006]
    ]
];

$conservativeDb = new PdoDb('sqlite', $conservativeConfig);
$conservativeConnection = $conservativeDb->connection;
if ($conservativeConnection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
    echo "Conservative retry: " . $conservativeConnection->getRetryConfig()['max_attempts'] . " attempts, " . 
         $conservativeConnection->getRetryConfig()['delay_ms'] . "ms delay\n";
}
echo "\n";

// Example 5: Retry disabled
echo "5. Retry Disabled:\n";
$noRetryConfig = [
    'path' => ':memory:',
    'retry' => [
        'enabled' => false,
        'max_attempts' => 3,
        'retryable_errors' => [2002, 2003, 2006]
    ]
];

$noRetryDb = new PdoDb('sqlite', $noRetryConfig);
$noRetryConnection = $noRetryDb->connection;
echo "Retry disabled - connection type: " . get_class($noRetryConnection) . "\n";
if ($noRetryConnection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
    echo "Retry enabled: " . ($noRetryConnection->getRetryConfig()['enabled'] ? 'Yes' : 'No') . "\n";
} else {
    echo "Retry not available on this connection type\n";
}
echo "\n";

// Example 6: No retry config (defaults to regular connection)
echo "6. No Retry Config (Default):\n";
$defaultConfig = [
    'path' => ':memory:'
];

$defaultDb = new PdoDb('sqlite', $defaultConfig);
$defaultConnection = $defaultDb->connection;
echo "No retry config - connection type: " . get_class($defaultConnection) . "\n\n";

echo "=== Example Complete ===\n";
