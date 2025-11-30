<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\PdoDb;

/**
 * Benchmark command for performance testing.
 */
class BenchmarkCommand extends Command
{
    /**
     * Create benchmark command.
     */
    public function __construct()
    {
        parent::__construct('benchmark', 'Benchmark and performance testing');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        $sub = $this->getArgument(0);
        if ($sub === null || $sub === '--help' || $sub === 'help') {
            return $this->showHelp();
        }

        return match ($sub) {
            'query' => $this->benchmarkQuery(),
            'crud' => $this->benchmarkCrud(),
            'load' => $this->benchmarkLoad(),
            'compare' => $this->benchmarkCompare(),
            'profile' => $this->benchmarkProfile(),
            'report' => $this->benchmarkReport(),
            default => $this->showError("Unknown subcommand: {$sub}"),
        };
    }

    /**
     * Benchmark a specific query.
     *
     * @return int Exit code
     */
    protected function benchmarkQuery(): int
    {
        $sql = $this->getArgument(1);
        if (!is_string($sql) || $sql === '') {
            return $this->showError('SQL query is required. Usage: pdodb benchmark query "SELECT * FROM users WHERE id = :id"');
        }

        $iterations = (int)$this->getOption('iterations', 100);
        $warmup = (int)$this->getOption('warmup', 10);
        $params = $this->parseQueryParams($sql);

        $db = $this->getDb();
        $db->enableProfiling(0.0); // Enable profiling without slow query threshold

        // Warmup
        for ($i = 0; $i < $warmup; $i++) {
            try {
                $db->rawQuery($sql, $params);
            } catch (\Exception $e) {
                // Ignore warmup errors
            }
        }

        // Reset profiler for actual benchmark
        $profiler = $db->getProfiler();
        if ($profiler !== null) {
            $profiler->reset();
        }

        // Actual benchmark
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        for ($i = 0; $i < $iterations; $i++) {
            try {
                $db->rawQuery($sql, $params);
            } catch (\Exception $e) {
                return $this->showError('Query execution failed: ' . $e->getMessage());
            }
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $totalTime = $endTime - $startTime;
        $totalMemory = max(0, $endMemory - $startMemory);
        $avgTime = $totalTime / $iterations;
        $qps = $iterations / $totalTime;

        // Get profiler stats if available
        $profilerStats = null;
        if ($profiler !== null) {
            $aggregated = $profiler->getAggregatedStats();
            if ($aggregated['total_queries'] > 0) {
                $profilerStats = $aggregated;
            }
        }

        $this->printQueryResults([
            'iterations' => $iterations,
            'total_time' => $totalTime,
            'avg_time' => $avgTime,
            'min_time' => $profilerStats['min_time'] ?? 0.0,
            'max_time' => $profilerStats['max_time'] ?? 0.0,
            'qps' => $qps,
            'total_memory' => $totalMemory,
            'avg_memory' => $profilerStats['avg_memory'] ?? 0,
        ]);

        return 0;
    }

    /**
     * Benchmark CRUD operations.
     *
     * @return int Exit code
     */
    protected function benchmarkCrud(): int
    {
        $table = $this->getArgument(1);
        if (!is_string($table) || $table === '') {
            return $this->showError('Table name is required. Usage: pdodb benchmark crud <table>');
        }

        $iterations = (int)$this->getOption('iterations', 1000);
        $db = $this->getDb();

        // Check if table exists
        if (!$db->schema()->tableExists($table)) {
            return $this->showError("Table '{$table}' does not exist");
        }

        $db->enableProfiling(0.0);
        $profiler = $db->getProfiler();

        $results = [
            'create' => $this->benchmarkCreate($db, $table, $iterations),
            'read' => $this->benchmarkRead($db, $table, $iterations),
            'update' => $this->benchmarkUpdate($db, $table, $iterations),
            'delete' => $this->benchmarkDelete($db, $table, $iterations),
        ];

        $this->printCrudResults($results, $iterations);

        return 0;
    }

    /**
     * Benchmark CREATE operations.
     *
     * @param PdoDb $db
     * @param string $table
     * @param int $iterations
     *
     * @return array<string, mixed>
     */
    protected function benchmarkCreate(PdoDb $db, string $table, int $iterations): array
    {
        // Get table structure to create realistic test data
        $columns = $db->describe($table);
        if (empty($columns)) {
            return ['error' => 'Could not get table structure'];
        }

        $profiler = $db->getProfiler();
        if ($profiler !== null) {
            $profiler->reset();
        }

        $startTime = microtime(true);
        $insertedIds = [];

        for ($i = 0; $i < $iterations; $i++) {
            $data = $this->generateTestData($columns, $i);

            try {
                $id = $db->find()->table($table)->insert($data);
                $insertedIds[] = $id;
            } catch (\Exception $e) {
                // Continue on error
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        $profilerStats = null;
        if ($profiler !== null) {
            $aggregated = $profiler->getAggregatedStats();
            if ($aggregated['total_queries'] > 0) {
                $profilerStats = $aggregated;
            }
        }

        return [
            'iterations' => count($insertedIds),
            'total_time' => $totalTime,
            'avg_time' => count($insertedIds) > 0 ? $totalTime / count($insertedIds) : 0.0,
            'ops_per_sec' => count($insertedIds) > 0 ? count($insertedIds) / $totalTime : 0.0,
            'inserted_ids' => $insertedIds,
        ];
    }

    /**
     * Benchmark READ operations.
     *
     * @param PdoDb $db
     * @param string $table
     * @param int $iterations
     *
     * @return array<string, mixed>
     */
    protected function benchmarkRead(PdoDb $db, string $table, int $iterations): array
    {
        $profiler = $db->getProfiler();
        if ($profiler !== null) {
            $profiler->reset();
        }

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            try {
                $db->find()->from($table)->limit(1)->get();
            } catch (\Exception $e) {
                // Continue on error
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        $profilerStats = null;
        if ($profiler !== null) {
            $aggregated = $profiler->getAggregatedStats();
            if ($aggregated['total_queries'] > 0) {
                $profilerStats = $aggregated;
            }
        }

        return [
            'iterations' => $iterations,
            'total_time' => $totalTime,
            'avg_time' => $totalTime / $iterations,
            'ops_per_sec' => $iterations / $totalTime,
        ];
    }

    /**
     * Benchmark UPDATE operations.
     *
     * @param PdoDb $db
     * @param string $table
     * @param int $iterations
     *
     * @return array<string, mixed>
     */
    protected function benchmarkUpdate(PdoDb $db, string $table, int $iterations): array
    {
        // Get existing records to update
        $existing = $db->find()->from($table)->limit($iterations)->get();
        if (empty($existing)) {
            return ['error' => 'No records to update'];
        }

        $profiler = $db->getProfiler();
        if ($profiler !== null) {
            $profiler->reset();
        }

        $startTime = microtime(true);
        $updated = 0;

        foreach ($existing as $row) {
            if ($updated >= $iterations) {
                break;
            }

            $pk = $this->getPrimaryKey($db, $table, $row);
            if ($pk === null) {
                continue;
            }

            try {
                $db->find()->table($table)->where($pk['column'], $pk['value'])->update(['updated_at' => date('Y-m-d H:i:s')]);
                $updated++;
            } catch (\Exception $e) {
                // Continue on error
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        return [
            'iterations' => $updated,
            'total_time' => $totalTime,
            'avg_time' => $updated > 0 ? $totalTime / $updated : 0.0,
            'ops_per_sec' => $updated > 0 ? $updated / $totalTime : 0.0,
        ];
    }

    /**
     * Benchmark DELETE operations.
     *
     * @param PdoDb $db
     * @param string $table
     * @param int $iterations
     *
     * @return array<string, mixed>
     */
    protected function benchmarkDelete(PdoDb $db, string $table, int $iterations): array
    {
        // Get existing records to delete
        $existing = $db->find()->from($table)->limit($iterations)->get();
        if (empty($existing)) {
            return ['error' => 'No records to delete'];
        }

        $profiler = $db->getProfiler();
        if ($profiler !== null) {
            $profiler->reset();
        }

        $startTime = microtime(true);
        $deleted = 0;

        foreach ($existing as $row) {
            if ($deleted >= $iterations) {
                break;
            }

            $pk = $this->getPrimaryKey($db, $table, $row);
            if ($pk === null) {
                continue;
            }

            try {
                $db->find()->table($table)->where($pk['column'], $pk['value'])->delete();
                $deleted++;
            } catch (\Exception $e) {
                // Continue on error
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        return [
            'iterations' => $deleted,
            'total_time' => $totalTime,
            'avg_time' => $deleted > 0 ? $totalTime / $deleted : 0.0,
            'ops_per_sec' => $deleted > 0 ? $deleted / $totalTime : 0.0,
        ];
    }

    /**
     * Benchmark load testing.
     *
     * @return int Exit code
     */
    protected function benchmarkLoad(): int
    {
        $connections = (int)$this->getOption('connections', 10);
        $duration = (int)$this->getOption('duration', 60);
        $query = (string)$this->getOption('query', 'SELECT 1');

        $db = $this->getDb();
        $db->enableProfiling(0.0);

        echo "Load Testing\n";
        echo "============\n";
        echo "Connections: {$connections}\n";
        echo "Duration: {$duration}s\n";
        echo "Query: {$query}\n\n";

        $startTime = microtime(true);
        $endTime = $startTime + $duration;
        $totalQueries = 0;
        $errors = 0;

        // Emulate concurrent connections by running queries sequentially
        // with timing to simulate concurrent load
        $queryTimes = [];
        $currentTime = microtime(true);

        while ($currentTime < $endTime) {
            $queryStart = microtime(true);

            try {
                $db->rawQuery($query);
                $totalQueries++;
            } catch (\Exception $e) {
                $errors++;
            }
            $queryEnd = microtime(true);
            $queryTimes[] = $queryEnd - $queryStart;

            // Small delay to prevent overwhelming the database
            usleep(1000); // 1ms delay

            $currentTime = microtime(true);
        }

        $actualDuration = $currentTime - $startTime;
        $qps = $totalQueries / $actualDuration;

        $avgTime = !empty($queryTimes) ? array_sum($queryTimes) / count($queryTimes) : 0.0;
        $minTime = !empty($queryTimes) ? min($queryTimes) : 0.0;
        $maxTime = !empty($queryTimes) ? max($queryTimes) : 0.0;

        echo "Results:\n";
        echo "--------\n";
        echo "Total queries: {$totalQueries}\n";
        echo "Errors: {$errors}\n";
        echo 'Duration: ' . round($actualDuration, 2) . "s\n";
        echo 'Queries per second: ' . round($qps, 2) . "\n";
        echo 'Avg query time: ' . round($avgTime * 1000, 2) . "ms\n";
        echo 'Min query time: ' . round($minTime * 1000, 2) . "ms\n";
        echo 'Max query time: ' . round($maxTime * 1000, 2) . "ms\n";

        return 0;
    }

    /**
     * Compare benchmark results with different configurations.
     *
     * @return int Exit code
     */
    protected function benchmarkCompare(): int
    {
        $withCache = (bool)$this->getOption('cache', false);
        $withoutCache = (bool)$this->getOption('no-cache', false);
        $queryOption = $this->getOption('query', null);
        $query = is_string($queryOption) ? $queryOption : null;
        $iterations = (int)$this->getOption('iterations', 100);

        // Check if query is specified
        if ($query === null || $query === '') {
            static::warning('No query specified. Use --query option to specify the SQL query to benchmark.');
            static::info('Example: pdodb benchmark compare --query="SELECT * FROM users"');
            return 1;
        }

        $db = $this->getDb();
        $cacheManager = $db->getCacheManager();

        // Check if cache is available
        if ($cacheManager === null) {
            static::warning('Cache is not configured. Comparison requires cache to be enabled.');
            static::info('To enable cache, configure it in your database configuration.');
            static::info('Running benchmark without comparison...');
            // Run single benchmark without comparison
            $results = ['no-cache' => $this->runSingleBenchmark($db, $query, $iterations)];
            $this->printCompareResults($results);
            return 0;
        }

        if (!$withCache && !$withoutCache) {
            // Run both by default
            $withCache = true;
            $withoutCache = true;
        } elseif ($withCache && !$withoutCache) {
            // Only --cache specified, warn that comparison needs both
            static::warning('Only --cache option specified. For comparison, both --cache and --no-cache are needed.');
            static::info('Running benchmark with cache enabled only...');
            $results = ['cache' => $this->runSingleBenchmark($db, $query, $iterations)];
            $this->printCompareResults($results);
            return 0;
        } elseif (!$withCache && $withoutCache) {
            // Only --no-cache specified, warn that comparison needs both
            static::warning('Only --no-cache option specified. For comparison, both --cache and --no-cache are needed.');
            static::info('Running benchmark with cache disabled only...');
            $results = ['no-cache' => $this->runSingleBenchmark($db, $query, $iterations)];
            $this->printCompareResults($results);
            return 0;
        }

        $results = [];

        if ($withoutCache) {
            // Disable cache if enabled
            if (method_exists($cacheManager, 'setEnabled')) {
                $cacheManager->setEnabled(false);
            }
            $results['no-cache'] = $this->runSingleBenchmark($db, $query, $iterations);
        }

        if ($withCache) {
            // Enable cache if available
            if (method_exists($cacheManager, 'setEnabled')) {
                $cacheManager->setEnabled(true);
            }
            $results['cache'] = $this->runSingleBenchmark($db, $query, $iterations);
        }

        $this->printCompareResults($results);

        return 0;
    }

    /**
     * Run a single benchmark iteration.
     *
     * @param PdoDb $db
     * @param string $query
     * @param int $iterations
     *
     * @return array<string, mixed>
     */
    protected function runSingleBenchmark(PdoDb $db, string $query, int $iterations): array
    {
        $db->enableProfiling(0.0);
        $profiler = $db->getProfiler();
        if ($profiler !== null) {
            $profiler->reset();
        }

        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            try {
                $db->rawQuery($query);
            } catch (\Exception $e) {
                // Ignore errors
            }
        }
        $endTime = microtime(true);

        $totalTime = $endTime - $startTime;
        $profilerStats = null;
        if ($profiler !== null) {
            $aggregated = $profiler->getAggregatedStats();
            if ($aggregated['total_queries'] > 0) {
                $profilerStats = $aggregated;
            }
        }

        return [
            'iterations' => $iterations,
            'total_time' => $totalTime,
            'avg_time' => $totalTime / $iterations,
            'qps' => $iterations / $totalTime,
            'min_time' => $profilerStats['min_time'] ?? 0.0,
            'max_time' => $profilerStats['max_time'] ?? 0.0,
        ];
    }

    /**
     * Profile queries with detailed statistics.
     *
     * @return int Exit code
     */
    protected function benchmarkProfile(): int
    {
        $threshold = $this->parseTime((string)$this->getOption('slow-threshold', '100ms'));
        $queryOption = $this->getOption('query', null);
        $query = is_string($queryOption) ? $queryOption : null;
        $iterations = (int)$this->getOption('iterations', 100);

        $db = $this->getDb();
        $db->enableProfiling($threshold);

        if ($query === null || $query === '') {
            return $this->showError('Please specify a query with --query option');
        }

        // Profile specific query
        $params = $this->parseQueryParams($query);
        for ($i = 0; $i < $iterations; $i++) {
            try {
                $db->rawQuery($query, $params);
            } catch (\Exception $e) {
                return $this->showError('Query execution failed: ' . $e->getMessage());
            }
        }

        $profiler = $db->getProfiler();
        if ($profiler === null) {
            return $this->showError('Profiler not available');
        }

        $stats = $profiler->getStats();
        $aggregated = $profiler->getAggregatedStats();
        $slowest = $profiler->getSlowestQueries(10);

        $this->printProfileResults($stats, $aggregated, $slowest, $threshold);

        return 0;
    }

    /**
     * Generate HTML benchmark report.
     *
     * @return int Exit code
     */
    protected function benchmarkReport(): int
    {
        $output = (string)$this->getOption('output', 'benchmark-report.html');
        $query = (string)$this->getOption('query', 'SELECT 1');
        $iterations = (int)$this->getOption('iterations', 100);

        $db = $this->getDb();
        $db->enableProfiling(0.0);

        $results = $this->runSingleBenchmark($db, $query, $iterations);
        $profiler = $db->getProfiler();
        $stats = $profiler !== null ? $profiler->getStats() : [];

        $html = $this->generateHtmlReport($results, $stats, $query);

        file_put_contents($output, $html);
        static::success("Benchmark report saved to: {$output}");

        return 0;
    }

    /**
     * Parse query parameters from SQL string.
     *
     * @param string $sql
     *
     * @return array<int|string, mixed>
     */
    protected function parseQueryParams(string $sql): array
    {
        $params = [];

        // Extract named parameters (:param)
        if (preg_match_all('/:(\w+)/', $sql, $matches)) {
            foreach ($matches[1] as $param) {
                // Set default values for common parameters
                if ($param === 'id') {
                    $params[$param] = 1;
                } elseif ($param === 'limit') {
                    $params[$param] = 10;
                } else {
                    $params[$param] = null;
                }
            }
        }

        return $params;
    }

    /**
     * Parse time string to seconds.
     *
     * @param string $timeString e.g., "100ms", "1.5s", "500ms"
     *
     * @return float Seconds
     */
    protected function parseTime(string $timeString): float
    {
        $timeString = trim($timeString);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s)?$/i', $timeString, $matches)) {
            $value = (float)$matches[1];
            $unit = strtolower($matches[2] ?? 's');
            if ($unit === 'ms') {
                return $value / 1000;
            }
            return $value;
        }
        return 1.0; // Default to 1 second
    }

    /**
     * Generate test data for table columns.
     *
     * @param array<int, array<string, mixed>> $columns
     * @param int $index
     *
     * @return array<string, mixed>
     */
    protected function generateTestData(array $columns, int $index): array
    {
        $data = [];
        foreach ($columns as $col) {
            $name = $col['Field'] ?? $col['name'] ?? $col['COLUMN_NAME'] ?? $col['column_name'] ?? null;
            if ($name === null) {
                continue;
            }

            // Skip primary key if auto-increment
            $isPrimary = ($col['Key'] ?? '') === 'PRI' || ($col['primary'] ?? false) || ($col['PRIMARY'] ?? false);
            $isAutoIncrement = str_contains(strtolower($col['Extra'] ?? $col['extra'] ?? ''), 'auto_increment') ||
                               str_contains(strtolower($col['EXTRA'] ?? ''), 'auto_increment');

            if ($isPrimary && $isAutoIncrement) {
                continue;
            }

            $type = strtolower($col['Type'] ?? $col['type'] ?? $col['TYPE'] ?? $col['data_type'] ?? 'string');
            $nullable = ($col['Null'] ?? 'NO') === 'YES' || ($col['nullable'] ?? false) || ($col['NULL'] ?? 'YES') === 'YES';

            if (str_contains($type, 'int')) {
                $data[$name] = $index;
            } elseif (str_contains($type, 'varchar') || str_contains($type, 'text') || str_contains($type, 'string')) {
                $data[$name] = "test_data_{$index}";
            } elseif (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
                $data[$name] = date('Y-m-d H:i:s');
            } elseif (str_contains($type, 'date')) {
                $data[$name] = date('Y-m-d');
            } elseif (str_contains($type, 'bool') || str_contains($type, 'boolean')) {
                $data[$name] = ($index % 2) === 0;
            } elseif (str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'decimal')) {
                $data[$name] = (float)$index + 0.5;
            } else {
                $data[$name] = $nullable ? null : "value_{$index}";
            }
        }

        return $data;
    }

    /**
     * Get primary key from row.
     *
     * @param PdoDb $db
     * @param string $table
     * @param array<string, mixed> $row
     *
     * @return array{column: string, value: mixed}|null
     */
    protected function getPrimaryKey(PdoDb $db, string $table, array $row): ?array
    {
        try {
            // Try to get primary key from indexes
            $indexes = $db->schema()->getIndexes($table);
            $pkColumn = null;

            foreach ($indexes as $index) {
                $indexName = $index['Key_name'] ?? $index['indexname'] ?? $index['name'] ?? '';
                if (strtoupper($indexName) === 'PRIMARY') {
                    $pkColumn = $index['Column_name'] ?? $index['column_name'] ?? $index['column'] ?? null;
                    break;
                }
            }

            if ($pkColumn === null) {
                // Try common primary key names
                foreach (['id', 'ID', 'Id'] as $commonPk) {
                    if (isset($row[$commonPk])) {
                        return ['column' => $commonPk, 'value' => $row[$commonPk]];
                    }
                }
                return null;
            }

            if (isset($row[$pkColumn])) {
                return ['column' => $pkColumn, 'value' => $row[$pkColumn]];
            }

            return null;
        } catch (\Exception $e) {
            // Try common primary key names as fallback
            foreach (['id', 'ID', 'Id'] as $commonPk) {
                if (isset($row[$commonPk])) {
                    return ['column' => $commonPk, 'value' => $row[$commonPk]];
                }
            }
            return null;
        }
    }

    /**
     * Print query benchmark results.
     *
     * @param array<string, mixed> $results
     */
    protected function printQueryResults(array $results): void
    {
        echo "Query Benchmark Results\n";
        echo "=======================\n\n";
        echo "Iterations: {$results['iterations']}\n";
        echo 'Total time: ' . round($results['total_time'], 4) . "s\n";
        echo 'Average time: ' . round($results['avg_time'] * 1000, 2) . "ms\n";
        if ($results['min_time'] > 0) {
            echo 'Min time: ' . round($results['min_time'] * 1000, 2) . "ms\n";
        }
        if ($results['max_time'] > 0) {
            echo 'Max time: ' . round($results['max_time'] * 1000, 2) . "ms\n";
        }
        echo 'Queries per second: ' . round($results['qps'], 2) . "\n";
        if ($results['avg_memory'] > 0) {
            echo 'Average memory: ' . $this->formatMemory($results['avg_memory']) . "\n";
        }
        echo "\n";
    }

    /**
     * Print CRUD benchmark results.
     *
     * @param array<string, array<string, mixed>> $results
     * @param int $iterations
     */
    protected function printCrudResults(array $results, int $iterations): void
    {
        echo "CRUD Benchmark Results\n";
        echo "=====================\n\n";

        foreach ($results as $operation => $result) {
            if (isset($result['error'])) {
                echo ucfirst($operation) . ": {$result['error']}\n";
                continue;
            }

            echo ucfirst($operation) . ":\n";
            echo "  Iterations: {$result['iterations']}\n";
            echo '  Total time: ' . round($result['total_time'], 4) . "s\n";
            echo '  Average time: ' . round($result['avg_time'] * 1000, 2) . "ms\n";
            echo '  Operations per second: ' . round($result['ops_per_sec'], 2) . "\n";
            echo "\n";
        }
    }

    /**
     * Print compare results.
     *
     * @param array<string, array<string, mixed>> $results
     */
    protected function printCompareResults(array $results): void
    {
        echo "Benchmark Comparison\n";
        echo "====================\n\n";

        foreach ($results as $config => $result) {
            echo ucfirst($config) . ":\n";
            echo "  Iterations: {$result['iterations']}\n";
            echo '  Total time: ' . round($result['total_time'], 4) . "s\n";
            echo '  Average time: ' . round($result['avg_time'] * 1000, 2) . "ms\n";
            echo '  Queries per second: ' . round($result['qps'], 2) . "\n";
            echo "\n";
        }

        if (count($results) === 2) {
            $noCache = $results['no-cache'] ?? null;
            $cache = $results['cache'] ?? null;

            if ($noCache !== null && $cache !== null) {
                $speedup = $noCache['total_time'] > 0 ? ($noCache['total_time'] / $cache['total_time']) : 1.0;
                echo 'Speedup with cache: ' . round($speedup, 2) . "x\n";
            }
        }
    }

    /**
     * Print profile results.
     *
     * @param array<string, array<string, mixed>> $stats
     * @param array<string, mixed> $aggregated
     * @param array<int, array<string, mixed>> $slowest
     * @param float $threshold
     */
    protected function printProfileResults(array $stats, array $aggregated, array $slowest, float $threshold): void
    {
        echo "Query Profile Results\n";
        echo "=====================\n\n";

        echo "Aggregated Statistics:\n";
        echo "  Total queries: {$aggregated['total_queries']}\n";
        echo '  Total time: ' . round($aggregated['total_time'], 4) . "s\n";
        echo '  Average time: ' . round($aggregated['avg_time'] * 1000, 2) . "ms\n";
        echo '  Min time: ' . round($aggregated['min_time'] * 1000, 2) . "ms\n";
        echo '  Max time: ' . round($aggregated['max_time'] * 1000, 2) . "ms\n";
        echo "  Slow queries (>{$threshold}s): {$aggregated['slow_queries']}\n";
        echo "\n";

        if (!empty($slowest)) {
            echo "Slowest Queries:\n";
            foreach ($slowest as $i => $query) {
                echo '  ' . ($i + 1) . '. Avg: ' . round($query['avg_time'] * 1000, 2) . 'ms, ';
                echo 'Max: ' . round($query['max_time'] * 1000, 2) . 'ms, ';
                echo "Count: {$query['count']}\n";
                echo '     SQL: ' . substr($query['sql'], 0, 80) . "...\n";
            }
        }
    }

    /**
     * Generate HTML report.
     *
     * @param array<string, mixed> $results
     * @param array<string, array<string, mixed>> $stats
     * @param string $query
     *
     * @return string HTML content
     */
    protected function generateHtmlReport(array $results, array $stats, string $query): string
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>PDOdb Benchmark Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .metric { font-weight: bold; }
    </style>
</head>
<body>
    <h1>PDOdb Benchmark Report</h1>
    <p>Generated: <?php echo date('Y-m-d H:i:s'); ?></p>

    <h2>Query</h2>
    <pre><?php echo htmlspecialchars($query); ?></pre>

    <h2>Results</h2>
    <table>
        <tr>
            <th>Metric</th>
            <th>Value</th>
        </tr>
HTML;

        $html .= "<tr><td class='metric'>Iterations</td><td>{$results['iterations']}</td></tr>\n";
        $html .= "<tr><td class='metric'>Total Time</td><td>" . round($results['total_time'], 4) . "s</td></tr>\n";
        $html .= "<tr><td class='metric'>Average Time</td><td>" . round($results['avg_time'] * 1000, 2) . "ms</td></tr>\n";
        $html .= "<tr><td class='metric'>Queries Per Second</td><td>" . round($results['qps'], 2) . "</td></tr>\n";

        if (!empty($stats)) {
            $html .= "</table>\n<h2>Query Statistics</h2>\n<table>\n";
            $html .= "<tr><th>SQL</th><th>Count</th><th>Avg Time</th><th>Total Time</th></tr>\n";
            foreach ($stats as $stat) {
                $sql = htmlspecialchars(substr($stat['sql'], 0, 100));
                $html .= "<tr><td>{$sql}...</td><td>{$stat['count']}</td><td>" . round($stat['avg_time'] * 1000, 2) . 'ms</td><td>' . round($stat['total_time'], 4) . "s</td></tr>\n";
            }
        }

        $html .= <<<'HTML'
    </table>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Format memory size.
     *
     * @param int $bytes
     *
     * @return string
     */
    protected function formatMemory(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = (int)floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Benchmark and Performance Testing\n\n";
        echo "Usage: pdodb benchmark <subcommand> [arguments] [options]\n\n";
        echo "Subcommands:\n";
        echo "  query <sql>                    Benchmark a specific SQL query\n";
        echo "  crud <table>                   Benchmark CRUD operations on a table\n";
        echo "  load                           Load testing with concurrent connections\n";
        echo "  compare                        Compare performance with/without cache\n";
        echo "  profile                        Profile queries with detailed statistics\n";
        echo "  report                         Generate HTML benchmark report\n\n";
        echo "Options:\n";
        echo "  --iterations=N                 Number of iterations (default: 100 for query, 1000 for crud)\n";
        echo "  --warmup=N                     Warmup iterations before benchmark (default: 10)\n";
        echo "  --connections=N                Number of concurrent connections for load test (default: 10)\n";
        echo "  --duration=N                   Duration in seconds for load test (default: 60)\n";
        echo "  --query=SQL                    SQL query for load/profile/report\n";
        echo "  --cache                        Enable cache for compare\n";
        echo "  --no-cache                     Disable cache for compare\n";
        echo "  --slow-threshold=TIME          Slow query threshold (e.g., 100ms, 1s) for profile\n";
        echo "  --output=FILE                  Output file for report (default: benchmark-report.html)\n\n";
        echo "Examples:\n";
        echo "  pdodb benchmark query \"SELECT * FROM users WHERE id = :id\"\n";
        echo "  pdodb benchmark query \"SELECT * FROM users\" --iterations=1000\n";
        echo "  pdodb benchmark crud users --iterations=500\n";
        echo "  pdodb benchmark load --connections=50 --duration=60\n";
        echo "  pdodb benchmark compare --query=\"SELECT * FROM users\"\n";
        echo "  pdodb benchmark profile --query=\"SELECT * FROM users\" --slow-threshold=100ms\n";
        echo "  pdodb benchmark report --query=\"SELECT * FROM users\" --output=report.html\n";
        return 0;
    }
}
