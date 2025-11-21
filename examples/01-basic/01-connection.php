<?php
/**
 * Example 01: Database Connection
 * 
 * Demonstrates how to connect to different databases
 * This example tests all available database configurations
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;

echo "=== PDOdb Connection Examples ===\n\n";

// Helper function to check if config file exists and is accessible
function tryConnect($driver, $configFile) {
    if (!file_exists($configFile)) {
        return null;
    }
    
    try {
        $config = require $configFile;
        $db = new PdoDb($driver, $config);
        return $db;
    } catch (\Throwable $e) {
        echo "  ⚠️  Connection failed: {$e->getMessage()}\n";
        return false;
    }
}

// Example 1: SQLite Connection (always available)
echo "1. Connecting to SQLite...\n";
$sqliteConfig = __DIR__ . '/../config.sqlite.php';
$sqlite = tryConnect('sqlite', $sqliteConfig);

if ($sqlite instanceof PdoDb) {
    echo "✓ SQLite connected successfully\n";
    $config = require $sqliteConfig;
    echo "  Path: {$config['path']}\n";
    
    // Test with a simple query
    $result = $sqlite->rawQueryValue('SELECT 1 + 1 AS result');
    echo "  Test query result: $result\n\n";
} else {
    echo "✗ SQLite config not found: $sqliteConfig\n\n";
}

// Example 2: MySQL Connection (if config exists)
echo "2. Connecting to MySQL...\n";
$mysqlConfig = __DIR__ . '/../config.mysql.php';
$mysql = tryConnect('mysql', $mysqlConfig);

if ($mysql instanceof PdoDb) {
    echo "✓ MySQL connected successfully\n";
    $config = require $mysqlConfig;
    echo "  Server: {$config['host']}:{$config['port']}\n";
    echo "  Database: {$config['dbname']}\n\n";
} elseif ($mysql === false) {
    echo "  (Check your MySQL server and config.mysql.php settings)\n\n";
} else {
    echo "  ℹ️  Config not found: $mysqlConfig\n";
    echo "  (This is OK - MySQL is optional. Create config.mysql.php to test)\n\n";
}

// Example 3: PostgreSQL Connection (if config exists)
echo "3. Connecting to PostgreSQL...\n";
$pgsqlConfig = __DIR__ . '/../config.pgsql.php';
$pgsql = tryConnect('pgsql', $pgsqlConfig);

if ($pgsql instanceof PdoDb) {
    echo "✓ PostgreSQL connected successfully\n";
    $config = require $pgsqlConfig;
    echo "  Server: {$config['host']}:{$config['port']}\n";
    echo "  Database: {$config['dbname']}\n\n";
} elseif ($pgsql === false) {
    echo "  (Check your PostgreSQL server and config.pgsql.php settings)\n\n";
} else {
    echo "  ℹ️  Config not found: $pgsqlConfig\n";
    echo "  (This is OK - PostgreSQL is optional. Create config.pgsql.php to test)\n\n";
}

// Example 4: Microsoft SQL Server Connection (if config exists)
echo "4. Connecting to Microsoft SQL Server...\n";
$mssqlConfig = __DIR__ . '/../config.sqlsrv.php';
$mssql = tryConnect('sqlsrv', $mssqlConfig);

if ($mssql instanceof PdoDb) {
    echo "✓ MSSQL connected successfully\n";
    $config = require $mssqlConfig;
    echo "  Server: {$config['host']}:{$config['port']}\n";
    echo "  Database: {$config['dbname']}\n\n";
} elseif ($mssql === false) {
    echo "  (Check your MSSQL server and config.sqlsrv.php settings)\n\n";
} else {
    echo "  ℹ️  Config not found: $mssqlConfig\n";
    echo "  (This is OK - MSSQL is optional. Create config.sqlsrv.php to test)\n\n";
}

// Example 5: Connection pooling (without default connection)
echo "5. Connection pooling example...\n";
$db = new PdoDb();

// Use SQLite for pooling demo (always available)
$sqliteConf = require $sqliteConfig;
$db->addConnection('primary', $sqliteConf);
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
echo "\nℹ️  Tip: Create config.mysql.php, config.pgsql.php, and config.sqlsrv.php to test all databases\n";
