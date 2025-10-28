# Query Caching Examples

Examples demonstrating query result caching with PSR-16 compatible cache implementations.

## Overview

PDOdb supports PSR-16 (Simple Cache) for caching query results. Caching can significantly improve performance by avoiding repeated database queries.

## Features

- **PSR-16 Compatible**: Works with any PSR-16 cache implementation
- **Auto-generated Keys**: Cache keys are automatically generated from SQL and parameters
- **Custom Keys**: Optional custom cache keys for easier management
- **Flexible TTL**: Per-query time-to-live configuration
- **Method Support**: Works with `get()`, `getOne()`, `getValue()`, `getColumn()`
- **Easy Disable**: `noCache()` method to skip caching for specific queries

## Example

### 01-basic-caching.php

Demonstrates all caching features:

1. **Basic Caching**: Auto-generated keys, cache hits/misses
2. **Custom Keys**: Using memorable keys like `'expensive_products'`
3. **Different Methods**: Caching with `getOne()`, `getValue()`, `getColumn()`
4. **Disable Caching**: Using `noCache()` to skip cache
5. **Parameter Sensitivity**: Different parameters create different cache entries
6. **Custom TTL**: Per-query time-to-live settings
7. **Configuration**: Accessing cache configuration

## PSR-16 Cache Implementations

PDOdb works with any PSR-16 implementation:

### Recommended Implementations

1. **Symfony Cache** (recommended):
```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$adapter = new FilesystemAdapter();
$cache = new Psr16Cache($adapter);
$db = new PdoDb('mysql', $config, [], null, $cache);
```

2. **Redis** (for distributed caching):
```php
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

$redis = RedisAdapter::createConnection('redis://localhost');
$adapter = new RedisAdapter($redis);
$cache = new Psr16Cache($adapter);
$db = new PdoDb('mysql', $config, [], null, $cache);
```

3. **APCu** (for high performance):
```php
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Psr16Cache;

$adapter = new ApcuAdapter();
$cache = new Psr16Cache($adapter);
$db = new PdoDb('mysql', $config, [], null, $cache);
```

4. **ArrayCache** (for testing):
```php
use tommyknocker\pdodb\tests\ArrayCache;

$cache = new ArrayCache();
$db = new PdoDb('sqlite', ['path' => ':memory:'], [], null, $cache);
```

## Cache Configuration

Configure caching behavior through the config array:

```php
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
    'cache' => [
        'prefix' => 'myapp_',        // Cache key prefix (default: 'pdodb_')
        'default_ttl' => 7200,       // Default TTL in seconds (default: 3600)
        'enabled' => true,           // Enable/disable caching (default: true)
    ],
], [], null, $cache);
```

## Usage Patterns

### Pattern 1: Always Cache

```php
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->cache(3600) // 1 hour
    ->get();
```

### Pattern 2: Conditional Caching

```php
$useCache = $_GET['fresh'] ? false : true;

$query = $db->find()
    ->from('products')
    ->where('category', $category);

if ($useCache) {
    $query->cache(1800); // 30 minutes
}

$products = $query->get();
```

### Pattern 3: Named Cache Keys

```php
// Set cache
$db->find()
    ->from('settings')
    ->cache(86400, 'app_settings') // 24 hours
    ->get();

// Invalidate later (if you have access to cache instance)
$cache->delete('app_settings');
```

### Pattern 4: Short-lived Cache

```php
// Cache for just 60 seconds (e.g., rate limiting)
$recentViews = $db->find()
    ->from('page_views')
    ->where('created_at', date('Y-m-d H:i:s', strtotime('-1 hour')), '>')
    ->cache(60)
    ->get();
```

## Performance Benefits

**Without caching**:
- Query 1: 50ms (database query)
- Query 2: 50ms (database query)
- Query 3: 50ms (database query)
- **Total**: 150ms

**With caching**:
- Query 1: 50ms (database query + cache write)
- Query 2: 1ms (cache hit)
- Query 3: 1ms (cache hit)
- **Total**: 52ms (71% faster)

## Best Practices

1. **Cache Expensive Queries**: Focus on slow/complex queries
2. **Appropriate TTL**: Balance freshness vs performance
3. **Custom Keys for Important Data**: Use memorable keys for data you might need to invalidate
4. **Test Without Cache**: Use `noCache()` when debugging
5. **Monitor Cache Size**: Track cache entries to avoid memory issues
6. **Use Distributed Cache for Multi-Server**: Redis/Memcached for load-balanced apps

## When NOT to Cache

❌ **Don't cache**:
- Real-time data (stock prices, live scores)
- User-specific data with high variability
- Write-heavy tables
- Very frequently changing data

✅ **Do cache**:
- Configuration/settings
- Product catalogs
- Static content
- Expensive reports
- Aggregated statistics

## Running Examples

```bash
# SQLite (default)
php 01-basic-caching.php

# MySQL
PDODB_DRIVER=mysql php 01-basic-caching.php

# PostgreSQL
PDODB_DRIVER=pgsql php 01-basic-caching.php
```

## Learn More

- [Query Caching Documentation](../../documentation/05-advanced-features/query-caching.md)
- [PSR-16 Specification](https://www.php-fig.org/psr/psr-16/)
- [Symfony Cache Component](https://symfony.com/doc/current/components/cache.html)

