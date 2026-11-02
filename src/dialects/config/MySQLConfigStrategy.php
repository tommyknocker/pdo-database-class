<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\config;

/**
 * Configuration recommendations for MySQL and MariaDB.
 */
final class MySQLConfigStrategy implements ConfigOptimizerStrategy
{
    /** Settings that are an amount of memory in bytes; every other setting is a plain number and is written as is */
    private const MEMORY_SETTINGS = ['innodb_buffer_pool_size', 'innodb_log_file_size', 'tmp_table_size', 'max_heap_table_size'];

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

        // innodb_buffer_pool_size: 70-80% of available memory for OLTP, 50-60% for OLAP
        $bufferPoolPercent = $workload === 'oltp' ? 0.75 : 0.55;
        $bufferPoolBytes = (int)($memoryBytes * $bufferPoolPercent);
        // Round to nearest MB
        $bufferPoolBytes = (int)(round($bufferPoolBytes / (1024 * 1024)) * 1024 * 1024);
        $recommendations['innodb_buffer_pool_size'] = [
            'value' => (string)$bufferPoolBytes,
            'value_human' => ConfigValue::formatBytes($bufferPoolBytes),
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
            'value_human' => ConfigValue::formatBytes($logFileBytes),
            'priority' => 'medium',
            'reason' => 'Larger log files improve write performance but increase recovery time.',
        ];

        // tmp_table_size and max_heap_table_size: based on memory
        $tmpTableSize = min(256 * 1024 * 1024, (int)($memoryBytes * 0.05));
        $tmpTableSize = (int)(round($tmpTableSize / (1024 * 1024)) * 1024 * 1024);
        $recommendations['tmp_table_size'] = [
            'value' => (string)$tmpTableSize,
            'value_human' => ConfigValue::formatBytes($tmpTableSize),
            'priority' => 'low',
            'reason' => 'Size of in-memory temporary tables. Increase if you have complex queries with GROUP BY.',
        ];
        $recommendations['max_heap_table_size'] = [
            'value' => (string)$tmpTableSize,
            'value_human' => ConfigValue::formatBytes($tmpTableSize),
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
     * {@inheritDoc}
     */
    public function command(string $name, string $value): string
    {
        // Only memory amounts carry a unit; max_connections, thread_cache_size and table_open_cache are counts
        if (in_array($name, self::MEMORY_SETTINGS, true) && (int)$value > 0) {
            $bytes = (int)$value;
            if ($bytes >= 1024 * 1024 * 1024) {
                $value = (int)($bytes / (1024 * 1024 * 1024)) . 'G';
            } elseif ($bytes >= 1024 * 1024) {
                $value = (int)($bytes / (1024 * 1024)) . 'M';
            } elseif ($bytes >= 1024) {
                $value = (int)($bytes / 1024) . 'K';
            }
        }

        return "SET GLOBAL {$name} = {$value};";
    }
}
