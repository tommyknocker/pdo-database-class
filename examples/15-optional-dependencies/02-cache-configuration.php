<?php

declare(strict_types=1);

/**
 * Example: Cache Configuration via Environment Variables and Config Files
 *
 * Demonstrates how to configure cache using:
 * - Environment variables (PDODB_CACHE_*)
 * - Config files (config/db.php)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\cache\CacheFactory;

echo "=== Cache Configuration Examples ===\n\n";

// Example 1: Configuration via array (programmatic)
echo "1. Programmatic Configuration:\n";
$cacheConfig = [
    'enabled' => true,
    'type' => 'filesystem',
    'directory' => sys_get_temp_dir() . '/pdodb_cache_config',
    'namespace' => 'app',
    'default_lifetime' => 7200, // 2 hours
    'prefix' => 'myapp_',
];

$cache = CacheFactory::create($cacheConfig);
if ($cache !== null) {
    echo "   ✓ Cache created from array config\n";
    echo "   - Type: filesystem\n";
    echo "   - Directory: " . $cacheConfig['directory'] . "\n";
    echo "   - Namespace: " . $cacheConfig['namespace'] . "\n";
    echo "   - TTL: " . $cacheConfig['default_lifetime'] . " seconds\n";
    $cache->clear();
} else {
    echo "   ✗ Cache not available\n";
}

// Example 2: Redis configuration
echo "\n2. Redis Configuration:\n";
if (extension_loaded('redis')) {
    $redisConfig = [
        'enabled' => true,
        'type' => 'redis',
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 15,
        'password' => null, // Set if Redis requires password
        'namespace' => 'pdodb',
        'default_lifetime' => 3600,
    ];

    $redisCache = CacheFactory::create($redisConfig);
    if ($redisCache !== null) {
        echo "   ✓ Redis cache created\n";
        echo "   - Host: " . $redisConfig['host'] . "\n";
        echo "   - Port: " . $redisConfig['port'] . "\n";
        echo "   - Database: " . $redisConfig['database'] . "\n";
        $redisCache->clear();
    } else {
        echo "   ✗ Redis cache not available (ext-redis not installed, symfony/cache not installed, or Redis server not running)\n";
    }
} else {
    echo "   ✗ Redis extension not available\n";
}

// Example 3: Using with PdoDb config
echo "\n3. Cache Configuration in PdoDb:\n";
$dbConfig = [
    'driver' => 'sqlite',
    'path' => ':memory:',
    'cache' => [
        'enabled' => true,
        'type' => 'filesystem',
        'directory' => sys_get_temp_dir() . '/pdodb_cache_db',
        'default_lifetime' => 1800,
        'prefix' => 'db_',
    ],
];

$dbCache = CacheFactory::create($dbConfig['cache']);
if ($dbCache !== null) {
    $db = new PdoDb('sqlite', $dbConfig, [], null, $dbCache);
    echo "   ✓ PdoDb created with cache from config\n";
    echo "   - Cache prefix: db_\n";
    echo "   - Cache TTL: 1800 seconds\n";
    $dbCache->clear();
} else {
    echo "   ✗ Cache not available\n";
}

// Example 4: Environment variables (simulated)
echo "\n4. Environment Variables Configuration:\n";
echo "   You can configure cache using environment variables:\n";
echo "   - PDODB_CACHE_ENABLED=true\n";
echo "   - PDODB_CACHE_TYPE=filesystem|redis|apcu|memcached\n";
echo "   - PDODB_CACHE_DIRECTORY=/path/to/cache\n";
echo "   - PDODB_CACHE_REDIS_HOST=127.0.0.1\n";
echo "   - PDODB_CACHE_REDIS_PORT=6379\n";
echo "   - PDODB_CACHE_REDIS_DATABASE=0\n";
echo "   - PDODB_CACHE_REDIS_PASSWORD=secret\n";
echo "   - PDODB_CACHE_NAMESPACE=myapp\n";
echo "   - PDODB_CACHE_TTL=3600\n";
echo "   - PDODB_CACHE_PREFIX=app_\n";

echo "\n✓ Configuration examples completed\n";

