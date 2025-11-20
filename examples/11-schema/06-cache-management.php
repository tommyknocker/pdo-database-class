<?php

declare(strict_types=1);

/**
 * Cache Management Examples.
 *
 * This example demonstrates how to use the pdodb cache command
 * to manage query result cache (clear, invalidate, statistics).
 *
 * Usage:
 *   php examples/11-schema/06-cache-management.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

// Get database configuration
$config = getExampleConfig();
$driver = $config['driver'] ?? 'sqlite';

echo "PDOdb Cache Management Examples\n";
echo "================================\n\n";
echo "Driver: {$driver}\n\n";

// Create cache instance
$cache = new ArrayCache();

// Set cache environment variables for CLI commands to use the same cache
putenv('PDODB_CACHE_ENABLED=true');
putenv('PDODB_CACHE_TYPE=array');
putenv('PDODB_NON_INTERACTIVE=1');

// Create database connection with cache enabled
$db = new PdoDb($driver, $config, [], null, $cache);

// Create a test table
$schema = $db->schema();
$schema->dropTableIfExists('cache_test');
$schema->createTable('cache_test', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100),
    'value' => $schema->integer(),
    'created_at' => $schema->datetime(),
]);

echo "✓ Created test table 'cache_test'\n\n";

// Insert some test data
for ($i = 1; $i <= 10; $i++) {
    $db->find()->table('cache_test')->insert([
        'name' => "Item {$i}",
        'value' => $i * 10,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
echo "✓ Inserted 10 test records\n\n";

// Generate some cache activity
echo "Generating cache activity...\n";
echo "1. First query (cache miss):\n";
$result1 = $db->find()
    ->from('cache_test')
    ->where('value', 50, '>')
    ->cache(3600)
    ->get();
echo "   Found " . count($result1) . " records\n";

echo "2. Same query (cache hit):\n";
$result2 = $db->find()
    ->from('cache_test')
    ->where('value', 50, '>')
    ->cache(3600)
    ->get();
echo "   Found " . count($result2) . " records (from cache)\n";

echo "3. Another query (cache miss):\n";
$result3 = $db->find()
    ->from('cache_test')
    ->where('name', 'Item 1')
    ->cache(3600)
    ->getOne();
echo "   Found: " . ($result3['name'] ?? 'N/A') . "\n";

echo "4. Same query again (cache hit):\n";
$result4 = $db->find()
    ->from('cache_test')
    ->where('name', 'Item 1')
    ->cache(3600)
    ->getOne();
echo "   Found: " . ($result4['name'] ?? 'N/A') . " (from cache)\n\n";

// Demonstrate cache commands using CLI
$app = new Application();

echo "Cache Management Commands:\n";
echo "==========================\n\n";

echo "1. Show Cache Statistics:\n";
echo "   Command: pdodb cache stats\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'cache', 'stats']);
$output = ob_get_clean();
echo $output . "\n";

echo "2. Show Cache Statistics (JSON format):\n";
echo "   Command: pdodb cache stats --format=json\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'cache', 'stats', '--format=json']);
$output = ob_get_clean();
echo $output . "\n";

echo "3. Clear Cache (interactive):\n";
echo "   Command: pdodb cache clear\n";
echo "   (This would normally prompt for confirmation)\n";
echo "   Using --force to skip confirmation:\n";
ob_start();
$app->run(['pdodb', 'cache', 'clear', '--force']);
$output = ob_get_clean();
echo $output . "\n";

echo "4. Show Cache Statistics After Clear:\n";
echo "   Command: pdodb cache stats\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'cache', 'stats']);
$output = ob_get_clean();
echo $output . "\n";

echo "5. Generate More Cache Activity:\n";
$result5 = $db->find()
    ->from('cache_test')
    ->where('value', 30, '>')
    ->cache(3600)
    ->get();
echo "   Found " . count($result5) . " records (cache miss after clear)\n";

$result6 = $db->find()
    ->from('cache_test')
    ->where('value', 30, '>')
    ->cache(3600)
    ->get();
echo "   Found " . count($result6) . " records (cache hit)\n\n";

echo "6. Invalidate Cache by Table Pattern:\n";
echo "   Command: pdodb cache invalidate cache_test --force\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'cache', 'invalidate', 'cache_test', '--force']);
$output = ob_get_clean();
echo $output . "\n";

echo "7. Invalidate Cache by Table Pattern (table: prefix):\n";
echo "   Command: pdodb cache invalidate \"table:cache_test\" --force\n";
// First generate some cache again
$db->find()->from('cache_test')->cache(3600)->get();
ob_start();
$app->run(['pdodb', 'cache', 'invalidate', 'table:cache_test', '--force']);
$output = ob_get_clean();
echo $output . "\n";

echo "8. Final Cache Statistics (from CLI):\n";
echo "   Note: CLI commands create a separate CacheManager instance,\n";
echo "   so statistics may differ from the application's CacheManager.\n";
echo "   Command: pdodb cache stats\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'cache', 'stats']);
$output = ob_get_clean();
echo $output . "\n";

echo "\n9. Direct Cache Statistics (from application's CacheManager):\n";
$cacheManager = $db->getCacheManager();
if ($cacheManager !== null) {
    $stats = $cacheManager->getStats();
    echo "   Hits: {$stats['hits']}\n";
    echo "   Misses: {$stats['misses']}\n";
    echo "   Hit Rate: {$stats['hit_rate']}%\n";
    echo "   Sets: {$stats['sets']}\n";
}

echo "\nAdditional Notes:\n";
echo "- Cache statistics track hits, misses, sets, and deletes\n";
echo "- Hit rate is calculated as: (hits / (hits + misses)) * 100\n";
echo "- Statistics are persistent across requests (stored in cache backend)\n";
echo "- Cache type (Redis, Memcached, APCu, Filesystem, Array) is shown in statistics\n";
echo "- Use --force flag to skip confirmation when clearing/invalidating cache\n";
echo "- Cache clear removes ALL cached query results\n";
echo "- Cache invalidate removes entries matching a pattern (table name, table prefix, or key pattern)\n";
echo "- Supported patterns: 'table:users', 'table:users_*', 'pdodb_table_users_*', 'users'\n";
echo "- Cache statistics help monitor cache effectiveness\n";

// Cleanup
$schema->dropTableIfExists('cache_test');
echo "\n✓ Cleaned up test table\n";

