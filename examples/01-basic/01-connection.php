<?php
/**
 * Example 01: Database Connection
 * 
 * Demonstrates how to connect to different databases
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;

// Load configuration
if (!file_exists(__DIR__ . '/../config.php')) {
    die("Error: Please copy config.example.php to config.php and update with your credentials\n");
}
$config = require __DIR__ . '/../config.php';

echo "=== PDOdb Connection Examples (on $driver) ===\n\n";

// Example 1: SQLite Connection (in-memory) - easiest to get started
echo "1. Connecting to SQLite (in-memory)...\n";
try {
    $sqlite = new PdoDb('sqlite', $config['sqlite']);
    echo "✓ SQLite connected successfully\n";
    echo "  Path: {$config['sqlite']['path']}\n\n";
    
    // Test with a simple query
    $result = $sqlite->rawQueryValue('SELECT 1 + 1 AS result');
    echo "  Test query result: $result\n\n";
} catch (\PDOException $e) {
    echo "✗ SQLite connection failed: {$e->getMessage()}\n\n";
}

// Example 2: MySQL Connection (requires MySQL server)
echo "2. Connecting to MySQL...\n";
try {
    $mysql = new PdoDb('mysql', $config['mysql']);
    echo "✓ MySQL connected successfully\n";
    echo "  Server: {$config['mysql']['host']}:{$config['mysql']['port']}\n";
    echo "  Database: {$config['mysql']['dbname']}\n\n";
} catch (\Throwable $e) {
    echo "✗ MySQL connection failed: {$e->getMessage()}\n";
    echo "  (This is OK if MySQL is not installed or config.php needs updating)\n\n";
}

// Example 3: PostgreSQL Connection (requires PostgreSQL server)
echo "3. Connecting to PostgreSQL...\n";
try {
    $pgsql = new PdoDb('pgsql', $config['pgsql']);
    echo "✓ PostgreSQL connected successfully\n";
    echo "  Server: {$config['pgsql']['host']}:{$config['pgsql']['port']}\n";
    echo "  Database: {$config['pgsql']['dbname']}\n\n";
} catch (\Throwable $e) {
    echo "✗ PostgreSQL connection failed: {$e->getMessage()}\n";
    echo "  (This is OK if PostgreSQL is not installed or config.php needs updating)\n\n";
}

// Example 4: Connection pooling (without default connection)
echo "4. Connection pooling example...\n";
$db = new PdoDb();

$db->addConnection('primary', $config['sqlite']);
$db->addConnection('analytics', ['driver' => 'sqlite', 'path' => ':memory:']);

echo "✓ Two connections added to pool\n";
echo "  Connections: primary, analytics\n\n";

// Switch between connections
$db->connection('primary');
$result = $db->rawQueryValue('SELECT "Connected to primary"');
echo "✓ $result\n";

$db->connection('analytics');
$result = $db->rawQueryValue('SELECT "Connected to analytics"');
echo "✓ $result\n\n";

echo "All connection examples completed!\n";

