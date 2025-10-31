# Query Caching

PDOdb provides built-in support for query result caching using PSR-16 (Simple Cache) compatible implementations. This feature can significantly improve application performance by reducing database load and query execution time.

## Table of Contents

- [Overview](#overview)
- [Setup](#setup)
- [Basic Usage](#basic-usage)
- [Cache Methods](#cache-methods)
- [Cache Configuration](#cache-configuration)
- [Cache Key Generation](#cache-key-generation)
- [PSR-16 Implementations](#psr-16-implementations)
- [Best Practices](#best-practices)
- [Performance Considerations](#performance-considerations)
- [Troubleshooting](#troubleshooting)

## Overview

Query caching stores the results of SELECT queries in a cache layer, allowing subsequent identical queries to retrieve results from cache instead of executing against the database.

### Benefits

- **Reduced Database Load**: Fewer queries executed
- **Faster Response Times**: Cache reads are typically 10-100x faster than database queries
- **Scalability**: Better handling of high-traffic scenarios
- **Cost Savings**: Lower database server resource usage

### When to Use Caching

✅ **Good candidates for caching**:
- Configuration and settings
- Product catalogs
- User profiles (with appropriate TTL)
- Aggregate reports and statistics
- Static or rarely-changing content
- Expensive JOIN queries
- Complex aggregations

❌ **Poor candidates for caching**:
- Real-time data (stock prices, live scores)
- Frequently updated records
- User-specific data with high variability
- Write-heavy tables
- Data requiring strong consistency

## Setup

### 1. Install a PSR-16 Cache Implementation

```bash
# Symfony Cache (recommended)
composer require symfony/cache

# Or other implementations
composer require cache/filesystem-adapter
composer require predis/predis
```

### 2. Create Cache Instance

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use tommyknocker\pdodb\PdoDb;

// Create PSR-16 cache
$adapter = new FilesystemAdapter();
$cache = new Psr16Cache($adapter);

// Pass cache to PdoDb constructor
$db = new PdoDb(
    'mysql',
    [
        'host' => 'localhost',
        'dbname' => 'myapp',
        'username' => 'user',
        'password' => 'pass',
    ],
    [],
    null,
    $cache  // PSR-16 cache instance
);
```

### 3. Optional: Configure Cache Behavior

```php
$db = new PdoDb(
    'mysql',
    [
        'host' => 'localhost',
        'dbname' => 'myapp',
        'username' => 'user',
        'password' => 'pass',
        'cache' => [
            'prefix' => 'myapp_',        // Cache key prefix
            'default_ttl' => 7200,       // Default TTL (seconds)
            'enabled' => true,           // Global enable/disable
        ],
    ],
    [],
    null,
    $cache
);
```

## Basic Usage

### Enable Caching for a Query

Use the `cache()` method to enable caching for a specific query:

```php
// Cache for 1 hour (3600 seconds)
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->cache(3600)
    ->get();
```

### Custom Cache Key

Provide a custom key for easier cache management:

```php
$settings = $db->find()
    ->from('settings')
    ->cache(3600, 'app_settings')
    ->get();
```

### Disable Caching

Use `noCache()` to explicitly disable caching:

```php
$data = $db->find()
    ->from('products')
    ->cache(3600)  // Enable cache
    ->noCache()    // But then disable it
    ->get();       // This query won't be cached
```

## Cache Methods

### cache(int $ttl, ?string $key = null)

Enable caching for the current query.

**Parameters**:
- `$ttl` - Time-to-live in seconds
- `$key` - Optional custom cache key (auto-generated if null)

**Returns**: `self` (for method chaining)

```php
// Auto-generated key
$result = $db->find()
    ->from('products')
    ->where('category', 'Electronics')
    ->cache(1800)  // 30 minutes
    ->get();

// Custom key
$result = $db->find()
    ->from('products')
    ->where('featured', 1)
    ->cache(3600, 'featured_products')
    ->get();
```

### noCache()

Disable caching for the current query.

**Returns**: `self` (for method chaining)

```php
$result = $db->find()
    ->from('users')
    ->noCache()
    ->get();
```

## Works With All Fetch Methods

Caching works seamlessly with all result-fetching methods:

### get() - Fetch All Rows

```php
$products = $db->find()
    ->from('products')
    ->where('stock', 0, '>')
    ->cache(600)
    ->get();
```

### getOne() - Fetch Single Row

```php
$user = $db->find()
    ->from('users')
    ->where('email', $email)
    ->cache(1800)
    ->getOne();
```

### getValue() - Fetch Single Value

```php
$count = $db->find()
    ->from('orders')
    ->select([Db::count('*', 'total')])
    ->where('status', 'pending')
    ->cache(300)
    ->getValue();
```

### getColumn() - Fetch Column Values

```php
$usernames = $db->find()
    ->from('users')
    ->select('username')
    ->where('active', 1)
    ->cache(600)
    ->getColumn();
```

## Cache Configuration

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `prefix` | `string` | `'pdodb_'` | Cache key prefix for namespacing |
| `default_ttl` | `int` | `3600` | Default TTL in seconds |
| `enabled` | `bool` | `true` | Global cache enable/disable |

### Example Configuration

```php
$config = [
    'host' => 'localhost',
    'dbname' => 'myapp',
    'username' => 'user',
    'password' => 'pass',
    'cache' => [
        'prefix' => 'myapp_v1_',
        'default_ttl' => 7200,
        'enabled' => true,
    ],
];

$db = new PdoDb('mysql', $config, [], null, $cache);
```

### Accessing Configuration

```php
$cacheManager = $db->getCacheManager();
$config = $cacheManager->getConfig();

echo "Prefix: " . $config->getPrefix() . "\n";
echo "Default TTL: " . $config->getDefaultTtl() . " seconds\n";
echo "Enabled: " . ($config->isEnabled() ? 'Yes' : 'No') . "\n";
```

## Cache Key Generation

### Auto-Generated Keys

When no custom key is provided, cache keys are automatically generated using:
- SQL query string
- Query parameters
- Database driver name

This ensures different queries (or same query with different parameters) get different cache entries.

```php
// These create different cache entries
$result1 = $db->find()->from('users')->where('age', 25, '>')->cache(3600)->get();
$result2 = $db->find()->from('users')->where('age', 30, '>')->cache(3600)->get();
```

### Custom Keys

Custom keys are useful for:
- Easier cache invalidation
- Shared caching logic
- Debugging and monitoring

```php
// Set cache with custom key
$products = $db->find()
    ->from('products')
    ->where('featured', 1)
    ->cache(3600, 'featured_products')
    ->get();

// Later, invalidate if needed
$cacheManager = $db->getCacheManager();
$cacheManager->delete('featured_products');
```

### Key Prefix

All cache keys are automatically prefixed based on configuration:

```php
// With prefix 'myapp_'
$cache->cache(3600, 'users');
// Actual key: 'myapp_users'

// Auto-generated keys also get prefix
$cache->cache(3600);
// Actual key: 'myapp_[hash]'
```

## PSR-16 Implementations

PDOdb works with any PSR-16 compatible cache. Here are recommended implementations:

### 1. Symfony Cache (Recommended)

**Filesystem Cache**:
```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$adapter = new FilesystemAdapter(
    namespace: 'myapp',
    defaultLifetime: 0,
    directory: '/tmp/cache'
);
$cache = new Psr16Cache($adapter);
```

**Redis Cache**:
```php
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

$redis = RedisAdapter::createConnection('redis://localhost:6379');
$adapter = new RedisAdapter($redis);
$cache = new Psr16Cache($adapter);
```

**APCu Cache** (High Performance):
```php
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Psr16Cache;

$adapter = new ApcuAdapter(namespace: 'myapp');
$cache = new Psr16Cache($adapter);
```

**Memcached Cache**:
```php
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Psr16Cache;

$client = MemcachedAdapter::createConnection('memcached://localhost:11211');
$adapter = new MemcachedAdapter($client);
$cache = new Psr16Cache($adapter);
```

### 2. PHP Cache

```bash
composer require cache/filesystem-adapter
```

```php
use Cache\Adapter\Filesystem\FilesystemCachePool;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

$filesystemAdapter = new Local(__DIR__.'/cache/');
$filesystem = new Filesystem($filesystemAdapter);
$cache = new FilesystemCachePool($filesystem);
```

### 3. Array Cache (Testing)

For unit tests and development:

```php
use tommyknocker\pdodb\tests\ArrayCache;

$cache = new ArrayCache();
$db = new PdoDb('sqlite', ['path' => ':memory:'], [], null, $cache);
```

## Best Practices

### 1. Choose Appropriate TTL

```php
// Short TTL for frequently changing data
$recentOrders = $db->find()
    ->from('orders')
    ->where('created_at', date('Y-m-d'), '>')
    ->cache(300)  // 5 minutes
    ->get();

// Long TTL for static data
$categories = $db->find()
    ->from('categories')
    ->cache(86400)  // 24 hours
    ->get();

// Very long TTL for configuration
$settings = $db->find()
    ->from('app_settings')
    ->cache(604800, 'app_settings')  // 1 week
    ->get();
```

### 2. Use Custom Keys for Important Data

```php
// Easy to invalidate later
$featuredProducts = $db->find()
    ->from('products')
    ->where('featured', 1)
    ->cache(3600, 'featured_products')
    ->get();

// When product is updated
$db->find()->table('products')->where('id', $productId)->update(['featured' => 1]);
$db->getCacheManager()->delete('featured_products');
```

### 3. Cache Expensive Queries

```php
// Complex aggregation - good candidate for caching
$report = $db->find()
    ->from('orders')
    ->select([
        Db::raw('DATE(created_at) as date'),
        Db::count('*', 'total_orders'),
        Db::sum('total_amount', 'revenue'),
    ])
    ->where('created_at', date('Y-m-d', strtotime('-30 days')), '>=')
    ->groupBy('date')
    ->cache(3600, 'monthly_report')
    ->get();
```

### 4. Conditional Caching

```php
function getProducts($category, $useCache = true) {
    $query = $db->find()
        ->from('products')
        ->where('category', $category);
    
    if ($useCache) {
        $query->cache(1800);
    }
    
    return $query->get();
}

// Development: no cache
$products = getProducts('Electronics', false);

// Production: use cache
$products = getProducts('Electronics', true);
```

### 5. Monitor Cache Performance

```php
// Track cache hits/misses
$cacheManager = $db->getCacheManager();
$startTime = microtime(true);

$result = $db->find()
    ->from('products')
    ->cache(3600, 'all_products')
    ->get();

$duration = microtime(true) - $startTime;
error_log("Query duration: {$duration}s");
```

## Performance Considerations

### Cache vs Database Performance

| Operation | Database | Cache (Memory) | Cache (Redis) | Speedup |
|-----------|----------|----------------|---------------|---------|
| Simple SELECT | 5-10ms | 0.1-0.5ms | 1-2ms | 5-100x |
| Complex JOIN | 50-500ms | 0.1-0.5ms | 1-2ms | 50-500x |
| Aggregation | 100-1000ms | 0.1-0.5ms | 1-2ms | 100-1000x |

### Memory Considerations

**Cache Size Estimation**:
```
Average Row Size: 1 KB
Rows per Query: 100
Queries Cached: 1000
Total Memory: 100 MB
```

**Recommendations**:
- Monitor cache size in production
- Set appropriate TTL to limit growth
- Use Redis/Memcached for large datasets
- Clear cache periodically

### Network Overhead

**Local Cache** (APCu, File):
- Latency: <0.1ms
- Best for single-server deployments

**Remote Cache** (Redis, Memcached):
- Latency: 1-5ms
- Best for multi-server deployments
- Adds network round-trip time

## Troubleshooting

### Cache Not Working

**Check if cache is enabled**:
```php
$cacheManager = $db->getCacheManager();
if ($cacheManager === null) {
    echo "No cache manager configured\n";
} else {
    $config = $cacheManager->getConfig();
    echo "Cache enabled: " . ($config->isEnabled() ? 'Yes' : 'No') . "\n";
}
```

**Verify cache() is called**:
```php
// This won't cache
$result = $db->find()->from('users')->get();

// This will cache
$result = $db->find()->from('users')->cache(3600)->get();
```

### Stale Data

**Symptoms**: Cached data doesn't reflect recent updates

**Solutions**:

1. **Reduce TTL**:
```php
$data = $db->find()->from('products')->cache(300)->get(); // 5 minutes instead of 1 hour
```

2. **Manual Invalidation**:
```php
// After update
$db->find()->table('products')->where('id', $id)->update($data);
$db->getCacheManager()->delete('product_list');
```

3. **Disable cache for writes**:
```php
// Always fetch fresh after write
$db->find()->table('products')->where('id', $id)->update($data);
$product = $db->find()->from('products')->where('id', $id)->noCache()->getOne();
```

### Memory Issues

**Symptoms**: Out of memory errors, slow cache performance

**Solutions**:

1. **Use distributed cache** (Redis, Memcached)
2. **Reduce cache TTL**
3. **Limit cached result size**:
```php
// Don't cache huge result sets
$query = $db->find()->from('logs')->limit(1000);
if ($query->limit <= 100) {
    $query->cache(600);
}
$results = $query->get();
```

### Different Results from Cache

**Symptoms**: Cached results differ from database

**Cause**: Parameters or query changed, but same cache key used

**Solution**: Use unique cache keys or rely on auto-generation:
```php
// Bad - reuses same key
$result1 = $db->find()->from('users')->where('age', 25, '>')->cache(3600, 'users')->get();
$result2 = $db->find()->from('users')->where('age', 30, '>')->cache(3600, 'users')->get();

// Good - different keys
$result1 = $db->find()->from('users')->where('age', 25, '>')->cache(3600, 'users_over_25')->get();
$result2 = $db->find()->from('users')->where('age', 30, '>')->cache(3600, 'users_over_30')->get();

// Best - auto-generated keys
$result1 = $db->find()->from('users')->where('age', 25, '>')->cache(3600)->get();
$result2 = $db->find()->from('users')->where('age', 30, '>')->cache(3600)->get();
```

## Examples

See [examples/14-caching/](../../examples/14-caching/) for complete working examples.

## Related Documentation

- [Performance Optimization](../08-best-practices/performance.md)
- [Connection Management](../02-core-concepts/connection-management.md)
- [Query Builder Basics](../02-core-concepts/query-builder-basics.md)
