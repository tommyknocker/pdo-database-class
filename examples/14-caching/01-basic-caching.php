<?php
/**
 * Example 01: Basic Query Caching
 *
 * Demonstrates basic query result caching with PSR-16 cache.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\ArrayCache; // Using test cache for demonstration

echo "=== Basic Query Caching Examples ===\n\n";

// Create a PSR-16 cache instance
// In production, use symfony/cache, predis/predis, or other PSR-16 implementation
$cache = new ArrayCache();

// Create database with cache (SQLite for simplicity)
$db = new PdoDb(
    'sqlite',
    ['path' => ':memory:'],
    [],
    null,
    $cache
);

// Setup table
$db->rawQuery('CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    category TEXT,
    price REAL,
    stock INTEGER
)');

$products = [
    ['name' => 'Laptop', 'category' => 'Electronics', 'price' => 1299.99, 'stock' => 15],
    ['name' => 'Mouse', 'category' => 'Electronics', 'price' => 29.99, 'stock' => 50],
    ['name' => 'Keyboard', 'category' => 'Electronics', 'price' => 89.99, 'stock' => 30],
    ['name' => 'Monitor', 'category' => 'Electronics', 'price' => 399.99, 'stock' => 20],
    ['name' => 'Desk', 'category' => 'Furniture', 'price' => 499.99, 'stock' => 5],
    ['name' => 'Chair', 'category' => 'Furniture', 'price' => 299.99, 'stock' => 8],
];

$db->find()->table('products')->insertMulti($products);
echo "✓ Inserted " . count($products) . " products\n\n";

// Example 1: Basic caching with auto-generated key
echo "1. Basic caching (auto-generated key)...\n";
echo "   Cache entries before: {$cache->count()}\n";

$result1 = $db->find()
    ->from('products')
    ->where('category', 'Electronics')
    ->cache(3600) // Cache for 1 hour
    ->get();

echo "   Found " . count($result1) . " electronics\n";
echo "   Cache entries after first query: {$cache->count()}\n";

// Same query - should hit cache
$result2 = $db->find()
    ->from('products')
    ->where('category', 'Electronics')
    ->cache(3600)
    ->get();

echo "   Found " . count($result2) . " electronics (from cache)\n";
echo "   Cache entries after second query: {$cache->count()}\n\n";

// Example 2: Custom cache key
echo "2. Custom cache key...\n";
$result3 = $db->find()
    ->from('products')
    ->where('price', 100, '>')
    ->cache(3600, 'expensive_products')
    ->get();

echo "   Found " . count($result3) . " expensive products\n";
echo "   Cache has 'expensive_products' key: " . ($cache->has('expensive_products') ? 'YES' : 'NO') . "\n\n";

// Example 3: Cache with getOne()
echo "3. Caching with getOne()...\n";
$product = $db->find()
    ->from('products')
    ->where('name', 'Laptop')
    ->cache(3600)
    ->getOne();

echo "   Product: {$product['name']}, Price: \${$product['price']}\n";
echo "   Cache entries: {$cache->count()}\n\n";

// Example 4: Cache with getValue()
echo "4. Caching with getValue()...\n";
$price = $db->find()
    ->from('products')
    ->select('price')
    ->where('name', 'Mouse')
    ->cache(3600)
    ->getValue();

echo "   Mouse price: \${$price}\n";
echo "   Cache entries: {$cache->count()}\n\n";

// Example 5: Cache with getColumn()
echo "5. Caching with getColumn()...\n";
$names = $db->find()
    ->from('products')
    ->select('name')
    ->where('category', 'Furniture')
    ->orderBy('name', 'ASC')
    ->cache(3600)
    ->getColumn();

echo "   Furniture items: " . implode(', ', $names) . "\n";
echo "   Cache entries: {$cache->count()}\n\n";

// Example 6: Disable caching for a query
echo "6. Disabling cache with noCache()...\n";
$countBefore = $cache->count();

$result4 = $db->find()
    ->from('products')
    ->where('stock', 10, '<')
    ->cache(3600)     // Enable cache
    ->noCache()       // But then disable it
    ->get();

echo "   Found " . count($result4) . " low stock products\n";
echo "   Cache entries before: {$countBefore}, after: {$cache->count()}\n";
echo "   (No new cache entry created)\n\n";

// Example 7: Different parameters = different cache
echo "7. Different parameters create different cache entries...\n";
$countBefore = $cache->count();

$result5 = $db->find()
    ->from('products')
    ->where('price', 200, '>')
    ->cache(3600)
    ->get();

$result6 = $db->find()
    ->from('products')
    ->where('price', 300, '>')
    ->cache(3600)
    ->get();

echo "   Price > 200: " . count($result5) . " products\n";
echo "   Price > 300: " . count($result6) . " products\n";
echo "   Cache entries added: " . ($cache->count() - $countBefore) . "\n\n";

// Example 8: Custom TTL
echo "8. Custom TTL (time-to-live)...\n";
$result7 = $db->find()
    ->from('products')
    ->orderBy('price', 'DESC')
    ->limit(3)
    ->cache(60) // Cache for only 60 seconds
    ->get();

echo "   Top 3 expensive products:\n";
foreach ($result7 as $p) {
    echo "     • {$p['name']}: \${$p['price']}\n";
}

echo "\n";

// Example 9: Cache configuration
echo "9. Cache configuration...\n";
$cacheManager = $db->getCacheManager();
if ($cacheManager !== null) {
    $config = $cacheManager->getConfig();
    echo "   Prefix: {$config->getPrefix()}\n";
    echo "   Default TTL: {$config->getDefaultTtl()} seconds\n";
    echo "   Enabled: " . ($config->isEnabled() ? 'YES' : 'NO') . "\n";
}

echo "\n=== Summary ===\n";
echo "Total cache entries: {$cache->count()}\n";
echo "Cache keys: " . implode(', ', array_slice($cache->getKeys(), 0, 3)) . "...\n";

echo "\nAll caching examples completed!\n";

