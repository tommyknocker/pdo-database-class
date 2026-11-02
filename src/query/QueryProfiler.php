<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\helpers\SqlContextTokenizer;

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

    /** @var int Next id for an in-flight measurement */
    protected int $nextQueryId = 1;

    /** @var bool Include parameter values in the slow-query log */
    protected bool $logParameters = false;

    /** @var int Maximum distinct SQL shapes kept in memory */
    protected int $maxTrackedQueries = 500;

    /** @var bool True when a new SQL shape was dropped to stay within the limit */
    protected bool $truncated = false;

    /** @var array<string, array{model: string, relation: string, lazy_loads: int, eager_loads: int, lazy_queries: int, eager_queries: int, sql: list<string>}> */
    protected array $relationStats = [];
    /** @var array<int, list<array{key: ?string, mode: string}>> */
    protected array $relationStacks = [];
    protected bool $relationStatsTruncated = false;

    /** @var float Slow query threshold in seconds */
    protected float $slowQueryThreshold = 1.0;

    /**
     * Live decision for the current connection. Null keeps double-quoted text,
     * because it may be an identifier.
     *
     * @var null|\Closure(): bool
     */
    protected ?\Closure $doubleQuotedTextIsLiteral = null;

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

    /** @var array<string, string> SQL redacted using the originating connection */
    protected array $redactedSql = [];

    /**
     * @var array<int, array{
     *     sql: string,
     *     redacted_sql: string,
     *     params: array<int|string, mixed>,
     *     start_time: float,
     *     start_memory: int
     * }> Active queries being profiled
     */
    protected array $activeQueries = [];

    /**
     * Enable profiling.
     *
     * @return static
     */
    public function enable(): static
    {
        $this->enabled = true;
        return $this;
    }

    /**
     * Disable profiling.
     *
     * @return static
     */
    public function disable(): static
    {
        $this->enabled = false;
        $this->stats = [];
        $this->redactedSql = [];
        $this->activeQueries = [];
        $this->nextQueryId = 1;
        $this->truncated = false;
        $this->relationStats = [];
        $this->relationStacks = [];
        $this->relationStatsTruncated = false;
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
     * @return static
     */
    public function setLogger(?LoggerInterface $logger): static
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
     * @return static
     */
    public function setSlowQueryThreshold(float $seconds): static
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

        $redacted = $this->redactLiterals($sql);
        $fiber = \Fiber::getCurrent();
        $stackId = $fiber === null ? 0 : spl_object_id($fiber);
        $stack = $this->relationStacks[$stackId] ?? [];
        $frame = $stack === [] ? null : $stack[array_key_last($stack)];
        if ($frame !== null && $frame['key'] !== null && isset($this->relationStats[$frame['key']])) {
            $key = $frame['key'];
            $stat = $this->relationStats[$key];
            if ($frame['mode'] === 'lazy') {
                $stat['lazy_queries']++;
            } else {
                $stat['eager_queries']++;
            }
            $sample = substr($redacted, 0, 2048);
            while ($sample !== '' && preg_match('//u', $sample) !== 1) {
                $sample = substr($sample, 0, -1);
            }
            if (count($stat['sql']) < 3 && !in_array($sample, $stat['sql'], true)) {
                $stat['sql'][] = $sample;
            }
            $this->relationStats[$key] = $stat;
        }

        $queryId = $this->nextQueryId;
        $this->nextQueryId++;
        $this->activeQueries[$queryId] = [
            'sql' => $sql,
            'redacted_sql' => $redacted,
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
            if (count($this->stats) >= $this->maxTrackedQueries) {
                $this->truncated = true;
                unset($this->activeQueries[$queryId]);
                if ($executionTime >= $this->slowQueryThreshold) {
                    $this->logSlowQuery($this->logParameters ? $query['sql'] : $query['redacted_sql'], $query['params'], $executionTime, $memoryUsed);
                }
                return [
                    'time' => $executionTime,
                    'memory' => $memoryUsed,
                ];
            }
            $this->redactedSql[$sqlHash] = $query['redacted_sql'];
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
            $this->logSlowQuery($this->logParameters ? $query['sql'] : $query['redacted_sql'], $query['params'], $executionTime, $memoryUsed);
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
     * @return static
     */
    public function reset(): static
    {
        $this->stats = [];
        $this->redactedSql = [];
        $this->activeQueries = [];
        $this->nextQueryId = 1;
        $this->truncated = false;
        $this->relationStats = [];
        $this->relationStacks = [];
        $this->relationStatsTruncated = false;
        return $this;
    }

    /** Start attributing SQL to a relation on the current fiber. */
    public function beginRelation(string $model, string $relation, string $mode): bool
    {
        if (!$this->enabled || !in_array($mode, ['lazy', 'eager'], true)) {
            return false;
        }
        $key = hash('sha256', $model . "\0" . $relation);
        if (!isset($this->relationStats[$key])) {
            if (count($this->relationStats) >= 100) {
                $this->relationStatsTruncated = true;
                $key = null;
            } else {
                $this->relationStats[$key] = ['model' => $model, 'relation' => $relation,
                    'lazy_loads' => 0, 'eager_loads' => 0, 'lazy_queries' => 0, 'eager_queries' => 0, 'sql' => []];
            }
        }
        if ($key !== null) {
            $this->relationStats[$key][$mode . '_loads']++;
        }
        $fiber = \Fiber::getCurrent();
        $stackId = $fiber === null ? 0 : spl_object_id($fiber);
        $this->relationStacks[$stackId][] = ['key' => $key, 'mode' => $mode];
        return true;
    }

    public function endRelation(): void
    {
        $fiber = \Fiber::getCurrent();
        $stackId = $fiber === null ? 0 : spl_object_id($fiber);
        if (!empty($this->relationStacks[$stackId])) {
            array_pop($this->relationStacks[$stackId]);
            if ($this->relationStacks[$stackId] === []) {
                unset($this->relationStacks[$stackId]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function getRelationStats(): array
    {
        $results = [];
        foreach ($this->relationStats as $stat) {
            $stat['possible_n_plus_one'] = $stat['lazy_loads'] >= 10 && $stat['lazy_queries'] >= 10;
            $results[] = $stat;
        }
        return $results;
    }

    public function areRelationStatsTruncated(): bool
    {
        return $this->relationStatsTruncated;
    }

    /**
     * Get slowest queries.
     *
     * @param int $limit Number of queries to return
     *
     * @return array<int, array{
     *     sql: string,
     *     count: int,
     *     total_time: float,
     *     avg_time: float,
     *     max_time: float,
     *     slow_queries: int,
     *     avg_memory: int,
     *     possible_n_plus_one: bool
     * }>
     */
    public function getSlowestQueries(int $limit = 10): array
    {
        $sorted = $this->stats;
        uasort($sorted, function ($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });

        $result = [];
        foreach (array_slice($sorted, 0, $limit, true) as $hash => $stat) {
            $result[] = [
                'sql' => $this->redactedSql[$hash] ?? $this->redactLiterals($stat['sql']),
                'count' => $stat['count'],
                'total_time' => $stat['total_time'],
                'avg_time' => $stat['avg_time'],
                'max_time' => $stat['max_time'],
                'slow_queries' => $stat['slow_queries'],
                'avg_memory' => $stat['avg_memory'],
                'possible_n_plus_one' => $stat['count'] >= 10,
            ];
        }

        return $result;
    }

    /**
     * Raw SQL for a ranked slow query (1 = slowest by total time).
     */
    public function getRawSqlBySlowestRank(int $rank): ?string
    {
        if ($rank < 1) {
            return null;
        }

        $sorted = $this->stats;
        uasort($sorted, static function ($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });

        $index = 0;
        foreach ($sorted as $stat) {
            $index++;
            if ($index === $rank) {
                return $stat['sql'];
            }
        }

        return null;
    }

    /**
     * Keep parameter values in the slow-query log.
     *
     * The default log records redacted SQL and no parameter values.
     */
    public function setLogParameters(bool $enabled): static
    {
        $this->logParameters = $enabled;
        return $this;
    }

    public function logsParameters(): bool
    {
        return $this->logParameters;
    }

    /**
     * @param null|\Closure(): bool $decision
     */
    public function setDoubleQuotedTextIsLiteral(?\Closure $decision): static
    {
        $this->doubleQuotedTextIsLiteral = $decision;

        return $this;
    }

    public function setMaxTrackedQueries(int $limit): static
    {
        $this->maxTrackedQueries = max(1, $limit);
        return $this;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function slowQueryContext(string $sql, array $params, float $executionTime, int $memoryUsed): array
    {
        $context = [
            'sql' => $this->logParameters ? $sql : $this->redactLiterals($sql),
            'execution_time' => $executionTime,
            'execution_time_ms' => round($executionTime * 1000, 2),
            'memory_used' => $memoryUsed,
            'memory_used_formatted' => $this->formatMemory($memoryUsed),
            'threshold' => $this->slowQueryThreshold,
            'parameters_included' => $this->logParameters,
        ];
        if ($this->logParameters) {
            $context['params'] = $params;
        }
        return $context;
    }

    protected function redactLiterals(string $sql): string
    {
        $doubleQuotesAreLiterals = false;
        if ($this->doubleQuotedTextIsLiteral !== null && str_contains($sql, '"')) {
            $doubleQuotesAreLiterals = ($this->doubleQuotedTextIsLiteral)();
        }

        return SqlContextTokenizer::redact($sql, $doubleQuotesAreLiterals);
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

        $this->logger->warning('query.slow', $this->slowQueryContext($sql, $params, $executionTime, $memoryUsed));
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
