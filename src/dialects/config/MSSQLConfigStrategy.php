<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\config;

/**
 * Configuration recommendations for SQL Server.
 */
final class MSSQLConfigStrategy implements ConfigOptimizerStrategy
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
     * {@inheritDoc}
     */
    public function command(string $name, string $value): string
    {
        // MSSQL uses sp_configure
        // Extract numeric value (remove " MB" suffix if present)
        $numericValue = preg_replace('/\s*MB\s*$/i', '', $value);
        $numericValue = (int)$numericValue;

        return "EXEC sp_configure '{$name}', {$numericValue}; RECONFIGURE;";
    }
}
