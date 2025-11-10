# Sharding

Sharding is a database scaling technique that horizontally partitions data across multiple database instances (shards). This allows you to distribute large datasets across multiple databases, improving performance and scalability.

## Overview

PdoDb's sharding feature automatically routes queries to the appropriate shard based on the shard key value in WHERE conditions. This is transparent to your application code - you write queries normally, and PdoDb handles the routing automatically.

## Configuration

Sharding is configured using a fluent API. First, add your shard connections to the connection pool, then configure sharding to use them:

```php
// Add connections to the pool
$db->addConnection('shard1', [
    'driver' => 'mysql',
    'host' => 'shard1.example.com',
    'dbname' => 'users_db',
    'user' => 'user',
    'password' => 'password',
]);

$db->addConnection('shard2', [
    'driver' => 'mysql',
    'host' => 'shard2.example.com',
    'dbname' => 'users_db',
    'user' => 'user',
    'password' => 'password',
]);

$db->addConnection('shard3', [
    'driver' => 'mysql',
    'host' => 'shard3.example.com',
    'dbname' => 'users_db',
    'user' => 'user',
    'password' => 'password',
]);

// Configure sharding to use existing connections
$db->shard('users')
    ->shardKey('user_id')
    ->strategy('range') // or 'hash', 'modulo'
    ->ranges([
        'shard1' => [0, 1000],
        'shard2' => [1001, 2000],
        'shard3' => [2001, 3000],
    ])
    ->useConnections(['shard1', 'shard2', 'shard3'])
    ->register();
```

## Sharding Strategies

### Range Strategy

Distributes data based on numeric ranges. Each shard handles a specific range of shard key values.

```php
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
```

**Use when:**
- Shard key values are numeric and sequential
- You want predictable distribution
- You need to query ranges of values

### Hash Strategy

Distributes data based on hash of the shard key value. Uses CRC32 for consistent hashing.

```php
$db->shard('users')
    ->shardKey('user_id')
    ->strategy('hash')
    ->useConnections(['shard1', 'shard2', 'shard3'])
    ->register();
```

**Use when:**
- You want even distribution across shards
- Shard key values are not sequential
- You want consistent routing (same value always goes to same shard)

### Modulo Strategy

Distributes data based on modulo operation: `value % shard_count`.

```php
$db->shard('users')
    ->shardKey('user_id')
    ->strategy('modulo')
    ->useConnections(['shard1', 'shard2', 'shard3'])
    ->register();
```

**Use when:**
- Shard key values are numeric
- You want simple, predictable distribution
- You need to easily add or remove shards

## Usage

Once configured, sharding works transparently:

```php
// Insert - automatically routed to appropriate shard
$db->find()->table('users')->insert([
    'user_id' => 500,
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);

// Query - automatically routed to appropriate shard
$user = $db->find()
    ->from('users')
    ->where('user_id', 500)
    ->getOne();

// Update - automatically routed to appropriate shard
$db->find()
    ->table('users')
    ->where('user_id', 500)
    ->update(['name' => 'Alice Updated']);

// Delete - automatically routed to appropriate shard
$db->find()
    ->table('users')
    ->where('user_id', 500)
    ->delete();
```

## Shard Key Requirements

For sharding to work, the shard key must be present in WHERE conditions:

```php
// ✅ Works - shard key in WHERE
$user = $db->find()
    ->from('users')
    ->where('user_id', 500)
    ->getOne();

// ❌ Falls back to normal routing - shard key not in WHERE
$users = $db->find()
    ->from('users')
    ->where('name', 'Alice')
    ->get();
```

If the shard key is not found in WHERE conditions, the query will fall back to normal routing (using the default connection or read/write splitting if enabled).

## Advanced Usage

### Using Existing Connections

You can use existing connections from the connection pool:

```php
// Add connections to pool
$db->addConnection('shard1', [...]);
$db->addConnection('shard2', [...]);

// Get connections and register them with shard router
$shardRouter = $db->getShardRouter();
$connections = // get connections from pool

foreach (['shard1', 'shard2'] as $shard) {
    $shardRouter->addShardConnection('users', $shard, $connections[$shard]);
}
```

### Manual Shard Resolution

You can manually resolve which shard a value belongs to:

```php
$shardRouter = $db->getShardRouter();
$config = $shardRouter->getShardConfig('users');

$strategy = // create strategy from config
$shardName = $strategy->resolveShard(500);
```

## Best Practices

1. **Choose appropriate shard key**: The shard key should be:
   - Present in most queries
   - Evenly distributed (for hash/modulo) or sequential (for range)
   - Never changed after creation

2. **Keep table structure consistent**: All shards must have the same table structure.

3. **Handle shard key absence**: If a query doesn't include the shard key, it will fall back to normal routing. Consider:
   - Adding the shard key to WHERE conditions when possible
   - Using a different query strategy for queries without shard key
   - Implementing cross-shard queries if needed

4. **Monitor shard distribution**: Regularly check that data is evenly distributed across shards.

5. **Plan for shard migration**: When adding or removing shards, you may need to migrate data.

## Limitations

- Sharding only works when the shard key is present in WHERE conditions
- Cross-shard queries are not supported (queries that need data from multiple shards)
- Transactions spanning multiple shards are not supported
- JOINs between sharded tables are not supported

## Testing

For testing, you can use multiple SQLite in-memory databases to simulate shards:

```php
$db->addConnection('shard1', ['driver' => 'sqlite', 'path' => ':memory:']);
$db->addConnection('shard2', ['driver' => 'sqlite', 'path' => ':memory:']);

// Configure sharding with in-memory databases
$db->shard('users')
    ->shardKey('user_id')
    ->strategy('range')
    ->ranges([
        'shard1' => [0, 1000],
        'shard2' => [1001, 2000],
    ])
    ->nodes([
        'shard1' => ['driver' => 'sqlite', 'path' => ':memory:'],
        'shard2' => ['driver' => 'sqlite', 'path' => ':memory:'],
    ])
    ->register();
```

This allows you to test sharding functionality without setting up multiple database instances.
