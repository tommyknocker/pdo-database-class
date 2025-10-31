# Read/Write Splitting

Read/Write Splitting is a database scaling technique that distributes database load across multiple servers by routing read and write operations to different database instances.

## Table of Contents

- [Overview](#overview)
- [When to Use](#when-to-use)
- [Basic Setup](#basic-setup)
- [Automatic Routing](#automatic-routing)
- [Load Balancing Strategies](#load-balancing-strategies)
- [Sticky Writes](#sticky-writes)
- [Force Write Mode](#force-write-mode)
- [Transactions](#transactions)
- [Health Checks](#health-checks)
- [Best Practices](#best-practices)

## Overview

Read/Write Splitting allows you to:
- **Scale horizontally** by adding read replicas
- **Distribute load** across multiple database servers
- **Improve performance** by separating read and write traffic
- **Increase availability** with automatic failover

### Architecture

```
┌─────────────┐
│   PdoDb     │
└──────┬──────┘
       │
       ├──────────────┐
       │              │
┌──────▼──────┐  ┌───▼────────────┐
│   Master    │  │  Load Balancer │
│  (writes)   │  │   (reads)      │
└─────────────┘  └───┬────────────┘
                     │
                ┌────┼────┐
                │    │    │
           ┌────▼┐ ┌▼────▼┐
           │Rep-1│ │Rep-2│ ...
           └─────┘ └──────┘
```

## When to Use

Read/Write Splitting is ideal when:

✅ **Your application has more reads than writes** (typical ratio: 80% reads, 20% writes)  
✅ **You need horizontal scalability** without changing application code  
✅ **You have database replication** set up (master-slave/master-replica)  
✅ **Read latency is a concern** and you want to distribute load

❌ **Avoid when**:
- Write-heavy workloads dominate
- Strict consistency is required for all reads (use sticky writes)
- Replication lag is unacceptable
- You have a small database that fits on one server

## Basic Setup

### 1. Enable Read/Write Splitting

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;

$db = new PdoDb();

// Enable read/write splitting with a load balancer
$db->enableReadWriteSplitting(new RoundRobinLoadBalancer());
```

### 2. Add Write Connection (Master)

```php
$db->addConnection('master', [
    'driver' => 'mysql',
    'host' => 'master.db.local',
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'type' => 'write',  // Mark as write connection
]);
```

### 3. Add Read Connections (Replicas)

```php
$db->addConnection('replica-1', [
    'driver' => 'mysql',
    'host' => 'replica1.db.local',
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'type' => 'read',  // Mark as read connection
]);

$db->addConnection('replica-2', [
    'driver' => 'mysql',
    'host' => 'replica2.db.local',
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'type' => 'read',
]);
```

### 4. Set Default Connection

```php
// Set the default connection (typically write)
$db->connection('master');
```

## Automatic Routing

Once configured, queries are automatically routed:

```php
// SELECT queries go to read replicas
$users = $db->find()->from('users')->get();

// INSERT queries go to write master
$id = $db->find()->table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// UPDATE queries go to write master
$db->find()->table('users')
    ->where('id', $id)
    ->update(['status' => 'active']);

// DELETE queries go to write master
$db->find()->table('users')
    ->where('id', $id)
    ->delete();
```

## Load Balancing Strategies

PdoDb supports multiple load balancing strategies for distributing reads across replicas.

### Round-Robin (Default)

Distributes requests evenly in circular order.

```php
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;

$db->enableReadWriteSplitting(new RoundRobinLoadBalancer());
```

**Pros**: Even distribution, predictable  
**Cons**: Doesn't account for server capacity differences

### Random

Randomly selects a replica for each request.

```php
use tommyknocker\pdodb\connection\loadbalancer\RandomLoadBalancer;

$db->enableReadWriteSplitting(new RandomLoadBalancer());
```

**Pros**: Simple, good distribution over time  
**Cons**: May have short-term imbalances

### Weighted

Distributes load proportionally based on server weights.

```php
use tommyknocker\pdodb\connection\loadbalancer\WeightedLoadBalancer;

$balancer = new WeightedLoadBalancer();
$balancer->setWeights([
    'replica-1' => 3,  // Gets 3x more traffic
    'replica-2' => 2,  // Gets 2x more traffic
    'replica-3' => 1,  // Gets 1x traffic (baseline)
]);

$db->enableReadWriteSplitting($balancer);
```

**Pros**: Can balance based on server capacity  
**Cons**: Requires capacity planning and tuning

### Switching Load Balancers

You can change the load balancer at runtime:

```php
$router = $db->getConnectionRouter();
$router->setLoadBalancer(new RandomLoadBalancer());
```

## Sticky Writes

Sticky writes ensure read-after-write consistency by routing reads to the master for a specified duration after a write operation.

### Why Use Sticky Writes?

Database replication has a delay (lag). After writing to the master, replicas may not have the data immediately. Sticky writes solve this by:
- Reading from master after writes for a specified time window
- Ensuring the application sees its own writes
- Automatic expiration after the time window

### Enable Sticky Writes

```php
// Enable sticky writes for 60 seconds
$db->enableStickyWrites(60);
```

### How It Works

```php
// Write operation
$id = $db->find()->table('posts')->insert([
    'title' => 'New Post',
    'content' => 'Content here',
]);

// For the next 60 seconds, this read goes to MASTER
$post = $db->find()->from('posts')->where('id', $id)->getOne();

// After 60 seconds, reads go back to REPLICAS
sleep(61);
$post = $db->find()->from('posts')->where('id', $id)->getOne();
```

### Disable Sticky Writes

```php
$db->disableStickyWrites();
```

## Force Write Mode

Sometimes you need to explicitly read from the master, regardless of sticky writes settings.

```php
// Force this SELECT to read from master
$user = $db->find()
    ->from('users')
    ->forceWrite()  // Read from master
    ->where('id', 1)
    ->getOne();
```

**Use cases**:
- Critical operations requiring absolute consistency
- After payment processing
- Before security checks
- When replication lag is a concern

## Transactions

All queries within a transaction automatically use the write connection (master).

```php
$db->transaction(function ($db) {
    // This SELECT uses the MASTER (not replica)
    $user = $db->find()->from('users')->where('id', 1)->getOne();
    
    // Update operation
    $db->find()->table('users')
        ->where('id', 1)
        ->update(['balance' => $user['balance'] - 100]);
    
    // Insert operation
    $db->find()->table('transactions')->insert([
        'user_id' => 1,
        'amount' => -100,
    ]);
});
```

**Why?** Transactions require consistency and isolation. Reading from replicas during a transaction could lead to:
- Inconsistent data
- Deadlocks
- Phantom reads

## Health Checks

Monitor and manage replica health to handle failures gracefully.

### Manual Health Check

```php
$router = $db->getConnectionRouter();

foreach ($router->getReadConnections() as $name => $connection) {
    if (!$router->healthCheck($connection)) {
        // Connection is unhealthy
        $router->getLoadBalancer()->markFailed($name);
    }
}
```

### Mark Connection States

```php
$balancer = $router->getLoadBalancer();

// Mark a replica as failed (will be skipped)
$balancer->markFailed('replica-2');

// Mark a replica as healthy (will be used again)
$balancer->markHealthy('replica-2');

// Reset all connection states
$balancer->reset();
```

### Automatic Failover

Load balancers automatically skip failed replicas and retry all if all fail:

```php
// If all replicas fail, queries fall back to master
$users = $db->find()->from('users')->get();
```

## Best Practices

### 1. Monitor Replication Lag

```php
// Check replication lag periodically
$lag = $db->rawQueryValue(
    "SHOW SLAVE STATUS"
)['Seconds_Behind_Master'] ?? 0;

if ($lag > 5) {
    // Consider increasing sticky write duration
    $db->enableStickyWrites(max(60, $lag * 2));
}
```

### 2. Use Sticky Writes for User Sessions

```php
// After login, enable sticky writes for session duration
$userId = authenticateUser($username, $password);
$db->enableStickyWrites(3600); // 1 hour
```

### 3. Force Write for Critical Operations

```php
// Before processing payment, read from master
$user = $db->find()
    ->from('users')
    ->forceWrite()
    ->where('id', $userId)
    ->getOne();

if ($user['balance'] >= $amount) {
    // Process payment...
}
```

### 4. Set Appropriate Weights

```php
// Distribute based on server capacity
$balancer->setWeights([
    'replica-1' => 4,  // 16GB RAM, 8 cores
    'replica-2' => 2,  // 8GB RAM, 4 cores
    'replica-3' => 1,  // 4GB RAM, 2 cores
]);
```

### 5. Health Check Interval

```php
// Check health every 30 seconds
while (true) {
    $router = $db->getConnectionRouter();
    $balancer = $router->getLoadBalancer();
    
    foreach ($router->getReadConnections() as $name => $conn) {
        if ($router->healthCheck($conn)) {
            $balancer->markHealthy($name);
        } else {
            $balancer->markFailed($name);
            logError("Replica $name is down");
        }
    }
    
    sleep(30);
}
```

### 6. Testing

Always test read/write splitting in staging before production:

```php
// Enable query logging to verify routing
$logger = new \Monolog\Logger('db');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout'));

$db = new PdoDb();
$db->addConnection('master', [...], [], $logger);
```

### 7. Fallback Strategy

Always have a fallback if all replicas fail:

```php
try {
    $users = $db->find()->from('users')->get();
} catch (\PDOException $e) {
    // All replicas failed, fallback to master
    $users = $db->find()->from('users')->forceWrite()->get();
}
```

## Configuration Reference

### Connection Options

```php
$db->addConnection('name', [
    'driver' => 'mysql',         // Required
    'host' => 'localhost',       // Required
    'database' => 'myapp',       // Required
    'username' => 'root',        // Required
    'password' => 'secret',      // Required
    'port' => 3306,             // Optional
    'charset' => 'utf8mb4',     // Optional
    'type' => 'read',           // 'read' or 'write' (default: 'write')
]);
```

### Router Methods

```php
$router = $db->getConnectionRouter();

// Get connections
$router->getWriteConnections();  // array
$router->getReadConnections();   // array

// Connection routing
$router->getReadConnection();    // ConnectionInterface
$router->getWriteConnection();   // ConnectionInterface

// Force write mode
$router->enableForceWrite();
$router->disableForceWrite();
$router->isForceWriteMode();     // bool

// Sticky writes
$router->enableStickyWrites(60);
$router->disableStickyWrites();
$router->isStickyWritesEnabled(); // bool

// Transaction state
$router->setTransactionState(true);
$router->isInTransaction();      // bool

// Health check
$router->healthCheck($connection); // bool

// Load balancer
$router->getLoadBalancer();      // LoadBalancerInterface
$router->setLoadBalancer($balancer);
```

## Troubleshooting

### Problem: Reads are not balanced evenly

**Solution**: Check load balancer configuration and replica health.

```php
$router = $db->getConnectionRouter();
echo "Read connections: " . count($router->getReadConnections()) . "\n";
echo "Load balancer: " . get_class($router->getLoadBalancer()) . "\n";
```

### Problem: Seeing stale data after writes

**Solution**: Enable sticky writes or use `forceWrite()`.

```php
$db->enableStickyWrites(60);
// or
$data = $db->find()->from('table')->forceWrite()->get();
```

### Problem: All replicas marked as failed

**Solution**: Check network connectivity and replica status.

```php
$router = $db->getConnectionRouter();
$balancer = $router->getLoadBalancer();
$balancer->reset(); // Clear failed states and retry
```

### Problem: High replication lag

**Solution**: Increase sticky writes duration or add more replicas.

```php
$db->enableStickyWrites(120); // Increase to 2 minutes
```

## See Also

- [Connection Management](../02-core-concepts/connection-management.md)
- [Transactions](transactions.md)
- [Connection Retry](connection-retry.md)
- [Performance Best Practices](../08-best-practices/performance.md)
