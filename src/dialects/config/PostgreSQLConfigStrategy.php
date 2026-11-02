<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\config;

/**
 * Configuration recommendations for PostgreSQL.
 */
final class PostgreSQLConfigStrategy implements ConfigOptimizerStrategy
{
    /** Settings that are an amount of memory in bytes; every other setting is a plain number and is written as is */
    private const MEMORY_SETTINGS = ['shared_buffers', 'effective_cache_size', 'work_mem', 'maintenance_work_mem'];

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

        // shared_buffers: 25% of memory for OLTP, 40% for OLAP
        $sharedBuffersPercent = $workload === 'oltp' ? 0.25 : 0.40;
        $sharedBuffersBytes = (int)($memoryBytes * $sharedBuffersPercent);
        // Round to nearest 8MB (PostgreSQL requirement)
        $sharedBuffersBytes = (int)(round($sharedBuffersBytes / (8 * 1024 * 1024)) * 8 * 1024 * 1024);
        $recommendations['shared_buffers'] = [
            'value' => (string)$sharedBuffersBytes,
            'value_human' => ConfigValue::formatBytes($sharedBuffersBytes),
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
            'value_human' => ConfigValue::formatBytes($effectiveCacheBytes),
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
            'value_human' => ConfigValue::formatBytes($workMemBytes),
            'priority' => 'medium',
            'reason' => 'Memory used for sorting and hash operations. ' .
                'Each operation can use this amount, so total usage = work_mem * number of operations.',
        ];

        // maintenance_work_mem: larger for maintenance operations
        $maintenanceWorkMem = min(2 * 1024 * 1024 * 1024, (int)($memoryBytes * 0.10));
        $maintenanceWorkMem = (int)(round($maintenanceWorkMem / (1024 * 1024)) * 1024 * 1024);
        $recommendations['maintenance_work_mem'] = [
            'value' => (string)$maintenanceWorkMem,
            'value_human' => ConfigValue::formatBytes($maintenanceWorkMem),
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
     * {@inheritDoc}
     */
    public function command(string $name, string $value): string
    {
        // Only memory amounts carry a unit; max_connections, random_page_cost (1.5) and effective_io_concurrency are not bytes
        if (in_array($name, self::MEMORY_SETTINGS, true) && (int)$value > 0) {
            $bytes = (int)$value;
            if ($bytes >= 1024 * 1024 * 1024) {
                $value = (int)($bytes / (1024 * 1024 * 1024)) . 'GB';
            } elseif ($bytes >= 1024 * 1024) {
                $value = (int)($bytes / (1024 * 1024)) . 'MB';
            } elseif ($bytes >= 1024) {
                $value = (int)($bytes / 1024) . 'kB';
            } else {
                $value = $bytes . 'B';
            }
        }

        return "ALTER SYSTEM SET {$name} = '" . str_replace("'", "''", $value) . "';";
    }
}
