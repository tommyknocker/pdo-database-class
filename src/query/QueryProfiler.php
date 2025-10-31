<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use Psr\Log\LoggerInterface;

/**
 * Query Performance Profiler.
 *
 * Tracks query execution times, memory usage, and provides statistics.
 */
class QueryProfiler
{
    /** @var bool Whether profiling is enabled */
    protected bool $enabled = false;

    /** @var LoggerInterface|null Logger for slow query detection */
    protected ?LoggerInterface $logger = null;

    /** @var float Slow query threshold in seconds */
    protected float $slowQueryThreshold = 1.0;

    /**
     * @var array<string, array{
     *     sql: string,
     *     count: int,
     *     total_time: float,
     *     avg_time: float,
     *     min_time: float,
     *     max_time: float,
     *     total_memory: int,
     *     avg_memory: int,
     *     slow_queries: int
     * }> Query statistics keyed by SQL hash
     */
    protected array $stats = [];

    /**
     * @var array<int, array{
     *     sql: string,
     *     params: array<int|string, mixed>,
     *     start_time: float,
     *     start_memory: int
     * }> Active queries being profiled
     */
    protected array $activeQueries = [];

    /**
     * Enable profiling.
     *
     * @return self
     */
    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    /**
     * Disable profiling.
     *
     * @return self
     */
    public function disable(): self
    {
        $this->enabled = false;
        $this->stats = [];
        $this->activeQueries = [];
        return $this;
    }

    /**
     * Check if profiling is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set logger for slow query detection.
     *
     * @param LoggerInterface|null $logger
     *
     * @return self
     */
    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get logger.
     *
     * @return LoggerInterface|null
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Set slow query threshold.
     *
     * @param float $seconds Threshold in seconds
     *
     * @return self
     */
    public function setSlowQueryThreshold(float $seconds): self
    {
        $this->slowQueryThreshold = $seconds;
        return $this;
    }

    /**
     * Get slow query threshold.
     *
     * @return float
     */
    public function getSlowQueryThreshold(): float
    {
        return $this->slowQueryThreshold;
    }

    /**
     * Start profiling a query.
     *
     * @param string $sql SQL query
     * @param array<int|string, mixed> $params Query parameters
     *
     * @return int Query ID for tracking, or -1 if profiling disabled
     */
    public function startQuery(string $sql, array $params = []): int
    {
        if (!$this->enabled) {
            return -1;
        }

        $queryId = count($this->activeQueries);
        $this->activeQueries[$queryId] = [
            'sql' => $sql,
            'params' => $params,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
        ];

        return $queryId;
    }

    /**
     * End profiling a query.
     *
     * @param int $queryId Query ID from startQuery()
     *
     * @return array{time: float, memory: int}|null Profile data or null if not found
     */
    public function endQuery(int $queryId): ?array
    {
        if (!$this->enabled || $queryId < 0 || !isset($this->activeQueries[$queryId])) {
            return null;
        }

        $query = $this->activeQueries[$queryId];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $executionTime = $endTime - $query['start_time'];
        $memoryUsed = max(0, $endMemory - $query['start_memory']);

        // Generate hash for statistics grouping (same SQL = same hash)
        $sqlHash = $this->hashQuery($query['sql']);

        // Update statistics
        if (!isset($this->stats[$sqlHash])) {
            $this->stats[$sqlHash] = [
                'sql' => $query['sql'], // Store first occurrence SQL
                'count' => 0,
                'total_time' => 0.0,
                'avg_time' => 0.0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0.0,
                'total_memory' => 0,
                'avg_memory' => 0,
                'slow_queries' => 0,
            ];
        }

        $this->stats[$sqlHash]['count']++;
        $this->stats[$sqlHash]['total_time'] += $executionTime;
        $this->stats[$sqlHash]['min_time'] = min($this->stats[$sqlHash]['min_time'], $executionTime);
        $this->stats[$sqlHash]['max_time'] = max($this->stats[$sqlHash]['max_time'], $executionTime);
        $this->stats[$sqlHash]['total_memory'] += $memoryUsed;
        $this->stats[$sqlHash]['avg_time'] = $this->stats[$sqlHash]['total_time'] / $this->stats[$sqlHash]['count'];
        $this->stats[$sqlHash]['avg_memory'] = (int)($this->stats[$sqlHash]['total_memory'] / $this->stats[$sqlHash]['count']);

        // Check for slow query
        if ($executionTime >= $this->slowQueryThreshold) {
            $this->stats[$sqlHash]['slow_queries']++;
            $this->logSlowQuery($query['sql'], $query['params'], $executionTime, $memoryUsed);
        }

        // Remove from active queries
        unset($this->activeQueries[$queryId]);

        return [
            'time' => $executionTime,
            'memory' => $memoryUsed,
        ];
    }

    /**
     * Get all statistics.
     *
     * @return array<string, array{
     *     sql: string,
     *     count: int,
     *     total_time: float,
     *     avg_time: float,
     *     min_time: float,
     *     max_time: float,
     *     total_memory: int,
     *     avg_memory: int,
     *     slow_queries: int
     * }>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get aggregated statistics across all queries.
     *
     * @return array{
     *     total_queries: int,
     *     total_time: float,
     *     avg_time: float,
     *     min_time: float,
     *     max_time: float,
     *     total_memory: int,
     *     avg_memory: int,
     *     slow_queries: int
     * }
     */
    public function getAggregatedStats(): array
    {
        $totalQueries = 0;
        $totalTime = 0.0;
        $minTime = PHP_FLOAT_MAX;
        $maxTime = 0.0;
        $totalMemory = 0;
        $totalSlowQueries = 0;

        foreach ($this->stats as $stat) {
            $totalQueries += $stat['count'];
            $totalTime += $stat['total_time'];
            $minTime = min($minTime, $stat['min_time']);
            $maxTime = max($maxTime, $stat['max_time']);
            $totalMemory += $stat['total_memory'];
            $totalSlowQueries += $stat['slow_queries'];
        }

        return [
            'total_queries' => $totalQueries,
            'total_time' => $totalTime,
            'avg_time' => $totalQueries > 0 ? $totalTime / $totalQueries : 0.0,
            'min_time' => $minTime !== PHP_FLOAT_MAX ? $minTime : 0.0,
            'max_time' => $maxTime,
            'total_memory' => $totalMemory,
            'avg_memory' => $totalQueries > 0 ? (int)($totalMemory / $totalQueries) : 0,
            'slow_queries' => $totalSlowQueries,
        ];
    }

    /**
     * Reset all statistics.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->stats = [];
        $this->activeQueries = [];
        return $this;
    }

    /**
     * Get slowest queries.
     *
     * @param int $limit Number of queries to return
     *
     * @return array<int, array{
     *     sql: string,
     *     count: int,
     *     avg_time: float,
     *     max_time: float,
     *     slow_queries: int,
     *     avg_memory: int
     * }>
     */
    public function getSlowestQueries(int $limit = 10): array
    {
        $sorted = $this->stats;
        usort($sorted, function ($a, $b) {
            return $b['avg_time'] <=> $a['avg_time'];
        });

        $result = [];
        foreach (array_slice($sorted, 0, $limit) as $stat) {
            $result[] = [
                'sql' => $stat['sql'],
                'count' => $stat['count'],
                'avg_time' => $stat['avg_time'],
                'max_time' => $stat['max_time'],
                'slow_queries' => $stat['slow_queries'],
                'avg_memory' => $stat['avg_memory'],
            ];
        }

        return $result;
    }

    /**
     * Hash SQL query for statistics grouping.
     *
     * @param string $sql
     *
     * @return string
     */
    protected function hashQuery(string $sql): string
    {
        // Normalize SQL: remove extra whitespace and parameter placeholders
        $trimmed = trim($sql);
        if ($trimmed === '') {
            return hash('sha256', '');
        }
        $normalized = preg_replace('/\s+/', ' ', $trimmed);
        if (!is_string($normalized)) {
            $normalized = $trimmed;
        }
        $normalized = preg_replace('/:[a-zA-Z0-9_]+/', ':param', $normalized);
        if (!is_string($normalized)) {
            $normalized = $trimmed;
        }
        $normalized = preg_replace('/\?/', '?', $normalized);
        if (!is_string($normalized)) {
            $normalized = $trimmed;
        }
        return hash('sha256', $normalized);
    }

    /**
     * Log slow query.
     *
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @param float $executionTime
     * @param int $memoryUsed
     */
    protected function logSlowQuery(string $sql, array $params, float $executionTime, int $memoryUsed): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('query.slow', [
            'sql' => $sql,
            'params' => $params,
            'execution_time' => $executionTime,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'memory_used' => $memoryUsed,
            'memory_used_formatted' => $this->formatMemory($memoryUsed),
            'threshold' => $this->slowQueryThreshold,
        ]);
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
}
