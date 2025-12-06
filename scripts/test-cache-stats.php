#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cache Stats Test Script
 *
 * Generates cacheable queries to test pdodb ui cache stats pane.
 * Uses .env file from project root for database configuration.
 *
 * Usage:
 *   php scripts/test-cache-stats.php [N] [delay]
 *
 * Arguments:
 *   N     - Number of queries to execute (default: 50)
 *   delay - Delay between queries in seconds (default: 1)
 *
 * Environment:
 *   Loads database configuration from .env file in project root
 *   Requires PDODB_DRIVER and other PDODB_* variables
 */

require_once __DIR__ . '/../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Get arguments
$numQueries = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : 50;
$delay = isset($argv[2]) && is_numeric($argv[2]) ? (float)$argv[2] : 1.0;

echo "=== Cache Stats Test Script ===\n\n";
echo "Queries to execute: {$numQueries}\n";
echo "Delay between queries: {$delay}s\n\n";

// Load .env from project root
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) {
    echo "Error: .env file not found at: {$envPath}\n";
    echo "Please create .env file in project root with PDODB_* variables.\n";
    exit(1);
}

echo "Loading configuration from: {$envPath}\n";

// Create database connection from .env (cache will be auto-created from .env if PDODB_CACHE_ENABLED=true)
try {
    $db = PdoDb::fromEnv($envPath);
    
    // Display cache info if available
    $cacheManager = $db->getCacheManager();
    if ($cacheManager !== null) {
        $config = $cacheManager->getConfig();
        echo "✓ Database connection established with cache enabled\n";
        echo "  Cache enabled: " . ($config->isEnabled() ? 'Yes' : 'No') . "\n";
        
        // Try to detect cache type
        $cache = $cacheManager->getCache();
        $cacheTypeName = 'Unknown';
        if ($cache instanceof \Symfony\Component\Cache\Psr16Cache) {
            // Use reflection to get the adapter
            $reflection = new \ReflectionClass($cache);
            if ($reflection->hasProperty('pool')) {
                $property = $reflection->getProperty('pool');
                $property->setAccessible(true);
                $adapter = $property->getValue($cache);
                if ($adapter instanceof \Symfony\Component\Cache\Adapter\RedisAdapter) {
                    $cacheTypeName = 'Redis';
                } elseif ($adapter instanceof \Symfony\Component\Cache\Adapter\FilesystemAdapter) {
                    $cacheTypeName = 'Filesystem';
                } elseif ($adapter instanceof \Symfony\Component\Cache\Adapter\ApcuAdapter) {
                    $cacheTypeName = 'APCu';
                } else {
                    $cacheTypeName = get_class($adapter);
                }
            }
        } else {
            $cacheTypeName = get_class($cache);
        }
        echo "  Cache type: {$cacheTypeName}\n";
        
        // Show cache config
        $config = $cacheManager->getConfig();
        echo "  Cache prefix: " . $config->getPrefix() . "\n";
        echo "  Cache default TTL: " . $config->getDefaultTtl() . "s\n";
        echo "  Cache enabled: " . ($config->isEnabled() ? 'Yes' : 'No') . "\n";
        
        // Show initial stats
        $stats = $cacheManager->getStats();
        echo "  Initial stats - Hits: " . ($stats['hits'] ?? 0) . ", Misses: " . ($stats['misses'] ?? 0) . ", Sets: " . ($stats['sets'] ?? 0) . "\n\n";
    } else {
        echo "✓ Database connection established (cache disabled)\n";
        echo "  Warning: CacheManager is null. Check PDODB_CACHE_ENABLED in .env\n\n";
    }
} catch (\Exception $e) {
    echo "Error: Failed to connect to database: " . $e->getMessage() . "\n";
    exit(1);
}

// Create test table if it doesn't exist
$tableName = 'cache_test_users';
$driver = $db->connection->getDriverName();

echo "Creating test table: {$tableName}\n";
try {
    // Use DDL Query Builder for cross-dialect compatibility
    $schema = $db->schema();
    
    // Check if table exists
    if (!$schema->tableExists($tableName)) {
        $schema->createTable($tableName, [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
            'email' => $schema->string(100)->notNull(),
            'age' => $schema->integer(),
            'status' => $schema->string(20)->defaultValue('active'),
            'category' => $schema->string(50),
            'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
        
        // Create indexes
        $schema->createIndex("idx_{$tableName}_status", $tableName, ['status']);
        $schema->createIndex("idx_{$tableName}_category", $tableName, ['category']);
        $schema->createIndex("idx_{$tableName}_age", $tableName, ['age']);
        
        echo "✓ Test table created\n";
    } else {
        echo "✓ Test table already exists\n";
    }
} catch (\Exception $e) {
    echo "Warning: Could not create table: " . $e->getMessage() . "\n";
    echo "Continuing with existing table...\n";
}

// Insert some test data if table is empty
$count = $db->find()->from($tableName)->select('COUNT(*)')->getValue();
if ($count === 0 || $count === null) {
    echo "Inserting test data...\n";
    $categories = ['Electronics', 'Clothing', 'Food', 'Books', 'Sports'];
    $statuses = ['active', 'inactive', 'pending'];
    
    $data = [];
    for ($i = 1; $i <= 100; $i++) {
        $data[] = [
            'name' => "User {$i}",
            'email' => "user{$i}@example.com",
            'age' => 20 + ($i % 50),
            'status' => $statuses[$i % 3],
            'category' => $categories[$i % 5],
        ];
    }
    
    try {
        $db->find()->table($tableName)->insertMulti($data);
        echo "✓ Inserted 100 test records\n";
    } catch (\Exception $e) {
        echo "Warning: Could not insert test data: " . $e->getMessage() . "\n";
    }
} else {
    echo "✓ Test data already exists ({$count} records)\n";
}

echo "\nStarting query execution...\n";
echo "Press Ctrl+C to stop\n\n";

// Define different query patterns for cache testing
// Some patterns use fixed values to generate cache hits, others use variable values
$queryPatterns = [
    // Pattern 1: Simple WHERE with fixed values (will generate cache hits)
    function ($i) use ($db, $tableName) {
        // Use fixed values for first 3 queries, then repeat
        $statuses = ['active', 'inactive', 'pending'];
        $statusIndex = ($i < 3) ? $i : ($i % 3);
        $status = $statuses[$statusIndex];
        return $db->find()
            ->from($tableName)
            ->where('status', $status)
            ->cache(3600)
            ->get();
    },
    
    // Pattern 2: WHERE with fixed age range (will generate cache hits)
    function ($i) use ($db, $tableName) {
        // Use fixed age ranges that repeat
        $ranges = [[20, 30], [30, 40], [40, 50]];
        $rangeIndex = ($i < 3) ? $i : ($i % 3);
        [$minAge, $maxAge] = $ranges[$rangeIndex];
        return $db->find()
            ->from($tableName)
            ->where('age', $minAge, '>=')
            ->andWhere('age', $maxAge, '<=')
            ->cache(3600)
            ->get();
    },
    
    // Pattern 3: Category filter with fixed values (will generate cache hits)
    function ($i) use ($db, $tableName) {
        $categories = ['Electronics', 'Clothing', 'Food', 'Books', 'Sports'];
        $categoryIndex = ($i < 5) ? $i : ($i % 5);
        $category = $categories[$categoryIndex];
        return $db->find()
            ->from($tableName)
            ->where('category', $category)
            ->cache(3600)
            ->get();
    },
    
    // Pattern 4: Aggregation with GROUP BY (always same query - perfect for cache)
    function ($i) use ($db, $tableName) {
        return $db->find()
            ->from($tableName)
            ->select(['status', 'count' => Db::count('*')])
            ->groupBy('status')
            ->cache(3600)
            ->get();
    },
    
    // Pattern 5: Aggregation by category (always same query - perfect for cache)
    function ($i) use ($db, $tableName) {
        return $db->find()
            ->from($tableName)
            ->select(['category', 'avg_age' => Db::avg('age'), 'total' => Db::count('*')])
            ->groupBy('category')
            ->cache(3600)
            ->get();
    },
    
    // Pattern 6: Complex WHERE with fixed conditions (will generate cache hits)
    function ($i) use ($db, $tableName) {
        $conditions = [
            ['active', 25],
            ['inactive', 30],
            ['active', 35],
        ];
        $condIndex = ($i < 3) ? $i : ($i % 3);
        [$status, $minAge] = $conditions[$condIndex];
        return $db->find()
            ->from($tableName)
            ->where('status', $status)
            ->andWhere('age', $minAge, '>=')
            ->orderBy('age', 'ASC')
            ->limit(10)
            ->cache(3600)
            ->get();
    },
    
    // Pattern 7: COUNT query with fixed values (will generate cache hits)
    function ($i) use ($db, $tableName) {
        $statuses = ['active', 'inactive', 'pending'];
        $statusIndex = ($i < 3) ? $i : ($i % 3);
        $status = $statuses[$statusIndex];
        return $db->find()
            ->from($tableName)
            ->where('status', $status)
            ->select('COUNT(*)')
            ->cache(3600)
            ->getValue();
    },
    
    // Pattern 8: ORDER BY with fixed LIMIT (will generate cache hits)
    function ($i) use ($db, $tableName) {
        $limits = [5, 10, 15];
        $limitIndex = ($i < 3) ? $i : ($i % 3);
        $limit = $limits[$limitIndex];
        return $db->find()
            ->from($tableName)
            ->orderBy('age', 'DESC')
            ->limit($limit)
            ->cache(3600)
            ->get();
    },
];

$patternCount = count($queryPatterns);
$startTime = microtime(true);
$queryCount = 0;
$errorCount = 0;

// Execute queries in loop
// Use modulo to repeat same queries for cache hits demonstration
for ($i = 0; $i < $numQueries; $i++) {
    // Repeat patterns to generate cache hits
    // First half: unique queries (cache misses)
    // Second half: repeat same queries (cache hits)
    $patternIndex = ($i < $numQueries / 2) 
        ? ($i % $patternCount) 
        : (($i - (int)($numQueries / 2)) % $patternCount);
    $queryNum = $i + 1;
    
    try {
        $queryStart = microtime(true);
        $result = $queryPatterns[$patternIndex]($i);
        $queryTime = (microtime(true) - $queryStart) * 1000;
        
        $resultCount = is_array($result) ? count($result) : 1;
        echo sprintf(
            "[%d/%d] Query pattern %d: %d rows, %.2fms\n",
            $queryNum,
            $numQueries,
            $patternIndex + 1,
            $resultCount,
            $queryTime
        );
        
        $queryCount++;
        
        // Delay between queries
        if ($i < $numQueries - 1 && $delay > 0) {
            usleep((int)($delay * 1000000));
        }
    } catch (\Exception $e) {
        $errorCount++;
        echo sprintf(
            "[%d/%d] Error in query #%d: %s\n",
            $queryNum,
            $numQueries,
            $patternIndex + 1,
            $e->getMessage()
        );
    }
}

$totalTime = microtime(true) - $startTime;

echo "\n=== Summary ===\n";
echo "Total queries executed: {$queryCount}\n";
echo "Errors: {$errorCount}\n";
echo "Total time: " . number_format($totalTime, 2) . "s\n";
echo "Average time per query: " . number_format(($totalTime / max($queryCount, 1)) * 1000, 2) . "ms\n";
echo "\n";

// Show cache statistics if available
if (method_exists($db, 'getCacheManager')) {
    $cacheManager = $db->getCacheManager();
    if ($cacheManager !== null) {
        $stats = $cacheManager->getStats();
        echo "=== Cache Statistics ===\n";
        echo "Enabled: " . ($stats['enabled'] ?? false ? 'Yes' : 'No') . "\n";
        echo "Hits: " . ($stats['hits'] ?? 0) . "\n";
        echo "Misses: " . ($stats['misses'] ?? 0) . "\n";
        echo "Sets: " . ($stats['sets'] ?? 0) . "\n";
        echo "Deletes: " . ($stats['deletes'] ?? 0) . "\n";
        $total = ($stats['hits'] ?? 0) + ($stats['misses'] ?? 0);
        if ($total > 0) {
            $hitRate = (($stats['hits'] ?? 0) / $total) * 100;
            echo "Hit rate: " . number_format($hitRate, 2) . "%\n";
        } else {
            echo "Warning: No cache operations detected. Check if cache is enabled and queries use cache() method.\n";
        }
    } else {
        echo "=== Cache Statistics ===\n";
        echo "CacheManager is null - cache is not initialized.\n";
        echo "Check PDODB_CACHE_ENABLED in .env file.\n";
    }
}

echo "\nNow run 'vendor/bin/pdodb ui' to view cache statistics in the dashboard!\n";
echo "Navigate to Cache Statistics pane (key 3) to see real-time cache stats.\n";

