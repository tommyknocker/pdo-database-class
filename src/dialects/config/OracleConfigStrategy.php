<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\config;

/**
 * Configuration recommendations for Oracle.
 */
final class OracleConfigStrategy implements ConfigOptimizerStrategy
{
    /** Settings that are an amount of memory in bytes; every other setting is a plain number and is written as is */
    private const MEMORY_SETTINGS = ['sga_target', 'pga_aggregate_target', 'db_cache_size', 'shared_pool_size'];

    /**
     * {@inheritDoc}
     */
    public function recommend(
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
            'value_human' => ConfigValue::formatBytes($sgaBytes),
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
            'value_human' => ConfigValue::formatBytes($pgaBytes),
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
            'value_human' => ConfigValue::formatBytes($dbCacheBytes),
            'priority' => 'high',
            'reason' => 'Database buffer cache. Most important component of SGA for OLTP.',
        ];

        // SHARED_POOL_SIZE: 15% of SGA
        $sharedPoolBytes = (int)($sgaBytes * 0.15);
        $sharedPoolBytes = (int)(round($sharedPoolBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['shared_pool_size'] = [
            'value' => (string)$sharedPoolBytes,
            'value_human' => ConfigValue::formatBytes($sharedPoolBytes),
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
     * {@inheritDoc}
     */
    public function command(string $name, string $value): string
    {
        // Only memory amounts carry a unit; processes, sessions and parallel_max_servers are counts
        if (in_array($name, self::MEMORY_SETTINGS, true) && (int)$value > 0) {
            $bytes = (int)$value;
            $value = $bytes >= 1024 * 1024 * 1024
                ? (int)($bytes / (1024 * 1024 * 1024)) . 'G'
                : (int)($bytes / (1024 * 1024)) . 'M';
        }

        // Oracle uses ALTER SYSTEM for most parameters
        return "ALTER SYSTEM SET {$name} = {$value} SCOPE = SPFILE;";
    }
}
