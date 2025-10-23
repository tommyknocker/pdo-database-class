<?php
/**
 * Connection Retry Example
 * 
 * Demonstrates how to use the connection retry functionality
 * to handle temporary database connection issues automatically.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\connection\RetryableConnection;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\helpers\DbError;

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
            DbError::MYSQL_CANNOT_CONNECT,
            DbError::MYSQL_CONNECTION_KILLED,
            DbError::MYSQL_CONNECTION_LOST,
            DbError::POSTGRESQL_CONNECTION_FAILURE,
            DbError::POSTGRESQL_CONNECTION_DOES_NOT_EXIST,
        ]
    ]
]);

// Verify we got a RetryableConnection
$connection = $db->connection;
echo "Connection type: " . get_class($connection) . "\n";

if ($connection instanceof RetryableConnection) {
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
        'retryable_errors' => [
            DbError::MYSQL_CANNOT_CONNECT,
            DbError::MYSQL_CONNECTION_KILLED,
            DbError::MYSQL_CONNECTION_LOST,
        ]
    ]
];

$aggressiveDb = new PdoDb('sqlite', $aggressiveConfig);
$aggressiveConnection = $aggressiveDb->connection;
if ($aggressiveConnection instanceof RetryableConnection) {
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
        'retryable_errors' => [
            DbError::MYSQL_CANNOT_CONNECT,
            DbError::MYSQL_CONNECTION_KILLED,
            DbError::MYSQL_CONNECTION_LOST,
        ]
    ]
];

$conservativeDb = new PdoDb('sqlite', $conservativeConfig);
$conservativeConnection = $conservativeDb->connection;
if ($conservativeConnection instanceof RetryableConnection) {
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
        'retryable_errors' => [
            DbError::MYSQL_CANNOT_CONNECT,
            DbError::MYSQL_CONNECTION_KILLED,
            DbError::MYSQL_CONNECTION_LOST,
        ]
    ]
];

$noRetryDb = new PdoDb('sqlite', $noRetryConfig);
$noRetryConnection = $noRetryDb->connection;
echo "Retry disabled - connection type: " . get_class($noRetryConnection) . "\n";
if ($noRetryConnection instanceof RetryableConnection) {
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

// Example 7: Using DbError constants and helper methods
echo "7. DbError Constants and Helper Methods:\n";

// Get retryable errors for different drivers
$mysqlRetryableErrors = DbError::getMysqlRetryableErrors();
$postgresqlRetryableErrors = DbError::getPostgresqlRetryableErrors();
$sqliteRetryableErrors = DbError::getSqliteRetryableErrors();

echo "MySQL retryable errors: " . implode(', ', $mysqlRetryableErrors) . "\n";
echo "PostgreSQL retryable errors: " . implode(', ', $postgresqlRetryableErrors) . "\n";
echo "SQLite retryable errors: " . implode(', ', $sqliteRetryableErrors) . "\n";

// Check if specific error codes are retryable
echo "Is MySQL error 2006 retryable? " . (DbError::isRetryable(2006, 'mysql') ? 'Yes' : 'No') . "\n";
echo "Is PostgreSQL error '08006' retryable? " . (DbError::isRetryable('08006', 'pgsql') ? 'Yes' : 'No') . "\n";
echo "Is SQLite error 5 retryable? " . (DbError::isRetryable(5, 'sqlite') ? 'Yes' : 'No') . "\n";

// Get error descriptions
echo "MySQL error 2006 description: " . DbError::getDescription(2006, 'mysql') . "\n";
echo "PostgreSQL error '08006' description: " . DbError::getDescription('08006', 'pgsql') . "\n";
echo "SQLite error 5 description: " . DbError::getDescription(5, 'sqlite') . "\n";

// Example using DbError constants in configuration
echo "\n8. Using DbError Constants in Configuration:\n";
$dbWithDbError = new PdoDb('sqlite', [
    'path' => ':memory:',
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 100,
        'retryable_errors' => DbError::getRetryableErrors('sqlite'), // Use helper method
    ]
]);

$dbErrorConnection = $dbWithDbError->connection;
echo "Connection with DbError constants: " . get_class($dbErrorConnection) . "\n";
if ($dbErrorConnection instanceof RetryableConnection) {
    $config = $dbErrorConnection->getRetryConfig();
    echo "Retryable errors count: " . count($config['retryable_errors']) . "\n";
    echo "First few retryable errors: " . implode(', ', array_slice($config['retryable_errors'], 0, 5)) . "\n";
}
echo "\n";

echo "=== Example Complete ===\n";
