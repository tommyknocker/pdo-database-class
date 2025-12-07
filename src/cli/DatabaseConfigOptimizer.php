<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Database configuration optimizer.
 *
 * Provides recommendations for database server configuration
 * based on available resources (memory, CPU cores, workload type).
 */
class DatabaseConfigOptimizer
{
    protected PdoDb $db;
    protected string $dialect;

    /**
     * Create optimizer instance.
     *
     * @param PdoDb $db Database instance
     */
    public function __construct(PdoDb $db)
    {
        $this->db = $db;
        $this->dialect = $db->connection->getDialect()->getDriverName();
    }

    /**
     * Analyze and provide configuration recommendations.
     *
     * @param int $memoryBytes Available memory in bytes
     * @param int $cpuCores Number of CPU cores
     * @param string $workload Workload type (oltp|olap|mixed)
     * @param string $diskType Disk type (ssd|hdd|nvme)
     * @param int|null $expectedConnections Expected number of connections
     *
     * @return array<string, mixed> Analysis results with recommendations
     */
    public function analyze(
        int $memoryBytes,
        int $cpuCores,
        string $workload = 'oltp',
        string $diskType = 'ssd',
        ?int $expectedConnections = null
    ): array {
        // Get current server variables
        $currentVars = $this->getCurrentVariables();

        // Calculate recommendations based on dialect
        $recommendations = $this->calculateRecommendations(
            $memoryBytes,
            $cpuCores,
            $workload,
            $diskType,
            $expectedConnections,
            $currentVars
        );

        // Compare current vs recommended
        $comparison = $this->compareSettings($currentVars, $recommendations);

        return [
            'dialect' => $this->dialect,
            'resources' => [
                'memory_bytes' => $memoryBytes,
                'memory_human' => $this->formatBytes($memoryBytes),
                'cpu_cores' => $cpuCores,
                'workload' => $workload,
                'disk_type' => $diskType,
                'expected_connections' => $expectedConnections,
            ],
            'current_variables' => $currentVars,
            'recommendations' => $recommendations,
            'comparison' => $comparison,
            'summary' => $this->generateSummary($comparison),
            'sql_commands' => $this->generateSqlCommands($recommendations),
        ];
    }

    /**
     * Get current server variables.
     *
     * @return array<string, string> Variable name => value
     */
    protected function getCurrentVariables(): array
    {
        try {
            $dialect = $this->db->connection->getDialect();
            $vars = $dialect->getServerVariables($this->db);
            $result = [];
            foreach ($vars as $var) {
                $name = $var['name'] ?? '';
                $value = $var['value'] ?? '';
                if ($name !== '') {
                    $result[$name] = $value;
                }
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Calculate recommendations based on resources.
     *
     * @param int $memoryBytes Memory in bytes
     * @param int $cpuCores CPU cores
     * @param string $workload Workload type
     * @param string $diskType Disk type
     * @param int|null $expectedConnections Expected connections
     * @param array<string, string> $currentVars Current variables
     *
     * @return array<string, array<string, mixed>> Recommendations
     */
    protected function calculateRecommendations(
        int $memoryBytes,
        int $cpuCores,
        string $workload,
        string $diskType,
        ?int $expectedConnections,
        array $currentVars
    ): array {
        return match ($this->dialect) {
            'mysql', 'mariadb' => $this->calculateMySQLRecommendations(
                $memoryBytes,
                $cpuCores,
                $workload,
                $diskType,
                $expectedConnections,
                $currentVars
            ),
            'pgsql' => $this->calculatePostgreSQLRecommendations(
                $memoryBytes,
                $cpuCores,
                $workload,
                $diskType,
                $expectedConnections,
                $currentVars
            ),
            'oci' => $this->calculateOracleRecommendations(
                $memoryBytes,
                $cpuCores,
                $workload,
                $diskType,
                $expectedConnections,
                $currentVars
            ),
            'sqlsrv' => $this->calculateMSSQLRecommendations(
                $memoryBytes,
                $cpuCores,
                $workload,
                $diskType,
                $expectedConnections,
                $currentVars
            ),
            'sqlite' => $this->calculateSQLiteRecommendations(
                $memoryBytes,
                $cpuCores,
                $workload,
                $diskType,
                $expectedConnections,
                $currentVars
            ),
            default => [],
        };
    }

    /**
     * Calculate MySQL/MariaDB recommendations.
     *
     * @param int $memoryBytes Memory in bytes
     * @param int $cpuCores CPU cores
     * @param string $workload Workload type
     * @param string $diskType Disk type
     * @param int|null $expectedConnections Expected connections
     * @param array<string, string> $currentVars Current variables
     *
     * @return array<string, array<string, mixed>> Recommendations
     */
    protected function calculateMySQLRecommendations(
        int $memoryBytes,
        int $cpuCores,
        string $workload,
        string $diskType,
        ?int $expectedConnections,
        array $currentVars
    ): array {
        $memoryGB = $memoryBytes / (1024 * 1024 * 1024);
        $recommendations = [];

        // innodb_buffer_pool_size: 70-80% of available memory for OLTP, 50-60% for OLAP
        $bufferPoolPercent = $workload === 'oltp' ? 0.75 : 0.55;
        $bufferPoolBytes = (int)($memoryBytes * $bufferPoolPercent);
        // Round to nearest MB
        $bufferPoolBytes = (int)(round($bufferPoolBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['innodb_buffer_pool_size'] = [
            'value' => (string)$bufferPoolBytes,
            'value_human' => $this->formatBytes($bufferPoolBytes),
            'priority' => 'high',
            'reason' => sprintf(
                'InnoDB buffer pool should be %d%% of available memory for %s workload. ' .
                'This is the most important setting for MySQL performance.',
                (int)($bufferPoolPercent * 100),
                strtoupper($workload)
            ),
        ];

        // max_connections: based on CPU and expected connections
        $maxConn = $expectedConnections ?? max(200, $cpuCores * 10);
        // Cap at reasonable maximum
        $maxConn = min($maxConn, 1000);
        $recommendations['max_connections'] = [
            'value' => (string)$maxConn,
            'value_human' => (string)$maxConn,
            'priority' => 'medium',
            'reason' => sprintf(
                'Based on %d CPU cores and expected workload. ' .
                'Each connection uses memory, so adjust if you have many idle connections.',
                $cpuCores
            ),
        ];

        // innodb_log_file_size: 25% of buffer pool for OLTP, 10% for OLAP
        $logFilePercent = $workload === 'oltp' ? 0.25 : 0.10;
        $logFileBytes = (int)($bufferPoolBytes * $logFilePercent);
        // Round to nearest MB, minimum 48MB, maximum 2GB
        $logFileBytes = max(48 * 1024 * 1024, min(2 * 1024 * 1024 * 1024, $logFileBytes));
        $logFileBytes = (int)(round($logFileBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['innodb_log_file_size'] = [
            'value' => (string)$logFileBytes,
            'value_human' => $this->formatBytes($logFileBytes),
            'priority' => 'medium',
            'reason' => 'Larger log files improve write performance but increase recovery time.',
        ];

        // tmp_table_size and max_heap_table_size: based on memory
        $tmpTableSize = min(256 * 1024 * 1024, (int)($memoryBytes * 0.05));
        $tmpTableSize = (int)(round($tmpTableSize / (1024 * 1024)) * 1024 * 1024);
        $recommendations['tmp_table_size'] = [
            'value' => (string)$tmpTableSize,
            'value_human' => $this->formatBytes($tmpTableSize),
            'priority' => 'low',
            'reason' => 'Size of in-memory temporary tables. Increase if you have complex queries with GROUP BY.',
        ];
        $recommendations['max_heap_table_size'] = [
            'value' => (string)$tmpTableSize,
            'value_human' => $this->formatBytes($tmpTableSize),
            'priority' => 'low',
            'reason' => 'Should match tmp_table_size.',
        ];

        // thread_cache_size: based on max_connections
        $threadCache = min(100, (int)($maxConn * 0.1));
        $recommendations['thread_cache_size'] = [
            'value' => (string)$threadCache,
            'value_human' => (string)$threadCache,
            'priority' => 'low',
            'reason' => 'Reduces thread creation overhead. Recommended: 10% of max_connections.',
        ];

        // table_open_cache: based on number of tables (estimate)
        $tableCache = min(2000, max(400, $cpuCores * 50));
        $recommendations['table_open_cache'] = [
            'value' => (string)$tableCache,
            'value_human' => (string)$tableCache,
            'priority' => 'low',
            'reason' => 'Number of open tables. Increase if you have many tables.',
        ];

        // innodb_flush_log_at_trx_commit: based on workload and disk type
        if ($workload === 'oltp' && $diskType === 'ssd') {
            $recommendations['innodb_flush_log_at_trx_commit'] = [
                'value' => '2',
                'value_human' => '2',
                'priority' => 'medium',
                'reason' => 'For OLTP on SSD, value 2 provides good performance with acceptable durability. ' .
                    'Use 1 for maximum durability (slower writes).',
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate PostgreSQL recommendations.
     *
     * @param int $memoryBytes Memory in bytes
     * @param int $cpuCores CPU cores
     * @param string $workload Workload type
     * @param string $diskType Disk type
     * @param int|null $expectedConnections Expected connections
     * @param array<string, string> $currentVars Current variables
     *
     * @return array<string, array<string, mixed>> Recommendations
     */
    protected function calculatePostgreSQLRecommendations(
        int $memoryBytes,
        int $cpuCores,
        string $workload,
        string $diskType,
        ?int $expectedConnections,
        array $currentVars
    ): array {
        $memoryGB = $memoryBytes / (1024 * 1024 * 1024);
        $recommendations = [];

        // shared_buffers: 25% of memory for OLTP, 40% for OLAP
        $sharedBuffersPercent = $workload === 'oltp' ? 0.25 : 0.40;
        $sharedBuffersBytes = (int)($memoryBytes * $sharedBuffersPercent);
        // Round to nearest 8MB (PostgreSQL requirement)
        $sharedBuffersBytes = (int)(round($sharedBuffersBytes / (8 * 1024 * 1024)) * 8 * 1024 * 1024);
        $recommendations['shared_buffers'] = [
            'value' => (string)$sharedBuffersBytes,
            'value_human' => $this->formatBytes($sharedBuffersBytes),
            'priority' => 'high',
            'reason' => sprintf(
                'Shared buffers should be %d%% of available memory for %s workload. ' .
                'This is the most important setting for PostgreSQL performance.',
                (int)($sharedBuffersPercent * 100),
                strtoupper($workload)
            ),
        ];

        // effective_cache_size: 50-75% of memory
        $effectiveCachePercent = $workload === 'oltp' ? 0.50 : 0.75;
        $effectiveCacheBytes = (int)($memoryBytes * $effectiveCachePercent);
        $effectiveCacheBytes = (int)(round($effectiveCacheBytes / (8 * 1024 * 1024)) * 8 * 1024 * 1024);
        $recommendations['effective_cache_size'] = [
            'value' => (string)$effectiveCacheBytes,
            'value_human' => $this->formatBytes($effectiveCacheBytes),
            'priority' => 'high',
            'reason' => 'Tells planner how much memory is available for caching. ' .
                'Should include OS cache and shared_buffers.',
        ];

        // work_mem: based on memory, connections, and CPU
        $maxConn = $expectedConnections ?? max(100, $cpuCores * 5);
        // work_mem = (available_memory - shared_buffers) / (max_connections * 2)
        $workMemBytes = (int)(($memoryBytes - $sharedBuffersBytes) / ($maxConn * 2));
        // Minimum 4MB, maximum 256MB
        $workMemBytes = max(4 * 1024 * 1024, min(256 * 1024 * 1024, $workMemBytes));
        $workMemBytes = (int)(round($workMemBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['work_mem'] = [
            'value' => (string)$workMemBytes,
            'value_human' => $this->formatBytes($workMemBytes),
            'priority' => 'medium',
            'reason' => 'Memory used for sorting and hash operations. ' .
                'Each operation can use this amount, so total usage = work_mem * number of operations.',
        ];

        // maintenance_work_mem: larger for maintenance operations
        $maintenanceWorkMem = min(2 * 1024 * 1024 * 1024, (int)($memoryBytes * 0.10));
        $maintenanceWorkMem = (int)(round($maintenanceWorkMem / (1024 * 1024)) * 1024 * 1024);
        $recommendations['maintenance_work_mem'] = [
            'value' => (string)$maintenanceWorkMem,
            'value_human' => $this->formatBytes($maintenanceWorkMem),
            'priority' => 'low',
            'reason' => 'Memory for maintenance operations (VACUUM, CREATE INDEX, etc.).',
        ];

        // max_connections
        $maxConn = $expectedConnections ?? max(100, $cpuCores * 5);
        $maxConn = min($maxConn, 500);
        $recommendations['max_connections'] = [
            'value' => (string)$maxConn,
            'value_human' => (string)$maxConn,
            'priority' => 'medium',
            'reason' => 'Maximum number of concurrent connections. ' .
                'Each connection uses memory (work_mem, etc.).',
        ];

        // random_page_cost: based on disk type
        $randomPageCost = match ($diskType) {
            'nvme' => '1.1',
            'ssd' => '1.5',
            default => '4.0', // HDD
        };
        $recommendations['random_page_cost'] = [
            'value' => $randomPageCost,
            'value_human' => $randomPageCost,
            'priority' => 'medium',
            'reason' => sprintf(
                'Cost of random disk access. Lower values for %s encourage index usage.',
                strtoupper($diskType)
            ),
        ];

        // effective_io_concurrency: based on disk type
        $ioConcurrency = match ($diskType) {
            'nvme' => (string)min(200, $cpuCores * 2),
            'ssd' => (string)min(200, $cpuCores),
            default => '2', // HDD
        };
        $recommendations['effective_io_concurrency'] = [
            'value' => $ioConcurrency,
            'value_human' => $ioConcurrency,
            'priority' => 'low',
            'reason' => 'Number of concurrent I/O operations. Higher for SSD/NVMe.',
        ];

        return $recommendations;
    }

    /**
     * Calculate Oracle recommendations.
     *
     * @param int $memoryBytes Memory in bytes
     * @param int $cpuCores CPU cores
     * @param string $workload Workload type
     * @param string $diskType Disk type
     * @param int|null $expectedConnections Expected connections
     * @param array<string, string> $currentVars Current variables
     *
     * @return array<string, array<string, mixed>> Recommendations
     */
    protected function calculateOracleRecommendations(
        int $memoryBytes,
        int $cpuCores,
        string $workload,
        string $diskType,
        ?int $expectedConnections,
        array $currentVars
    ): array {
        $memoryGB = $memoryBytes / (1024 * 1024 * 1024);
        $recommendations = [];

        // SGA_TARGET: 60-70% of memory for OLTP, 50-60% for OLAP
        $sgaPercent = $workload === 'oltp' ? 0.65 : 0.55;
        $sgaBytes = (int)($memoryBytes * $sgaPercent);
        // Round to nearest MB
        $sgaBytes = (int)(round($sgaBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['sga_target'] = [
            'value' => (string)$sgaBytes,
            'value_human' => $this->formatBytes($sgaBytes),
            'priority' => 'high',
            'reason' => sprintf(
                'SGA (System Global Area) should be %d%% of available memory for %s workload.',
                (int)($sgaPercent * 100),
                strtoupper($workload)
            ),
        ];

        // PGA_AGGREGATE_TARGET: 20% of memory for OLTP, 30% for OLAP
        $pgaPercent = $workload === 'oltp' ? 0.20 : 0.30;
        $pgaBytes = (int)($memoryBytes * $pgaPercent);
        $pgaBytes = (int)(round($pgaBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['pga_aggregate_target'] = [
            'value' => (string)$pgaBytes,
            'value_human' => $this->formatBytes($pgaBytes),
            'priority' => 'high',
            'reason' => 'PGA (Program Global Area) for sorting and hash joins. ' .
                'Higher for OLAP workloads with complex queries.',
        ];

        // DB_CACHE_SIZE: 70% of SGA for OLTP, 50% for OLAP
        $dbCachePercent = $workload === 'oltp' ? 0.70 : 0.50;
        $dbCacheBytes = (int)($sgaBytes * $dbCachePercent);
        $dbCacheBytes = (int)(round($dbCacheBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['db_cache_size'] = [
            'value' => (string)$dbCacheBytes,
            'value_human' => $this->formatBytes($dbCacheBytes),
            'priority' => 'high',
            'reason' => 'Database buffer cache. Most important component of SGA for OLTP.',
        ];

        // SHARED_POOL_SIZE: 15% of SGA
        $sharedPoolBytes = (int)($sgaBytes * 0.15);
        $sharedPoolBytes = (int)(round($sharedPoolBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['shared_pool_size'] = [
            'value' => (string)$sharedPoolBytes,
            'value_human' => $this->formatBytes($sharedPoolBytes),
            'priority' => 'medium',
            'reason' => 'Shared pool for SQL and PL/SQL parsing. Increase if you have many concurrent users.',
        ];

        // PROCESSES and SESSIONS: based on expected connections
        $processes = $expectedConnections ?? max(150, $cpuCores * 10);
        $processes = min($processes, 1000);
        $sessions = (int)($processes * 1.1); // Sessions = 1.1 * processes
        $recommendations['processes'] = [
            'value' => (string)$processes,
            'value_human' => (string)$processes,
            'priority' => 'medium',
            'reason' => 'Maximum number of operating system processes.',
        ];
        $recommendations['sessions'] = [
            'value' => (string)$sessions,
            'value_human' => (string)$sessions,
            'priority' => 'medium',
            'reason' => 'Maximum number of sessions. Should be >= processes.',
        ];

        // PARALLEL_MAX_SERVERS: based on CPU cores
        $parallelServers = min(32, $cpuCores * 2);
        $recommendations['parallel_max_servers'] = [
            'value' => (string)$parallelServers,
            'value_human' => (string)$parallelServers,
            'priority' => 'low',
            'reason' => 'Maximum parallel execution servers. Useful for OLAP workloads.',
        ];

        return $recommendations;
    }

    /**
     * Calculate MSSQL recommendations.
     *
     * @param int $memoryBytes Memory in bytes
     * @param int $cpuCores CPU cores
     * @param string $workload Workload type
     * @param string $diskType Disk type
     * @param int|null $expectedConnections Expected connections
     * @param array<string, string> $currentVars Current variables
     *
     * @return array<string, array<string, mixed>> Recommendations
     */
    protected function calculateMSSQLRecommendations(
        int $memoryBytes,
        int $cpuCores,
        string $workload,
        string $diskType,
        ?int $expectedConnections,
        array $currentVars
    ): array {
        $memoryGB = $memoryBytes / (1024 * 1024 * 1024);
        $recommendations = [];

        // max server memory: 80-90% of available memory
        $maxMemoryPercent = $workload === 'oltp' ? 0.85 : 0.90;
        $maxMemoryBytes = (int)($memoryBytes * $maxMemoryPercent);
        $maxMemoryMB = (int)($maxMemoryBytes / (1024 * 1024));
        $recommendations['max server memory'] = [
            'value' => (string)$maxMemoryMB,
            'value_human' => $maxMemoryMB . ' MB',
            'priority' => 'high',
            'reason' => sprintf(
                'Maximum memory SQL Server can use. Set to %d%% of available memory, ' .
                'leaving room for OS and other applications.',
                (int)($maxMemoryPercent * 100)
            ),
        ];

        // min server memory: 10% of max
        $minMemoryMB = (int)($maxMemoryMB * 0.10);
        $recommendations['min server memory'] = [
            'value' => (string)$minMemoryMB,
            'value_human' => $minMemoryMB . ' MB',
            'priority' => 'low',
            'reason' => 'Minimum memory SQL Server will use. Prevents memory pressure from other applications.',
        ];

        // max degree of parallelism (MAXDOP): based on CPU cores
        $maxdop = min(8, max(1, (int)($cpuCores / 2)));
        $recommendations['max degree of parallelism'] = [
            'value' => (string)$maxdop,
            'value_human' => (string)$maxdop,
            'priority' => 'medium',
            'reason' => sprintf(
                'Maximum parallel threads per query. Recommended: %d for %d CPU cores. ' .
                'Lower values reduce contention but may slow complex queries.',
                $maxdop,
                $cpuCores
            ),
        ];

        // cost threshold for parallelism: based on workload
        $costThreshold = $workload === 'oltp' ? '50' : '25';
        $recommendations['cost threshold for parallelism'] = [
            'value' => $costThreshold,
            'value_human' => $costThreshold,
            'priority' => 'low',
            'reason' => 'Query cost threshold for parallel execution. ' .
                'Lower values enable parallelism for more queries (good for OLAP).',
        ];

        return $recommendations;
    }

    /**
     * Calculate SQLite recommendations.
     *
     * @param int $memoryBytes Memory in bytes
     * @param int $cpuCores CPU cores
     * @param string $workload Workload type
     * @param string $diskType Disk type
     * @param int|null $expectedConnections Expected connections
     * @param array<string, string> $currentVars Current variables
     *
     * @return array<string, array<string, mixed>> Recommendations
     */
    protected function calculateSQLiteRecommendations(
        int $memoryBytes,
        int $cpuCores,
        string $workload,
        string $diskType,
        ?int $expectedConnections,
        array $currentVars
    ): array {
        $recommendations = [];

        // PRAGMA cache_size: based on memory (in pages, default page size 4KB)
        $pageSize = 4096; // Default SQLite page size
        $cacheSizeBytes = min(256 * 1024 * 1024, (int)($memoryBytes * 0.10));
        $cachePages = (int)($cacheSizeBytes / $pageSize);
        $recommendations['cache_size'] = [
            'value' => (string)$cachePages,
            'value_human' => $this->formatBytes($cachePages * $pageSize) . ' (' . $cachePages . ' pages)',
            'priority' => 'medium',
            'reason' => 'Number of database pages to cache in memory. ' .
                'Higher values improve read performance but use more memory.',
        ];

        // PRAGMA page_size: based on workload
        $pageSize = $workload === 'oltp' ? 4096 : 8192;
        $recommendations['page_size'] = [
            'value' => (string)$pageSize,
            'value_human' => (string)$pageSize . ' bytes',
            'priority' => 'low',
            'reason' => 'Database page size. Larger pages improve sequential read performance (OLAP). ' .
                'Note: Can only be set when creating database.',
        ];

        // PRAGMA journal_mode: based on disk type and workload
        $journalMode = match (true) {
            $diskType === 'ssd' || $diskType === 'nvme' => 'WAL',
            $workload === 'oltp' => 'WAL',
            default => 'DELETE',
        };
        $recommendations['journal_mode'] = [
            'value' => $journalMode,
            'value_human' => $journalMode,
            'priority' => 'medium',
            'reason' => sprintf(
                'WAL (Write-Ahead Logging) provides better concurrency for %s workloads. ' .
                'DELETE is simpler but slower for concurrent writes.',
                strtoupper($workload)
            ),
        ];

        // PRAGMA synchronous: based on workload
        $synchronous = $workload === 'oltp' ? 'FULL' : 'NORMAL';
        $recommendations['synchronous'] = [
            'value' => $synchronous,
            'value_human' => $synchronous,
            'priority' => 'medium',
            'reason' => 'FULL provides maximum durability (slower writes). ' .
                'NORMAL is faster but less safe in case of power loss.',
        ];

        return $recommendations;
    }

    /**
     * Compare current settings with recommendations.
     *
     * @param array<string, string> $current Current variables
     * @param array<string, array<string, mixed>> $recommendations Recommendations
     *
     * @return array<int, array<string, mixed>> Comparison results
     */
    protected function compareSettings(array $current, array $recommendations): array
    {
        $comparison = [];

        foreach ($recommendations as $name => $rec) {
            $currentValue = $current[$name] ?? null;
            $recommendedValue = $rec['value'] ?? '';

            // Normalize values for comparison
            $currentNormalized = $this->normalizeValue($currentValue);
            $recommendedNormalized = $this->normalizeValue($recommendedValue);

            $needsChange = $currentNormalized !== $recommendedNormalized;

            $comparison[] = [
                'name' => $name,
                'current' => $currentValue ?? 'not set',
                'current_human' => $currentValue !== null ? $this->formatValue($currentValue) : 'not set',
                'recommended' => $recommendedValue,
                'recommended_human' => $rec['value_human'] ?? $recommendedValue,
                'priority' => $rec['priority'] ?? 'low',
                'reason' => $rec['reason'] ?? '',
                'needs_change' => $needsChange,
            ];
        }

        // Sort by priority (high -> medium -> low) and then by name
        usort($comparison, function ($a, $b) {
            $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            $aPriority = $priorityOrder[$a['priority']] ?? 3;
            $bPriority = $priorityOrder[$b['priority']] ?? 3;
            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $comparison;
    }

    /**
     * Normalize value for comparison.
     *
     * @param string|null $value Value to normalize
     *
     * @return string Normalized value
     */
    protected function normalizeValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Convert to lowercase and remove whitespace
        $value = strtolower(trim($value));

        // Try to parse as number (bytes)
        if (preg_match('/^(\d+)$/', $value, $matches)) {
            return $matches[1];
        }

        // Try to parse with units (e.g., "1G", "512M")
        if (preg_match('/^(\d+)([kmgt]?)$/i', $value, $matches)) {
            $num = (int)$matches[1];
            $unit = $matches[2] !== '' ? strtolower($matches[2]) : '';
            $multiplier = match ($unit) {
                'k' => 1024,
                'm' => 1024 * 1024,
                'g' => 1024 * 1024 * 1024,
                't' => 1024 * 1024 * 1024 * 1024,
                default => 1,
            };
            return (string)($num * $multiplier);
        }

        return $value;
    }

    /**
     * Format value for display.
     *
     * @param string $value Value to format
     *
     * @return string Formatted value
     */
    protected function formatValue(string $value): string
    {
        // If it's a number, try to format as bytes
        if (preg_match('/^\d+$/', $value)) {
            $bytes = (int)$value;
            if ($bytes > 1024) {
                return $this->formatBytes($bytes);
            }
            return $value;
        }

        return $value;
    }

    /**
     * Format bytes to human-readable format.
     *
     * @param int $bytes Bytes to format
     *
     * @return string Formatted string
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Generate summary statistics.
     *
     * @param array<int, array<string, mixed>> $comparison Comparison results
     *
     * @return array<string, mixed> Summary
     */
    protected function generateSummary(array $comparison): array
    {
        $summary = [
            'total_settings' => count($comparison),
            'needs_change' => 0,
            'high_priority' => 0,
            'medium_priority' => 0,
            'low_priority' => 0,
        ];

        foreach ($comparison as $item) {
            if ($item['needs_change'] ?? false) {
                $summary['needs_change']++;
            }
            $priority = $item['priority'] ?? 'low';
            if ($priority === 'high') {
                $summary['high_priority']++;
            } elseif ($priority === 'medium') {
                $summary['medium_priority']++;
            } else {
                $summary['low_priority']++;
            }
        }

        return $summary;
    }

    /**
     * Generate SQL commands to apply recommendations.
     *
     * @param array<string, array<string, mixed>> $recommendations Recommendations
     *
     * @return array<int, string> SQL commands
     */
    protected function generateSqlCommands(array $recommendations): array
    {
        $commands = [];

        foreach ($recommendations as $name => $rec) {
            $value = $rec['value'] ?? '';
            $command = $this->generateSqlCommand($name, $value);
            if ($command !== null) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /**
     * Generate SQL command for a setting.
     *
     * @param string $name Setting name
     * @param string $value Setting value
     *
     * @return string|null SQL command or null if not applicable
     */
    protected function generateSqlCommand(string $name, string $value): ?string
    {
        return match ($this->dialect) {
            'mysql', 'mariadb' => $this->generateMySQLCommand($name, $value),
            'pgsql' => $this->generatePostgreSQLCommand($name, $value),
            'oci' => $this->generateOracleCommand($name, $value),
            'sqlsrv' => $this->generateMSSQLCommand($name, $value),
            'sqlite' => $this->generateSQLiteCommand($name, $value),
            default => null,
        };
    }

    /**
     * Generate MySQL/MariaDB command.
     *
     * @param string $name Setting name
     * @param string $value Setting value
     *
     * @return string|null SQL command
     */
    protected function generateMySQLCommand(string $name, string $value): ?string
    {
        // Convert bytes to appropriate unit for MySQL
        $bytes = (int)$value;
        if ($bytes > 0) {
            if ($bytes >= 1024 * 1024 * 1024) {
                $value = (string)((int)($bytes / (1024 * 1024 * 1024))) . 'G';
            } elseif ($bytes >= 1024 * 1024) {
                $value = (string)((int)($bytes / (1024 * 1024))) . 'M';
            } elseif ($bytes >= 1024) {
                $value = (string)((int)($bytes / 1024)) . 'K';
            }
        }

        return "SET GLOBAL {$name} = {$value};";
    }

    /**
     * Generate PostgreSQL command.
     *
     * @param string $name Setting name
     * @param string $value Setting value
     *
     * @return string|null SQL command
     */
    protected function generatePostgreSQLCommand(string $name, string $value): ?string
    {
        // PostgreSQL uses bytes or specific units
        $bytes = (int)$value;
        if ($bytes > 0) {
            // Convert to appropriate unit
            if ($bytes >= 1024 * 1024 * 1024) {
                $value = (string)((int)($bytes / (1024 * 1024 * 1024))) . 'GB';
            } elseif ($bytes >= 1024 * 1024) {
                $value = (string)((int)($bytes / (1024 * 1024))) . 'MB';
            } elseif ($bytes >= 1024) {
                $value = (string)((int)($bytes / 1024)) . 'KB';
            } else {
                $value = (string)$bytes . 'B';
            }
        }

        return "ALTER SYSTEM SET {$name} = '{$value}';";
    }

    /**
     * Generate Oracle command.
     *
     * @param string $name Setting name
     * @param string $value Setting value
     *
     * @return string|null SQL command
     */
    protected function generateOracleCommand(string $name, string $value): ?string
    {
        $bytes = (int)$value;
        if ($bytes > 0) {
            // Oracle uses M or G suffix
            if ($bytes >= 1024 * 1024 * 1024) {
                $value = (string)((int)($bytes / (1024 * 1024 * 1024))) . 'G';
            } else {
                $value = (string)((int)($bytes / (1024 * 1024))) . 'M';
            }
        }

        // Oracle uses ALTER SYSTEM for most parameters
        return "ALTER SYSTEM SET {$name} = {$value} SCOPE = SPFILE;";
    }

    /**
     * Generate MSSQL command.
     *
     * @param string $name Setting name
     * @param string $value Setting value
     *
     * @return string|null SQL command
     */
    protected function generateMSSQLCommand(string $name, string $value): ?string
    {
        // MSSQL uses sp_configure
        // Extract numeric value (remove " MB" suffix if present)
        $numericValue = preg_replace('/\s*MB\s*$/i', '', $value);
        $numericValue = (int)$numericValue;

        return "EXEC sp_configure '{$name}', {$numericValue}; RECONFIGURE;";
    }

    /**
     * Generate SQLite command.
     *
     * @param string $name Setting name
     * @param string $value Setting value
     *
     * @return string|null SQL command
     */
    protected function generateSQLiteCommand(string $name, string $value): ?string
    {
        // SQLite uses PRAGMA
        return "PRAGMA {$name} = {$value};";
    }
}
