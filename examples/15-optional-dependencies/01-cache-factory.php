<?php

declare(strict_types=1);

/**
 * Example: Using CacheFactory for Optional Cache Adapters.
 *
 * Demonstrates how to use CacheFactory to create cache adapters
 * from optional dependencies (symfony/cache, ext-apcu, ext-redis).
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\cache\CacheFactory;
use tommyknocker\pdodb\PdoDb;

echo "=== CacheFactory Examples ===\n\n";

// Get database configuration
$config = getExampleConfig();
$driver = $config['driver'] ?? 'sqlite';

echo "1. Filesystem Cache (requires symfony/cache):\n";
$fsConfig = [
    'type' => 'filesystem',
    'directory' => sys_get_temp_dir() . '/pdodb_cache_example',
    'namespace' => 'example',
    'default_lifetime' => 3600,
];

$fsCache = CacheFactory::create($fsConfig);
if ($fsCache !== null) {
    echo "   ✓ Filesystem cache created\n";
    $fsCache->set('test_key', 'test_value', 60);
    echo '   ✓ Cache set/get works: ' . ($fsCache->get('test_key') === 'test_value' ? 'OK' : 'FAIL') . "\n";
    $fsCache->clear();
} else {
    echo "   ✗ Filesystem cache not available (symfony/cache not installed)\n";
}

echo "\n2. APCu Cache (requires ext-apcu and symfony/cache):\n";
if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
    $apcuConfig = [
        'type' => 'apcu',
        'namespace' => 'pdodb_example',
    ];

    $apcuCache = CacheFactory::create($apcuConfig);
    if ($apcuCache !== null) {
        echo "   ✓ APCu cache created\n";
        $apcuCache->set('test_key', 'test_value', 60);
        echo '   ✓ Cache set/get works: ' . ($apcuCache->get('test_key') === 'test_value' ? 'OK' : 'FAIL') . "\n";
        $apcuCache->delete('test_key');
    } else {
        echo "   ✗ APCu cache not available (symfony/cache not installed)\n";
    }
} else {
    echo "   ✗ APCu not available (ext-apcu not installed or not enabled)\n";
}

echo "\n3. Redis Cache (requires ext-redis and symfony/cache):\n";
if (extension_loaded('redis')) {
    $redisConfig = [
        'type' => 'redis',
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 15, // Use database 15 for testing
        'namespace' => 'pdodb_example',
    ];

    $redisCache = CacheFactory::create($redisConfig);
    if ($redisCache !== null) {
        echo "   ✓ Redis cache created\n";
        $redisCache->set('test_key', 'test_value', 60);
        echo '   ✓ Cache set/get works: ' . ($redisCache->get('test_key') === 'test_value' ? 'OK' : 'FAIL') . "\n";
        $redisCache->clear();
    } else {
        echo "   ✗ Redis cache not available (symfony/cache not installed or Redis server not running)\n";
    }
} else {
    echo "   ✗ Redis not available (ext-redis not installed)\n";
}

echo "\n4. Using Cache with PdoDb:\n";
$cacheConfig = [
    'type' => 'filesystem',
    'directory' => sys_get_temp_dir() . '/pdodb_cache_example',
];

$cache = CacheFactory::create($cacheConfig);
if ($cache !== null) {
    $db = new PdoDb($driver, $config, [], null, $cache);
    echo "   ✓ PdoDb created with cache\n";

    // Setup test table
    $schema = $db->schema();
    $schema->dropTableIfExists('cache_test');
    $schema->createTable('cache_test', [
        'id' => $schema->primaryKey(),
        'name' => $schema->string(100),
        'created_at' => $schema->timestamp(),
        'updated_at' => $schema->timestamp(),
    ]);

    // Insert test data
    $db->find()->from('cache_test')->insert(['name' => 'Test User']);

    // Query with caching
    $users1 = $db->find()->from('cache_test')->get();
    echo "   ✓ First query executed (may be cached)\n";

    $users2 = $db->find()->from('cache_test')->get();
    echo "   ✓ Second query executed (may use cache)\n";

    // Cleanup
    $schema->dropTableIfExists('cache_test');
    $cache->clear();
} else {
    echo "   ✗ Cache not available\n";
}

echo "\n✓ Examples completed\n";
