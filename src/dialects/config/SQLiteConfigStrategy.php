<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\config;

/**
 * Configuration recommendations for SQLite.
 */
final class SQLiteConfigStrategy implements ConfigOptimizerStrategy
{
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
        $recommendations = [];

        // PRAGMA cache_size: based on memory (in pages, default page size 4KB)
        $pageSize = 4096; // Default SQLite page size
        $cacheSizeBytes = min(256 * 1024 * 1024, (int)($memoryBytes * 0.10));
        $cachePages = (int)($cacheSizeBytes / $pageSize);
        $recommendations['cache_size'] = [
            'value' => (string)$cachePages,
            'value_human' => ConfigValue::formatBytes($cachePages * $pageSize) . ' (' . $cachePages . ' pages)',
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
     * {@inheritDoc}
     */
    public function command(string $name, string $value): string
    {
        // SQLite uses PRAGMA
        return "PRAGMA {$name} = {$value};";
    }
}
