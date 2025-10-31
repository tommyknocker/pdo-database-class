# Query Compilation Cache

Cache compiled SQL strings for improved query building performance.

## Overview

Query Compilation Cache stores compiled SQL query strings based on query structure, allowing subsequent queries with identical structure patterns to reuse the cached SQL instead of recompiling. This provides a 10-30% performance improvement for applications with repetitive query patterns.

## Key Features

- **Structure-based caching**: Queries with the same structure (columns, joins, conditions structure) share cached SQL
- **Parameter-independent**: Different parameter values don't invalidate cache
- **SHA-256 hashing**: Uses secure SHA-256 algorithm for reliable cache key generation
- **Automatic integration**: Works seamlessly with existing query builder
- **Zero configuration**: Enabled automatically when PSR-16 cache is provided

## How It Works

1. **Query Structure Extraction**: The query builder extracts the structure (table, columns, joins, conditions structure, etc.) without parameter values
2. **Hash Generation**: SHA-256 hash is generated from the normalized structure
3. **Cache Lookup**: Cache is checked for compiled SQL using the hash
4. **Cache Hit**: If found, cached SQL is returned immediately
5. **Cache Miss**: If not found, SQL is compiled, cached, and returned

## Setup

### Automatic Setup

When you provide a PSR-16 cache to `PdoDb`, compilation cache is automatically enabled:

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use tommyknocker\pdodb\PdoDb;

$adapter = new FilesystemAdapter();
$cache = new Psr16Cache($adapter);

// Compilation cache is automatically enabled
$db = new PdoDb('mysql', $config, [], null, $cache);
```

### Manual Configuration

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\cache\QueryCompilationCache;

$db = new PdoDb('mysql', $config, [], null, $cache);

// Configure compilation cache
$db->getCompilationCache()?->setDefaultTtl(3600); // 1 hour
$db->getCompilationCache()?->setPrefix('myapp_compiled_');
```

### Disable Compilation Cache

```php
// Disable compilation cache
$db->getCompilationCache()?->setEnabled(false);

// Or create without cache
$db = new PdoDb('mysql', $config); // No cache = no compilation cache
```

## Configuration Options

### Cache TTL

```php
// Set default TTL (default: 86400 seconds = 24 hours)
$db->getCompilationCache()?->setDefaultTtl(3600); // 1 hour
```

### Cache Prefix

```php
// Set custom prefix for cache keys
$db->getCompilationCache()?->setPrefix('app_compiled_');
```

### Enable/Disable

```php
// Enable compilation cache
$db->getCompilationCache()?->setEnabled(true);

// Disable compilation cache
$db->getCompilationCache()?->setEnabled(false);
```

## Usage Examples

### Basic Usage

```php
// First query - compiles and caches SQL
$users1 = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('id')
    ->get();

// Second query with same structure but different parameter - uses cached SQL
$users2 = $db->find()
    ->from('users')
    ->where('active', 0) // Different value, same structure
    ->orderBy('id')
    ->get();
```

### Performance Comparison

```php
// Without compilation cache
$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $db->find()
        ->from('users')
        ->where('status', 'active')
        ->orderBy('id')
        ->limit(10)
        ->get();
}
$withoutCache = microtime(true) - $start;

// With compilation cache (automatic)
$cache = new Psr16Cache(new FilesystemAdapter());
$dbWithCache = new PdoDb('mysql', $config, [], null, $cache);

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $dbWithCache->find()
        ->from('users')
        ->where('status', 'active')
        ->orderBy('id')
        ->limit(10)
        ->get();
}
$withCache = microtime(true) - $start;

echo "Without cache: " . round($withoutCache * 1000, 2) . "ms\n";
echo "With cache: " . round($withCache * 1000, 2) . "ms\n";
echo "Improvement: " . round((1 - $withCache / $withoutCache) * 100, 1) . "%\n";
```

## What Gets Cached

The compilation cache stores the **SQL structure** without parameter values:

### ✅ Cached (Same Structure)
```php
// These queries share the same cached SQL:
$db->find()->from('users')->where('age', 25)->get();
$db->find()->from('users')->where('age', 30)->get();
$db->find()->from('users')->where('age', 35)->get();
```

### ❌ Different Cache Entries
```php
// Different structures = different cache entries:
$db->find()->from('users')->where('age', 25)->get();        // Cache entry 1
$db->find()->from('users')->where('name', 'John')->get();   // Cache entry 2 (different column)
$db->find()->from('posts')->where('age', 25)->get();        // Cache entry 3 (different table)
```

## Cache Key Generation

Cache keys are generated using:
- Query structure (table, columns, joins, conditions structure, etc.)
- Database driver name
- SHA-256 hash for reliability

```php
// Structure normalized (parameter values removed):
[
    'driver' => 'mysql',
    'table' => 'users',
    'select' => ['id', 'name'],
    'where_structure' => [
        ['type' => 'where', 'column' => 'active', 'operator' => '=', 'has_value' => true]
    ],
    // ... other structure elements
]

// SHA-256 hash generated from normalized structure
```

## Best Practices

### ✅ Good Candidates

- **Repeated query patterns**: Same structure, different parameters
- **High-traffic applications**: Many similar queries
- **Complex queries**: JOINs, subqueries, aggregations
- **API endpoints**: Similar queries with different filters

### ❌ When Not Needed

- **One-off queries**: Queries that run once
- **Constantly changing structures**: Queries with different structures each time
- **Simple queries**: Very simple queries where compilation overhead is minimal

### Cache TTL Considerations

```php
// Short TTL for frequently changing schemas
$db->getCompilationCache()?->setDefaultTtl(3600); // 1 hour

// Long TTL for stable schemas
$db->getCompilationCache()?->setDefaultTtl(86400 * 7); // 7 days
```

## Performance Impact

### Typical Improvements

- **Simple queries**: 10-15% faster
- **Complex queries**: 20-30% faster
- **High-traffic scenarios**: 15-25% faster

### Memory Usage

Compilation cache has minimal memory impact:
- Cache stores only SQL strings (typically a few KB per entry)
- Cache automatically expires based on TTL
- Uses same PSR-16 cache backend as query result caching

## Troubleshooting

### Cache Not Working

1. **Check if cache is enabled**:
```php
var_dump($db->getCompilationCache()?->isEnabled());
```

2. **Verify cache backend**:
```php
$cache = $db->getCompilationCache()?->getCache();
// Should not be null
```

3. **Check cache TTL**:
```php
var_dump($db->getCompilationCache()?->getDefaultTtl());
```

### Clear Compilation Cache

Compilation cache shares the same backend as query result cache. To clear:

```php
$cacheManager = $db->getCacheManager();
$cacheManager?->clear(); // Clears all cache, including compilation cache
```

## Integration with Query Result Caching

Query Compilation Cache works alongside Query Result Caching:

- **Compilation Cache**: Caches the SQL string compilation
- **Result Cache**: Caches the query results

They complement each other:
- Compilation cache speeds up query building
- Result cache speeds up query execution (when results are cached)

```php
// Both caches working together:
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->cache(3600)  // Result cache (1 hour)
    ->get();

// Compilation cache is automatic and transparent
```

## Advanced Usage

### Custom Compilation Cache Instance

```php
use tommyknocker\pdodb\query\cache\QueryCompilationCache;

$compilationCache = new QueryCompilationCache($cache);
$compilationCache->setDefaultTtl(7200); // 2 hours
$compilationCache->setPrefix('custom_');

$db->setCompilationCache($compilationCache);
```

### Disable for Specific Queries

Compilation cache is always enabled when cache is provided, but you can disable it:

```php
// Temporarily disable
$compilationCache = $db->getCompilationCache();
$originalEnabled = $compilationCache?->isEnabled();
$compilationCache?->setEnabled(false);

// Run queries without compilation cache
// ...

// Restore
$compilationCache?->setEnabled($originalEnabled);
```

## Technical Details

### Hash Algorithm

Uses SHA-256 for reliable hash generation without collisions:
- 256-bit hash (64 hex characters)
- Cryptographically secure
- Collision-resistant

### Structure Normalization

Query structure is normalized before hashing:
- Parameter values removed (only structure kept)
- Arrays normalized (order preserved)
- Conditions normalized (types and operators preserved)

### Cache Key Format

```
{prefix}{sha256_hash}
```

Example:
```
pdodb_compiled_a1b2c3d4e5f6...
```
