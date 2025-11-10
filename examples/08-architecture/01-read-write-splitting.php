<?php

/**
 * Basic Read/Write Splitting Setup Example
 *
 * This example demonstrates how to set up and use read/write connection splitting
 * to distribute database load across master and replica servers.
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

echo "=== Read/Write Splitting - Basic Setup ===\n\n";

// ========================================
// 1. Basic Setup with RoundRobin Load Balancer
// ========================================
echo "1. Setting up read/write splitting\n";

// Create initial database instance with write connection
$db = new PdoDb($driver, $config);

// Enable read/write splitting with round-robin load balancer
$db->enableReadWriteSplitting(new RoundRobinLoadBalancer());

// Add write connection (master) - using the default connection
$writeConfig = $config;
$writeConfig['driver'] = $driver;
$writeConfig['type'] = 'write';
$db->addConnection('write', $writeConfig);

// For demo purposes, use the same database but mark as read replicas
// In production, these would be actual replica servers
$readConfig1 = $config;
$readConfig1['driver'] = $driver;
$readConfig1['type'] = 'read';
$db->addConnection('read-1', $readConfig1);

$readConfig2 = $config;
$readConfig2['driver'] = $driver;
$readConfig2['type'] = 'read';
$db->addConnection('read-2', $readConfig2);

echo "✓ Configured 1 write master and 2 read replicas\n\n";

// ========================================
// 2. Create Test Table
// ========================================
echo "2. Creating test table\n";

// Create test table using fluent API (cross-dialect)
$schema = $db->schema();
$schema->dropTableIfExists('users_rw');
$schema->createTable('users_rw', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(255)->notNull(),
    'email' => $schema->string(255)->notNull(),
    'status' => $schema->string(50)->defaultValue('active'),
    'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
]);

echo "✓ Table created\n\n";

// ========================================
// 3. Automatic Read/Write Routing
// ========================================
echo "3. Testing automatic routing\n";

// INSERT goes to write master automatically
$userId = $db->find()
    ->table('users_rw')
    ->insert([
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

echo "✓ INSERT (routed to master): User ID = $userId\n";

// SELECT goes to read replica automatically
$users = $db->find()
    ->from('users_rw')
    ->get();

echo "✓ SELECT (routed to replica): Found " . count($users) . " users\n";

// UPDATE goes to write master automatically
$affected = $db->find()
    ->table('users_rw')
    ->where('id', $userId)
    ->update(['status' => 'inactive']);

echo "✓ UPDATE (routed to master): Affected $affected rows\n\n";

// ========================================
// 4. Force Write Mode for SELECTs
// ========================================
echo "4. Testing forceWrite() for SELECT queries\n";

// Sometimes you need to read from master (e.g., after a write)
$user = $db->find()
    ->from('users_rw')
    ->forceWrite() // Force this SELECT to use master
    ->where('id', $userId)
    ->getOne();

echo "✓ SELECT with forceWrite() (routed to master): User = " . $user['name'] . "\n\n";

// ========================================
// 5. Connection Router Info
// ========================================
echo "5. Connection router information\n";

$router = $db->getConnectionRouter();

echo "Write connections: " . count($router->getWriteConnections()) . "\n";
echo "Read connections: " . count($router->getReadConnections()) . "\n";
echo "Sticky writes enabled: " . ($router->isStickyWritesEnabled() ? 'Yes' : 'No') . "\n";
echo "Force write mode: " . ($router->isForceWriteMode() ? 'Yes' : 'No') . "\n\n";

// ========================================
// 6. Cleanup
// ========================================
echo "6. Cleanup\n";
$schema->dropTableIfExists('users_rw');
echo "✓ Test table dropped\n";

// Clean up SQLite temp file
if ($driver === 'sqlite' && isset($config['path']) && file_exists($config['path'])) {
    unlink($config['path']);
    echo "✓ Temporary SQLite file removed\n";
}

echo "\n=== Example completed successfully ===\n";

