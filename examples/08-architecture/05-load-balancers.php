<?php

/**
 * Load Balancers Example
 *
 * Demonstrates different load balancing strategies for read replicas.
 * Includes RoundRobin, Random, and Weighted load balancers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;
use tommyknocker\pdodb\connection\loadbalancer\RandomLoadBalancer;
use tommyknocker\pdodb\connection\loadbalancer\WeightedLoadBalancer;

$driver = getenv('PDODB_DRIVER') ?: 'mysql';
$config = getExampleConfig();

// For SQLite, use a temporary file instead of :memory: for read/write splitting
if ($driver === 'sqlite') {
    $config['path'] = sys_get_temp_dir() . '/pdodb_rw_test_' . uniqid() . '.db';
}

echo "=== Read/Write Splitting - Load Balancers ===\n\n";

// ========================================
// 1. Round-Robin Load Balancer
// ========================================
echo "1. Round-Robin Load Balancer\n";
echo "   Distributes load evenly in circular order\n\n";

$db1 = new PdoDb($driver, $config);
$db1->enableReadWriteSplitting(new RoundRobinLoadBalancer());

$writeConfig = $config;
$writeConfig['driver'] = $driver;
$writeConfig['type'] = 'write';
$db1->addConnection('write', $writeConfig);

for ($i = 1; $i <= 3; $i++) {
    $readConfig = $config;
    $readConfig['driver'] = $driver;
    $readConfig['type'] = 'read';
    $db1->addConnection("read-$i", $readConfig);
}

// Create test table using fluent API (cross-dialect)
$schema1 = $db1->schema();
$schema1->dropTableIfExists('lb_test');
$schema1->createTable('lb_test', [
    'id' => $schema1->integer()->notNull(),
    'name' => $schema1->string(255),
], ['primaryKey' => ['id']]);

$db1->rawQuery("INSERT INTO lb_test (id, name) VALUES (1, 'Test')");

echo "   Load balancer type: " . get_class($db1->getConnectionRouter()->getLoadBalancer()) . "\n";
echo "   Read replicas: " . count($db1->getConnectionRouter()->getReadConnections()) . "\n";

// Simulate multiple reads - will distribute across replicas in order
for ($i = 1; $i <= 3; $i++) {
    $db1->find()->from('lb_test')->where('id', 1)->getOne();
    echo "   ✓ Read request $i completed\n";
}

echo "\n";

// ========================================
// 2. Random Load Balancer
// ========================================
echo "2. Random Load Balancer\n";
echo "   Randomly selects a replica for each request\n\n";

$db2 = new PdoDb($driver, $config);
$db2->enableReadWriteSplitting(new RandomLoadBalancer());

$writeConfig = $config;
$writeConfig['driver'] = $driver;
$writeConfig['type'] = 'write';
$db2->addConnection('write', $writeConfig);

for ($i = 1; $i <= 3; $i++) {
    $readConfig = $config;
    $readConfig['driver'] = $driver;
    $readConfig['type'] = 'read';
    $db2->addConnection("read-$i", $readConfig);
}

echo "   Load balancer type: " . get_class($db2->getConnectionRouter()->getLoadBalancer()) . "\n";
echo "   Read replicas: " . count($db2->getConnectionRouter()->getReadConnections()) . "\n";

// Simulate multiple reads - will distribute randomly
for ($i = 1; $i <= 3; $i++) {
    $db2->find()->from('lb_test')->where('id', 1)->getOne();
    echo "   ✓ Read request $i completed\n";
}

echo "\n";

// ========================================
// 3. Weighted Load Balancer
// ========================================
echo "3. Weighted Load Balancer\n";
echo "   Distributes load based on replica weights\n\n";

$weightedBalancer = new WeightedLoadBalancer();
$weightedBalancer->setWeights([
    'read-1' => 3,  // Gets 3x more traffic
    'read-2' => 2,  // Gets 2x more traffic
    'read-3' => 1,  // Gets 1x traffic (baseline)
]);

$db3 = new PdoDb($driver, $config);
$db3->enableReadWriteSplitting($weightedBalancer);

$writeConfig = $config;
$writeConfig['driver'] = $driver;
$writeConfig['type'] = 'write';
$db3->addConnection('write', $writeConfig);

for ($i = 1; $i <= 3; $i++) {
    $readConfig = $config;
    $readConfig['driver'] = $driver;
    $readConfig['type'] = 'read';
    $db3->addConnection("read-$i", $readConfig);
}

echo "   Load balancer type: " . get_class($db3->getConnectionRouter()->getLoadBalancer()) . "\n";
echo "   Read replicas: " . count($db3->getConnectionRouter()->getReadConnections()) . "\n";
echo "   Weights:\n";
echo "     read-1: 3 (50% of traffic)\n";
echo "     read-2: 2 (33% of traffic)\n";
echo "     read-3: 1 (17% of traffic)\n\n";

// Simulate multiple reads to demonstrate weighted distribution
for ($i = 1; $i <= 6; $i++) {
    $db3->find()->from('lb_test')->where('id', 1)->getOne();
    echo "   ✓ Read request $i completed\n";
}

echo "\n";

// ========================================
// 4. Health Checks and Failover
// ========================================
echo "4. Health Checks and Failover\n";
echo "   Marking replicas as failed/healthy\n\n";

$balancer = new RoundRobinLoadBalancer();

echo "   Marking read-2 as failed...\n";
$balancer->markFailed('read-2');
echo "   ✓ read-2 marked as failed (will be skipped)\n\n";

echo "   Marking read-2 as healthy...\n";
$balancer->markHealthy('read-2');
echo "   ✓ read-2 marked as healthy (will be used again)\n\n";

echo "   Resetting all connection states...\n";
$balancer->reset();
echo "   ✓ All connections reset\n\n";

// ========================================
// 5. Switching Load Balancers
// ========================================
echo "5. Switching Load Balancers at Runtime\n\n";

$db4 = new PdoDb($driver, $config);
$db4->enableReadWriteSplitting(new RoundRobinLoadBalancer());

$writeConfig = $config;
$writeConfig['driver'] = $driver;
$writeConfig['type'] = 'write';
$db4->addConnection('write', $writeConfig);

$readConfig = $config;
$readConfig['driver'] = $driver;
$readConfig['type'] = 'read';
$db4->addConnection('read-1', $readConfig);

echo "   Initial: " . get_class($db4->getConnectionRouter()->getLoadBalancer()) . "\n";

// Switch to RandomLoadBalancer
$db4->getConnectionRouter()->setLoadBalancer(new RandomLoadBalancer());
echo "   Switched to: " . get_class($db4->getConnectionRouter()->getLoadBalancer()) . "\n\n";

// ========================================
// 6. Cleanup
// ========================================
echo "6. Cleanup\n";
$schema1 = $db1->schema();
$schema1->dropTableIfExists('lb_test');
echo "✓ Test table dropped\n";

// Clean up SQLite temp file
if ($driver === 'sqlite' && isset($config['path']) && file_exists($config['path'])) {
    unlink($config['path']);
    echo "✓ Temporary SQLite file removed\n";
}

echo "\n=== Example completed successfully ===\n";

