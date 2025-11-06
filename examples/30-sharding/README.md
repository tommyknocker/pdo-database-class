# Sharding Examples

This directory contains examples demonstrating the sharding functionality of PdoDb.

## Overview

Sharding allows you to horizontally partition data across multiple database instances (shards) based on a shard key. This is useful for scaling large datasets by distributing them across multiple databases.

## Examples

### 01-basic-sharding.php

Demonstrates basic sharding functionality with range-based strategy:

- Configure sharding for a table
- Insert data (automatically routed to appropriate shard)
- Query data (automatically routed to appropriate shard)
- Update data (automatically routed to appropriate shard)
- Delete data (automatically routed to appropriate shard)

### 02-sharding-strategies.php

Demonstrates different sharding strategies:

- **Range Strategy**: Distributes data based on numeric ranges
- **Hash Strategy**: Distributes data based on hash of the shard key
- **Modulo Strategy**: Distributes data based on modulo operation

## Usage

Run examples with:

```bash
# Using SQLite (default, uses in-memory databases)
php examples/30-sharding/01-basic-sharding.php

# Using MySQL
PDODB_DRIVER=mysql php examples/30-sharding/01-basic-sharding.php

# Using PostgreSQL
PDODB_DRIVER=pgsql php examples/30-sharding/01-basic-sharding.php
```

## Configuration

Sharding is configured using the fluent API:

```php
$db->shard('users')
    ->shardKey('user_id')
    ->strategy('range') // or 'hash', 'modulo'
    ->ranges([
        'shard1' => [0, 1000],
        'shard2' => [1001, 2000],
        'shard3' => [2001, 3000],
    ])
    ->nodes([
        'shard1' => ['driver' => 'sqlite', 'path' => ':memory:'],
        'shard2' => ['driver' => 'sqlite', 'path' => ':memory:'],
        'shard3' => ['driver' => 'sqlite', 'path' => ':memory:'],
    ])
    ->register();
```

## Important Notes

- Sharding requires the shard key to be present in WHERE conditions for queries
- If the shard key is not found in WHERE conditions, the query will fall back to normal routing
- Each shard connection must have the same table structure
- For production use, each shard should be a separate database instance

