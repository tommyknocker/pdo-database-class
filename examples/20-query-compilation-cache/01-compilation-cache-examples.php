<?php
/**
 * Example 21: Query Compilation Cache
 * 
 * Demonstrates query compilation caching for improved performance
 * when building queries with identical structures.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\cache\QueryCompilationCache;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

$db = createExampleDb();
$driver = getCurrentDriver($db);

// Setup cache
$cache = new ArrayCache();

// Create database with compilation cache enabled
$dbWithCache = new PdoDb($driver, getExampleConfig(), [], null, $cache);

echo "=== Query Compilation Cache Examples (on $driver) ===\n\n";

// Setup
recreateTable($dbWithCache, 'products', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT',
    'price' => 'DECIMAL(10, 2)',
    'category' => 'TEXT',
    'stock' => 'INTEGER',
    'active' => 'INTEGER DEFAULT 1',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

// Insert test data
echo "1. Inserting test data...\n";
$products = [];
for ($i = 1; $i <= 100; $i++) {
    $products[] = [
        'name' => "Product {$i}",
        'price' => round(rand(100, 10000) / 100, 2),
        'category' => ['Electronics', 'Clothing', 'Food', 'Books'][$i % 4],
        'stock' => rand(0, 1000),
        'active' => $i % 5 === 0 ? 0 : 1
    ];
}

// Insert in batches
foreach (array_chunk($products, 50) as $batch) {
    $dbWithCache->find()->table('products')->insertMulti($batch);
}
echo "✓ Inserted " . count($products) . " products\n\n";

// Example 1: Demonstrate compilation cache
echo "2. Demonstrating compilation cache...\n";
$compilationCache = $dbWithCache->getCompilationCache();

if ($compilationCache === null) {
    echo "⚠ Compilation cache not available (no cache backend provided)\n\n";
} else {
    echo "  Compilation cache enabled: " . ($compilationCache->isEnabled() ? 'Yes' : 'No') . "\n";
    echo "  Cache TTL: " . $compilationCache->getDefaultTtl() . " seconds\n";
    echo "  Cache prefix: " . $compilationCache->getPrefix() . "\n\n";
}

// Example 2: Same query structure, different parameters
echo "3. Same query structure with different parameters...\n";

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    $dbWithCache->find()
        ->from('products')
        ->where('active', 1)
        ->andWhere('stock', 10, '>')
        ->orderBy('price', 'DESC')
        ->limit(10)
        ->toSQL(); // Only compile, don't execute
}
$compileTime = microtime(true) - $start;

echo "  Compiled 100 queries: " . round($compileTime * 1000, 2) . "ms\n";
echo "  Average: " . round($compileTime * 10, 2) . "ms per query\n\n";

// Example 3: Performance comparison
echo "4. Performance comparison...\n";

// Without compilation cache
$dbNoCache = createExampleDb();

$start = microtime(true);
for ($i = 0; $i < 50; $i++) {
    $dbNoCache->find()
        ->from('products')
        ->where('category', 'Electronics')
        ->andWhere('price', 50, '>=')
        ->orderBy('name')
        ->limit(20)
        ->toSQL();
}
$timeWithoutCache = microtime(true) - $start;

// With compilation cache
$start = microtime(true);
for ($i = 0; $i < 50; $i++) {
    $dbWithCache->find()
        ->from('products')
        ->where('category', 'Electronics')
        ->andWhere('price', 50, '>=')
        ->orderBy('name')
        ->limit(20)
        ->toSQL();
}
$timeWithCache = microtime(true) - $start;

$improvement = $timeWithoutCache > 0 
    ? round((1 - $timeWithCache / $timeWithoutCache) * 100, 1) 
    : 0;

echo "  Without compilation cache: " . round($timeWithoutCache * 1000, 2) . "ms\n";
echo "  With compilation cache: " . round($timeWithCache * 1000, 2) . "ms\n";
echo "  Performance improvement: {$improvement}%\n\n";

// Example 4: Cache hit demonstration
echo "5. Cache hit demonstration...\n";

$compilationCache = $dbWithCache->getCompilationCache();
if ($compilationCache !== null) {
    // Clear cache to start fresh
    $cache->clear();
    
    $start = microtime(true);
    
    // First query - cache miss (compiles)
    $sql1 = $dbWithCache->find()
        ->from('products')
        ->where('category', 'Clothing')
        ->orderBy('id')
        ->toSQL();
    
    $firstCall = microtime(true) - $start;
    
    $start = microtime(true);
    
    // Second query - same structure, cache hit
    $sql2 = $dbWithCache->find()
        ->from('products')
        ->where('category', 'Food') // Different parameter, same structure
        ->orderBy('id')
        ->toSQL();
    
    $secondCall = microtime(true) - $start;
    
    echo "  First call (cache miss): " . round($firstCall * 1000, 3) . "ms\n";
    echo "  Second call (cache hit): " . round($secondCall * 1000, 3) . "ms\n";
    
    if ($firstCall > 0 && $secondCall < $firstCall) {
        $speedup = round($firstCall / $secondCall, 1);
        echo "  Cache speedup: {$speedup}x faster\n";
    }
    
    echo "  SQL structure matches: " . ($sql1['sql'] === $sql2['sql'] ? 'Yes' : 'No') . "\n";
    echo "  Parameters differ: " . (json_encode($sql1['params']) !== json_encode($sql2['params']) ? 'Yes' : 'No') . "\n\n";
}

// Example 5: Real-world scenario
echo "6. Real-world scenario: API endpoint with filters...\n";

function getProducts($dbWithCache, $category = null, $minPrice = null, $maxPrice = null, $inStock = true) {
    $query = $dbWithCache->find()->from('products');
    
    if ($category !== null) {
        $query->where('category', $category);
    }
    
    if ($minPrice !== null) {
        $query->where('price', $minPrice, '>=');
    }
    
    if ($maxPrice !== null) {
        $query->where('price', $maxPrice, '<=');
    }
    
    if ($inStock) {
        $query->where('stock', 0, '>');
    }
    
    return $query->orderBy('price', 'DESC')->limit(20)->get();
}

$start = microtime(true);

// Simulate API requests with different filters but same structure
getProducts($dbWithCache, 'Electronics', 10, 100, true);
getProducts($dbWithCache, 'Electronics', 20, 150, true);
getProducts($dbWithCache, 'Electronics', 30, 200, true);
getProducts($dbWithCache, 'Clothing', 10, 100, true);
getProducts($dbWithCache, 'Clothing', 20, 150, true);
getProducts($dbWithCache, 'Food', 5, 50, true);

$apiTime = microtime(true) - $start;

echo "  Processed 6 API requests: " . round($apiTime * 1000, 2) . "ms\n";
echo "  Average per request: " . round($apiTime * 1000 / 6, 2) . "ms\n\n";

// Example 6: Configuration
echo "7. Compilation cache configuration...\n";

$compilationCache = $dbWithCache->getCompilationCache();
if ($compilationCache !== null) {
    echo "  Current TTL: " . $compilationCache->getDefaultTtl() . " seconds\n";
    echo "  Current prefix: " . $compilationCache->getPrefix() . "\n";
    
    // Modify settings
    $compilationCache->setDefaultTtl(7200); // 2 hours
    $compilationCache->setPrefix('app_compiled_');
    
    echo "  Updated TTL: " . $compilationCache->getDefaultTtl() . " seconds\n";
    echo "  Updated prefix: " . $compilationCache->getPrefix() . "\n\n";
}

echo "Query compilation cache examples completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Compilation cache automatically enabled when PSR-16 cache provided\n";
echo "  • Caches SQL structure, not parameter values\n";
echo "  • Provides 10-30% performance improvement for repeated query patterns\n";
echo "  • Uses SHA-256 for reliable cache key generation\n";
echo "  • Works transparently with existing query builder\n";
echo "  • Shares same cache backend as query result caching\n";
