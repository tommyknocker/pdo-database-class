# Read/Write Splitting Examples

This directory contains examples demonstrating the read/write splitting functionality of PdoDb.

## Overview

Read/write splitting allows you to distribute database load across multiple servers:
- **Write operations** (INSERT, UPDATE, DELETE) go to the master server
- **Read operations** (SELECT) go to replica servers
- Automatic load balancing across multiple replicas
- Support for sticky writes to ensure read-after-write consistency

## Examples

### 01-basic-setup.php
Demonstrates basic read/write splitting configuration:
- Setting up master and replica connections
- Automatic query routing
- Using `forceWrite()` to read from master
- Connection router information

### 02-sticky-writes.php
Shows how to use sticky writes for read-after-write consistency:
- Enabling sticky writes with a time window
- How reads go to master after writes
- Transaction behavior (always uses master)
- Disabling sticky writes

### 03-load-balancers.php
Explores different load balancing strategies:
- Round-Robin load balancer (even distribution)
- Random load balancer (random selection)
- Weighted load balancer (proportional distribution)
- Health checks and failover
- Switching load balancers at runtime

## Running the Examples

Run examples with different database drivers:

```bash
# MySQL
PDODB_DRIVER=mysql php 01-basic-setup.php

# PostgreSQL
PDODB_DRIVER=pgsql php 01-basic-setup.php

# SQLite
PDODB_DRIVER=sqlite php 01-basic-setup.php
```

## Key Concepts

### Automatic Routing
```php
// SELECT automatically goes to replica
$users = $db->find()->from('users')->get();

// INSERT automatically goes to master
$db->find()->table('users')->insert(['name' => 'John']);
```

### Force Write Mode
```php
// Force this SELECT to use master
$user = $db->find()
    ->from('users')
    ->forceWrite()
    ->where('id', 1)
    ->getOne();
```

### Sticky Writes
```php
// Enable sticky writes for 60 seconds
$db->enableStickyWrites(60);

// After any write, reads go to master for 60 seconds
$db->find()->table('users')->insert(['name' => 'John']);
$user = $db->find()->from('users')->getOne(); // Goes to master
```

### Transactions
```php
// All queries in transaction use master
$db->transaction(function ($db) {
    $user = $db->find()->from('users')->getOne(); // Uses master
    $db->find()->table('users')->update(['status' => 'active']);
});
```

## Load Balancing Strategies

### Round-Robin
```php
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;

$db->enableReadWriteSplitting(new RoundRobinLoadBalancer());
```
Distributes requests evenly across replicas in circular order.

### Random
```php
use tommyknocker\pdodb\connection\loadbalancer\RandomLoadBalancer;

$db->enableReadWriteSplitting(new RandomLoadBalancer());
```
Randomly selects a replica for each request.

### Weighted
```php
use tommyknocker\pdodb\connection\loadbalancer\WeightedLoadBalancer;

$balancer = new WeightedLoadBalancer();
$balancer->setWeights([
    'read-1' => 3, // Gets 3x more traffic
    'read-2' => 1, // Gets 1x traffic
]);
$db->enableReadWriteSplitting($balancer);
```
Distributes load proportionally based on weights.

## Production Considerations

1. **Replication Lag**: Replicas may have slight delays. Use `forceWrite()` or sticky writes when immediate consistency is required.

2. **Health Monitoring**: Implement health checks to detect and handle failed replicas.

3. **Connection Pooling**: Combine with connection pooling for optimal performance.

4. **Transaction Isolation**: All queries within a transaction automatically use the master.

5. **Testing**: Always test your read/write splitting configuration in a staging environment first.

