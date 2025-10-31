<?php
/**
 * Cache Benchmark Script
 * 
 * Benchmarks different cache configurations:
 * - No cache
 * - Query compilation cache only
 * - Query result cache only
 * - Both compilation and result caches
 * 
 * Usage:
 *   php scripts/benchmark-caches.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\cache\CacheConfig;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

// Configuration
$driver = getenv('PDODB_DRIVER') ?: 'mysql';
$numRecords = (int)(getenv('BENCHMARK_RECORDS') ?: 10000);
$iterations = (int)(getenv('BENCHMARK_ITERATIONS') ?: 50); // Reduced default for faster execution

echo "=== Cache Benchmark Tool ===\n\n";
echo "Driver: $driver\n";
echo "Records in table: $numRecords\n";
echo "Query iterations: $iterations\n\n";

// Database configuration
$config = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'dbname' => getenv('DB_NAME') ?: 'testdb',
    'username' => getenv('DB_USER') ?: 'testuser',
    'password' => getenv('DB_PASS') ?: 'testpass',
];

// Create cache backend
$cache = new ArrayCache(); // In-memory cache for testing

// Setup database connections
echo "1. Setting up database...\n";

// Without cache
$dbNoCache = new PdoDb($driver, $config);
echo "  ✓ Database without cache created\n";

// With compilation cache only
$dbCompilationCache = new PdoDb($driver, [
    ...$config,
    'cache' => ['enabled' => false], // Disable result cache
], [], null, $cache);
$dbCompilationCache->getCompilationCache()?->setEnabled(true);
echo "  ✓ Database with compilation cache only created\n";

// With result cache only
$dbResultCache = new PdoDb($driver, [
    ...$config,
    'cache' => ['enabled' => true], // Enable result cache
    'compilation_cache' => ['enabled' => false], // Disable compilation cache
], [], null, $cache);
echo "  ✓ Database with result cache only created\n";

// With both caches
$dbBothCaches = new PdoDb($driver, [
    ...$config,
    'cache' => ['enabled' => true], // Enable result cache
    'compilation_cache' => ['enabled' => true], // Enable compilation cache
], [], null, $cache);
echo "  ✓ Database with both caches created\n\n";

// Create test table
$tableName = 'benchmark_users';
$driverName = $dbNoCache->connection->getDriverName();

// Drop table
if ($driverName === 'pgsql') {
    $dbNoCache->rawQuery("DROP TABLE IF EXISTS {$tableName} CASCADE");
} else {
    $dbNoCache->rawQuery("DROP TABLE IF EXISTS `{$tableName}`");
}

// Create table with driver-specific syntax
if ($driverName === 'mysql') {
    $dbNoCache->rawQuery("
        CREATE TABLE `{$tableName}` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL,
            age INT NOT NULL,
            active TINYINT(1) DEFAULT 1,
            category VARCHAR(50),
            score DECIMAL(10,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_active (active),
            INDEX idx_category (category),
            INDEX idx_age (age)
        ) ENGINE=InnoDB
    ");
} elseif ($driverName === 'pgsql') {
    $dbNoCache->rawQuery("
        CREATE TABLE {$tableName} (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL,
            age INT NOT NULL,
            active SMALLINT DEFAULT 1,
            category VARCHAR(50),
            score NUMERIC(10,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $dbNoCache->rawQuery("CREATE INDEX idx_active ON {$tableName}(active)");
    $dbNoCache->rawQuery("CREATE INDEX idx_category ON {$tableName}(category)");
    $dbNoCache->rawQuery("CREATE INDEX idx_age ON {$tableName}(age)");
} else {
    // SQLite
    $dbNoCache->rawQuery("
        CREATE TABLE `{$tableName}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            age INTEGER NOT NULL,
            active INTEGER DEFAULT 1,
            category TEXT,
            score REAL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $dbNoCache->rawQuery("CREATE INDEX idx_active ON `{$tableName}`(active)");
    $dbNoCache->rawQuery("CREATE INDEX idx_category ON `{$tableName}`(category)");
    $dbNoCache->rawQuery("CREATE INDEX idx_age ON `{$tableName}`(age)");
}

echo "2. Inserting {$numRecords} test records...\n";
$start = microtime(true);

$categories = ['Electronics', 'Clothing', 'Food', 'Books', 'Sports'];
$batchSize = 1000;

for ($batch = 0; $batch < ceil($numRecords / $batchSize); $batch++) {
    $records = [];
    $batchStart = $batch * $batchSize;
    $batchEnd = min($batchStart + $batchSize, $numRecords);
    
    for ($i = $batchStart; $i < $batchEnd; $i++) {
        $records[] = [
            'name' => "User " . ($i + 1),
            'email' => "user" . ($i + 1) . "@example.com",
            'age' => 18 + ($i % 62), // Ages 18-80
            'active' => $i % 3 === 0 ? 0 : 1,
            'category' => $categories[$i % 5],
            'score' => round(rand(0, 10000) / 100, 2),
        ];
    }
    
    $dbNoCache->find()->table($tableName)->insertMulti($records);
    
    if (($batch + 1) % 5 === 0 || $batch === ceil($numRecords / $batchSize) - 1) {
        $progress = min(($batch + 1) * $batchSize, $numRecords);
        echo "  Progress: {$progress} / {$numRecords} records (" . round($progress / $numRecords * 100, 1) . "%)\n";
    }
}

$insertTime = microtime(true) - $start;
echo "  ✓ Inserted {$numRecords} records in " . round($insertTime, 2) . "s\n\n";

// Clear caches before benchmark
$cache->clear();
echo "3. Caches cleared, starting benchmark...\n\n";

// Test scenarios
$scenarios = [
    [
        'name' => 'No Cache',
        'db' => $dbNoCache,
        'useResultCache' => false,
        'useCompilationCache' => false,
    ],
    [
        'name' => 'Compilation Cache Only',
        'db' => $dbCompilationCache,
        'useResultCache' => false,
        'useCompilationCache' => true,
    ],
    [
        'name' => 'Result Cache Only',
        'db' => $dbResultCache,
        'useResultCache' => true,
        'useCompilationCache' => false,
    ],
    [
        'name' => 'Both Caches',
        'db' => $dbBothCaches,
        'useResultCache' => true,
        'useCompilationCache' => true,
    ],
];

$results = [];

foreach ($scenarios as $scenario) {
    echo "Testing: {$scenario['name']}...\n";
    
    $db = $scenario['db'];
    $times = [];
    
    // Test 1: Result Cache - Repeated Queries (for result cache effectiveness)
    $testName = 'Repeated Queries (Result Cache)';
    $cache->clear();
    
    $category = $categories[0];
    $totalQueries = $iterations * 20; // Many repetitions for clear cache benefit
    
    $start = microtime(true);
    // First query will be cache miss, all others should be cache hits
    for ($i = 0; $i < $totalQueries; $i++) {
        $db->find()
            ->from($tableName)
            ->where('category', $category)
            ->where('active', 1)
            ->orderBy('score', 'DESC')
            ->limit(20)
            ->cache($scenario['useResultCache'] ? 3600 : 0)
            ->get();
    }
    $times[$testName] = microtime(true) - $start;
    
    // Test 2: Compilation Cache - Complex Structure (same structure, different params)
    $testName = 'Complex Structure (Compilation Cache)';
    
    $start = microtime(true);
    // More iterations with complex structure - compilation cache helps
    for ($i = 0; $i < $iterations * 2; $i++) {
        $category = $categories[$i % 5];
        $minAge = 18 + ($i % 25);
        $maxAge = 40 + ($i % 25);
        // Same structure, different parameters -> compilation cache hits
        $db->find()
            ->from($tableName)
            ->select([
                'category' => 'category',
                'count' => Db::count(),
                'avg_age' => Db::avg('age'),
                'min_score' => Db::min('score'),
                'max_score' => Db::max('score'),
                'total_score' => Db::sum('score'),
                'score_variance' => Db::raw('AVG(score * score) - AVG(score) * AVG(score)'),
            ])
            ->where('category', $category)
            ->where('active', 1)
            ->where('age', $minAge, '>=')
            ->where('age', $maxAge, '<=')
            ->where('score', 10, '>=')
            ->where('score', 95, '<=')
            ->orderBy('category', 'ASC')
            ->orderBy('avg_age', 'DESC')
            ->orderBy('total_score', 'DESC')
            ->groupBy('category')
            ->having('count', 5, '>=')
            ->having('avg_age', 20, '>=')
            ->limit(50)
            ->cache($scenario['useResultCache'] ? 3600 : 0)
            ->get();
    }
    $times[$testName] = microtime(true) - $start;
    
    // Test 3: Both Caches - Complex Repeated Query
    $testName = 'Complex Repeated (Both Caches)';
    
    // Use single complex query, repeat MANY times
    // Compilation cache: same structure (hits after first)
    // Result cache: same query (cache hit after first)
    $category = $categories[0];
    $totalQueries = $iterations * 15; // Many repetitions
    
    $start = microtime(true);
    // First query: cache miss for both, all others: cache hits
    for ($i = 0; $i < $totalQueries; $i++) {
        $db->find()
            ->from($tableName)
            ->select([
                'category' => 'category',
                'total' => Db::count(),
                'avg_score' => Db::avg('score'),
                'max_age' => Db::max('age'),
                'min_age' => Db::min('age'),
            ])
            ->where('category', $category)
            ->where('active', 1)
            ->where('age', 25, '>=')
            ->where('age', 55, '<=')
            ->where('score', 25, '>=')
            ->orderBy('category', 'ASC')
            ->groupBy('category')
            ->having('total', 10, '>=')
            ->having('avg_score', 30, '>=')
            ->limit(25)
            ->cache($scenario['useResultCache'] ? 3600 : 0)
            ->get();
    }
    $times[$testName] = microtime(true) - $start;
    
    // Test 4: Simple Structure (Compilation Cache only)
    $testName = 'Simple Structure (Compilation)';
    
    $start = microtime(true);
    // Simple queries but many iterations - compilation cache helps
    for ($i = 0; $i < $iterations * 3; $i++) { // More iterations for simple queries
        $age = 20 + ($i % 40);
        // Same structure, different age parameter - compilation cache hits
        $db->find()
            ->from($tableName)
            ->select(['id', 'name', 'age', 'score', 'category'])
            ->where('active', 1)
            ->where('age', $age, '>=')
            ->where('age', $age + 15, '<=')
            ->where('score', 10, '>=')
            ->orderBy('score', 'DESC')
            ->orderBy('age', 'ASC')
            ->orderBy('name', 'ASC')
            ->limit(15)
            ->cache($scenario['useResultCache'] ? 3600 : 0)
            ->get();
    }
    $times[$testName] = microtime(true) - $start;
    
    // Test 5: Very Complex Query (Compilation Cache shines here)
    $testName = 'Very Complex Query';
    
    $start = microtime(true);
    for ($i = 0; $i < $iterations / 2; $i++) {
        $category1 = $categories[$i % 5];
        $category2 = $categories[($i + 1) % 5];
        // Complex query with multiple conditions
        $db->find()
            ->from($tableName)
            ->select([
                'category' => 'category',
                'total_users' => Db::count(),
                'avg_age' => Db::avg('age'),
                'max_score' => Db::max('score'),
                'min_score' => Db::min('score'),
                'score_range' => Db::raw('MAX(score) - MIN(score)'),
            ])
            ->where('active', 1)
            ->whereRaw("(category = '{$category1}' OR category = '{$category2}')")
            ->where('age', 20, '>=')
            ->where('age', 60, '<=')
            ->where('score', 30, '>=')
            ->where('score', 90, '<=')
            ->orderBy('category', 'ASC')
            ->orderBy('avg_age', 'DESC')
            ->orderBy('total_users', 'DESC')
            ->groupBy('category')
            ->having('total_users', 3, '>=')
            ->having('avg_age', 25, '>=')
            ->limit(100)
            ->cache($scenario['useResultCache'] ? 3600 : 0)
            ->get();
    }
    $times[$testName] = microtime(true) - $start;
    
    $results[$scenario['name']] = $times;
    
    echo "  ✓ Completed\n\n";
}

// Print results
echo "=== Benchmark Results ===\n\n";
echo str_pad('Test', 40) . ' | ' . 
     str_pad('No Cache', 12) . ' | ' . 
     str_pad('Compilation', 12) . ' | ' . 
     str_pad('Result', 12) . ' | ' . 
     str_pad('Both', 12) . ' | ' .
     str_pad('Best', 15) . "\n";
echo str_repeat('-', 110) . "\n";

$tests = [
    'Repeated Queries (Result Cache)',
    'Complex Structure (Compilation Cache)',
    'Complex Repeated (Both Caches)',
    'Simple Structure (Compilation)',
    'Very Complex Query'
];

foreach ($tests as $test) {
    $bestTime = min(
        $results['No Cache'][$test],
        $results['Compilation Cache Only'][$test],
        $results['Result Cache Only'][$test],
        $results['Both Caches'][$test]
    );
    
    $bestConfig = '';
    foreach ($results as $config => $times) {
        if ($times[$test] === $bestTime) {
            $bestConfig = $config;
            break;
        }
    }
    
    // Truncate test name if too long
    $displayName = strlen($test) > 38 ? substr($test, 0, 35) . '...' : $test;
    
    echo str_pad($displayName, 40) . ' | ' . 
         str_pad(round($results['No Cache'][$test] * 1000, 2) . 'ms', 12) . ' | ' .
         str_pad(round($results['Compilation Cache Only'][$test] * 1000, 2) . 'ms', 12) . ' | ' .
         str_pad(round($results['Result Cache Only'][$test] * 1000, 2) . 'ms', 12) . ' | ' .
         str_pad(round($results['Both Caches'][$test] * 1000, 2) . 'ms', 12) . ' | ' .
         str_pad($bestConfig, 15) . "\n";
}

echo "\n=== Performance Improvements ===\n\n";

$baseline = $results['No Cache'];
foreach ($tests as $test) {
    echo "{$test}:\n";
    
    $noCache = $baseline[$test];
    
    $compilation = $results['Compilation Cache Only'][$test];
    $compilationImprovement = round((1 - $compilation / $noCache) * 100, 1);
    
    $result = $results['Result Cache Only'][$test];
    $resultImprovement = round((1 - $result / $noCache) * 100, 1);
    
    $both = $results['Both Caches'][$test];
    $bothImprovement = round((1 - $both / $noCache) * 100, 1);
    
    echo "  No Cache:         " . round($noCache * 1000, 2) . "ms\n";
    echo "  Compilation Only: " . round($compilation * 1000, 2) . "ms (" . 
         ($compilationImprovement > 0 ? '+' : '') . "{$compilationImprovement}%)\n";
    echo "  Result Only:      " . round($result * 1000, 2) . "ms (" . 
         ($resultImprovement > 0 ? '+' : '') . "{$resultImprovement}%)\n";
    echo "  Both Caches:      " . round($both * 1000, 2) . "ms (" . 
         ($bothImprovement > 0 ? '+' : '') . "{$bothImprovement}%)\n";
    echo "\n";
}

// Summary
echo "=== Summary ===\n\n";

$avgNoCache = array_sum($baseline) / count($baseline);
$avgCompilation = array_sum($results['Compilation Cache Only']) / count($results['Compilation Cache Only']);
$avgResult = array_sum($results['Result Cache Only']) / count($results['Result Cache Only']);
$avgBoth = array_sum($results['Both Caches']) / count($results['Both Caches']);

echo "Average across all tests:\n";
echo "  No Cache:         " . round($avgNoCache * 1000, 2) . "ms\n";
echo "  Compilation Only: " . round($avgCompilation * 1000, 2) . "ms (" . 
     round((1 - $avgCompilation / $avgNoCache) * 100, 1) . "% improvement)\n";
echo "  Result Only:      " . round($avgResult * 1000, 2) . "ms (" . 
     round((1 - $avgResult / $avgNoCache) * 100, 1) . "% improvement)\n";
echo "  Both Caches:      " . round($avgBoth * 1000, 2) . "ms (" . 
     round((1 - $avgBoth / $avgNoCache) * 100, 1) . "% improvement)\n";

echo "\n=== Recommendations ===\n\n";

if ($avgBoth < $avgNoCache * 0.7) {
    echo "✓ Use both compilation and result caches for best performance\n";
} elseif ($avgResult < $avgNoCache * 0.8) {
    echo "✓ Use result cache for frequently repeated queries\n";
} elseif ($avgCompilation < $avgNoCache * 0.9) {
    echo "✓ Use compilation cache for repeated query structures\n";
} else {
    echo "⚠ Cache overhead may outweigh benefits for this workload\n";
    echo "  Consider enabling caches only for specific query patterns\n";
}

echo "\nBenchmark completed!\n";
