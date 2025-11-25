<?php

declare(strict_types=1);

/**
 * Query Performance Profiling Examples.
 *
 * This example demonstrates how to use the Query Performance Profiler
 * to track query execution times, memory usage, and identify slow queries.
 *
 * Usage:
 *   php examples/21-query-profiling/01-profiling-examples.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

// Get database from environment or default to SQLite
$db = createExampleDb();
$driver = getCurrentDriver($db);

// Setup test table
$driverName = $db->connection->getDriverName();
echo "=== Query Performance Profiling Examples ===\n\n";
echo "Driver: $driverName\n\n";

// Create test table using fluent API (cross-dialect)
$schema = $db->schema();
$schema->dropTableIfExists('profiling_demo');
$schema->createTable('profiling_demo', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100)->notNull(),
    'category' => $schema->string(50),
    'value' => $schema->integer(),
    'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
]);

echo "1. Basic Profiling Setup\n";
echo "   --------------------\n";
echo "Enable profiling with slow query threshold of 0.1 seconds:\n";
$db->enableProfiling(0.1);
echo "   ✓ Profiling enabled\n\n";

// Insert test data
echo "2. Inserting Test Data\n";
echo "   --------------------\n";
$categories = ['Electronics', 'Books', 'Clothing', 'Home', 'Sports'];
for ($i = 1; $i <= 50; $i++) {
    $db->find()
        ->table('profiling_demo')
        ->insert([
            'name' => "Product $i",
            'category' => $categories[array_rand($categories)],
            'value' => rand(10, 1000),
        ]);
}
echo "   ✓ Inserted 50 test records\n\n";

// Execute various queries
echo "3. Executing Queries\n";
echo "   --------------------\n";

// Simple queries
for ($i = 0; $i < 10; $i++) {
    $db->find()
        ->from('profiling_demo')
        ->where('category', $categories[$i % 5])
        ->get();
}

// Complex query with aggregation
$db->find()
    ->from('profiling_demo')
    ->select([
        'category',
        'total' => Db::count(),
        'avg_value' => Db::avg('value'),
    ])
    ->groupBy('category')
    ->get();

// Query with multiple conditions
$db->find()
    ->from('profiling_demo')
    ->where('category', 'Electronics')
    ->andWhere('value', 500, '>')
    ->orderBy('value', 'DESC')
    ->limit(10)
    ->get();

echo "   ✓ Executed multiple queries\n\n";

// Get profiling statistics
echo "4. Profiling Statistics\n";
echo "   --------------------\n";
$stats = $db->getProfilerStats(true);

echo "Aggregated Statistics:\n";
echo "  Total queries: {$stats['total_queries']}\n";
echo "  Total execution time: " . round($stats['total_time'] * 1000, 2) . " ms\n";
echo "  Average execution time: " . round($stats['avg_time'] * 1000, 2) . " ms\n";
echo "  Min execution time: " . round($stats['min_time'] * 1000, 2) . " ms\n";
echo "  Max execution time: " . round($stats['max_time'] * 1000, 2) . " ms\n";
echo "  Total memory used: " . formatBytes($stats['total_memory']) . "\n";
echo "  Average memory per query: " . formatBytes($stats['avg_memory']) . "\n";
echo "  Slow queries detected: {$stats['slow_queries']}\n\n";

// Get per-query statistics
echo "5. Per-Query Statistics\n";
echo "   --------------------\n";
$perQueryStats = $db->getProfilerStats(false);
echo "Unique query patterns: " . count($perQueryStats) . "\n";
foreach (array_slice($perQueryStats, 0, 3, true) as $hash => $stat) {
    echo "\n  Query #" . substr($hash, 0, 8) . "...\n";
    echo "    SQL: " . substr($stat['sql'], 0, 60) . "...\n";
    echo "    Executions: {$stat['count']}\n";
    echo "    Avg time: " . round($stat['avg_time'] * 1000, 2) . " ms\n";
    echo "    Max time: " . round($stat['max_time'] * 1000, 2) . " ms\n";
}
echo "\n";

// Get slowest queries
echo "6. Slowest Queries\n";
echo "   --------------------\n";
$slowest = $db->getSlowestQueries(5);
if (empty($slowest)) {
    echo "  No slow queries detected (all queries completed within threshold)\n";
} else {
    foreach ($slowest as $index => $query) {
        echo "  #" . ($index + 1) . " - " . round($query['avg_time'] * 1000, 2) . " ms (avg), "
             . round($query['max_time'] * 1000, 2) . " ms (max)\n";
        echo "    Executed {$query['count']} time(s)\n";
        echo "    SQL: " . substr($query['sql'], 0, 70) . "...\n";
        if ($query['slow_queries'] > 0) {
            echo "    ⚠ {$query['slow_queries']} slow execution(s) detected\n";
        }
        echo "\n";
    }
}

// Reset profiler
echo "7. Resetting Profiler\n";
echo "   --------------------\n";
$db->resetProfiler();
$statsAfterReset = $db->getProfilerStats(true);
echo "  Queries after reset: {$statsAfterReset['total_queries']}\n";
echo "  ✓ Profiler reset successfully\n\n";

// Disable profiling
echo "8. Disabling Profiling\n";
echo "   --------------------\n";
$db->disableProfiling();
echo "  Profiling enabled: " . ($db->isProfilingEnabled() ? 'Yes' : 'No') . "\n";
echo "  ✓ Profiling disabled\n\n";

echo "=== Profiling Examples Complete ===\n";

/**
 * Format bytes to human-readable format.
 *
 * @param int $bytes
 * @return string
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = (int)floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

