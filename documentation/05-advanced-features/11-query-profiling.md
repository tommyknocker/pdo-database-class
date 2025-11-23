# Query Performance Profiling

The Query Performance Profiler is a built-in tool for tracking and analyzing query execution performance. It helps identify slow queries, monitor memory usage, and optimize database operations.

## Overview

The profiler automatically tracks:
- **Execution time** for each query
- **Memory consumption** during query execution
- **Slow query detection** with configurable thresholds
- **Query statistics** aggregated by SQL pattern

## Basic Usage

### Enabling Profiling

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', $config);

// Enable profiling with default threshold (1.0 second)
$db->enableProfiling();

// Enable with custom slow query threshold (0.5 seconds)
$db->enableProfiling(0.5);
```

### Executing Queries

Once profiling is enabled, all queries are automatically tracked:

```php
// These queries will be profiled
$users = $db->find()->from('users')->where('active', 1)->get();
$count = $db->find()->from('orders')->where('status', 'pending')->count();
```

### Getting Statistics

#### Aggregated Statistics

```php
$stats = $db->getProfilerStats(true);

echo "Total queries: {$stats['total_queries']}\n";
echo "Total time: " . round($stats['total_time'] * 1000, 2) . " ms\n";
echo "Average time: " . round($stats['avg_time'] * 1000, 2) . " ms\n";
echo "Min time: " . round($stats['min_time'] * 1000, 2) . " ms\n";
echo "Max time: " . round($stats['max_time'] * 1000, 2) . " ms\n";
echo "Slow queries: {$stats['slow_queries']}\n";
```

#### Per-Query Statistics

```php
$perQueryStats = $db->getProfilerStats(false);

foreach ($perQueryStats as $hash => $stat) {
    echo "Query: {$stat['sql']}\n";
    echo "  Executions: {$stat['count']}\n";
    echo "  Avg time: " . round($stat['avg_time'] * 1000, 2) . " ms\n";
    echo "  Max time: " . round($stat['max_time'] * 1000, 2) . " ms\n";
}
```

### Getting Slowest Queries

```php
$slowest = $db->getSlowestQueries(10);

foreach ($slowest as $query) {
    echo "Query: {$query['sql']}\n";
    echo "  Avg time: " . round($query['avg_time'] * 1000, 2) . " ms\n";
    echo "  Executions: {$query['count']}\n";
    echo "  Slow queries: {$query['slow_queries']}\n";
}
```

### Disabling and Resetting

```php
// Disable profiling (stops tracking new queries)
$db->disableProfiling();

// Reset statistics (clears all collected data)
$db->resetProfiler();

// Check if profiling is enabled
if ($db->isProfilingEnabled()) {
    // Profiling is active
}
```

## Slow Query Detection

The profiler automatically detects queries that exceed the configured threshold and logs them via PSR-3 logger if provided.

### Configuring Threshold

```php
// Set threshold to 0.5 seconds
$db->enableProfiling(0.5);

// For very fast databases, use milliseconds
$db->enableProfiling(0.001); // 1ms threshold
```

### Logging Slow Queries

If a logger is provided to `PdoDb` constructor, slow queries are automatically logged:

```php
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\PdoDb;

$logger = new YourLoggerImplementation();

$db = new PdoDb('mysql', $config, [], $logger);
$db->enableProfiling(0.1); // 100ms threshold

// Slow queries will be logged with warning level
// Log entry includes: SQL, parameters, execution time, memory usage
```

## Statistics Structure

### Aggregated Statistics

```php
[
    'total_queries' => 150,        // Total number of queries executed
    'total_time' => 2.345,         // Total execution time in seconds
    'avg_time' => 0.0156,          // Average execution time in seconds
    'min_time' => 0.0012,          // Minimum execution time in seconds
    'max_time' => 0.1250,           // Maximum execution time in seconds
    'total_memory' => 2048000,      // Total memory used in bytes
    'avg_memory' => 13653,          // Average memory per query in bytes
    'slow_queries' => 5,           // Number of slow queries detected
]
```

### Per-Query Statistics

Each entry contains:

```php
[
    'sql' => 'SELECT * FROM users WHERE id = :id_1',
    'count' => 25,                  // Number of executions
    'total_time' => 0.523,          // Total time for this query pattern
    'avg_time' => 0.0209,           // Average time per execution
    'min_time' => 0.0150,           // Fastest execution
    'max_time' => 0.0450,           // Slowest execution
    'total_memory' => 512000,       // Total memory for this query pattern
    'avg_memory' => 20480,          // Average memory per execution
    'slow_queries' => 2,            // Number of slow executions
]
```

## Query Grouping

The profiler groups queries by normalized SQL structure. Queries with the same structure but different parameter values are grouped together:

```php
// These queries will be grouped together (same structure)
$db->find()->from('users')->where('id', 1)->get();
$db->find()->from('users')->where('id', 2)->get();
$db->find()->from('users')->where('id', 3)->get();

// Different structure = different group
$db->find()->from('users')->where('email', 'test@example.com')->get();
```

## Memory Tracking

The profiler tracks memory consumption during query execution:

```php
$stats = $db->getProfilerStats(true);

// Format memory usage
function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $pow = (int)floor(($bytes ? log($bytes) : 0) / log(1024));
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

echo "Total memory: " . formatBytes($stats['total_memory']) . "\n";
echo "Average per query: " . formatBytes($stats['avg_memory']) . "\n";
```

## Integration with Logger

The profiler integrates with PSR-3 compatible loggers:

```php
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\PdoDb;

class MyLogger implements LoggerInterface {
    // ... implement all methods
    public function warning(string $message, array $context = []): void {
        if ($message === 'query.slow') {
            // Handle slow query
            $sql = $context['sql'];
            $time = $context['execution_time_ms'];
            error_log("Slow query detected: {$sql} ({$time} ms)");
        }
    }
}

$logger = new MyLogger();
$db = new PdoDb('mysql', $config, [], $logger);
$db->enableProfiling(0.1);
```

## Performance Considerations

- **Overhead**: Profiling adds minimal overhead (~0.01ms per query)
- **Memory**: Statistics are stored in memory, reset periodically for long-running processes
- **Production**: Consider disabling in production or using high thresholds

```php
// Production configuration
if (getenv('APP_ENV') === 'production') {
    // Disable profiling or use high threshold
    $db->enableProfiling(5.0); // Only log queries > 5 seconds
} else {
    // Development: more aggressive profiling
    $db->enableProfiling(0.1);
}
```

## Best Practices

1. **Use appropriate thresholds**: Set threshold based on your application's performance requirements
2. **Periodic resets**: Reset profiler statistics periodically in long-running processes
3. **Logging integration**: Use logger to persist slow query information
4. **Analysis**: Regularly review slowest queries and optimize them
5. **Memory monitoring**: Track memory usage to identify potential memory leaks

## Example: Performance Analysis Workflow

```php
$db = new PdoDb('mysql', $config);
$db->enableProfiling(0.5); // 500ms threshold

// Run your application logic
// ... execute queries ...

// Analyze performance
$stats = $db->getProfilerStats(true);
echo "Total queries: {$stats['total_queries']}\n";
echo "Average time: " . round($stats['avg_time'] * 1000, 2) . " ms\n";

// Identify bottlenecks
$slowest = $db->getSlowestQueries(10);
foreach ($slowest as $query) {
    if ($query['avg_time'] > 0.1) {
        echo "Optimization candidate: {$query['sql']}\n";
    }
}

// Reset for next analysis period
$db->resetProfiler();
```

## API Reference

### PdoDb Methods

- `enableProfiling(float $slowQueryThreshold = 1.0): self` - Enable profiling
- `disableProfiling(): self` - Disable profiling
- `isProfilingEnabled(): bool` - Check if profiling is enabled
- `getProfilerStats(bool $aggregated = false): array` - Get statistics
- `getSlowestQueries(int $limit = 10): array` - Get slowest queries
- `resetProfiler(): self` - Reset statistics
- `getProfiler(): ?QueryProfiler` - Get profiler instance
- `setProfiler(?QueryProfiler $profiler): self` - Set profiler instance

## See Also

- [Query Caching](./query-caching.md) - Cache query results for better performance
- [Query Compilation Cache](./query-compilation-cache.md) - Cache compiled SQL
- [Examples](../../examples/07-performance/03-query-profiling.php) - Working examples
