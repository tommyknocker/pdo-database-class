<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\config;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\PdoDb;

/**
 * Dialect-specific database server configuration recommendations.
 */
class DatabaseConfigOptimizerEngine
{
    protected PdoDb $db;

    protected DialectInterface $dialect;

    /** Recommendations of the connected database; null when its dialect has none (the report is then empty) */
    protected ?ConfigOptimizerStrategy $strategy;

    public function __construct(PdoDb $db, ?ConfigOptimizerStrategy $strategy = null)
    {
        $this->db = $db;
        $this->dialect = $db->connection->getDialect();
        $this->strategy = $strategy ?? $this->dialect->configOptimizerStrategy();
    }

    protected function driverName(): string
    {
        return $this->dialect->getDriverName();
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
        $recommendations = $this->strategy?->recommend(
            $memoryBytes,
            $cpuCores,
            $workload,
            $diskType,
            $expectedConnections,
            $currentVars
        ) ?? [];

        // Compare current vs recommended
        $comparison = $this->compareSettings($currentVars, $recommendations);

        return [
            'dialect' => $this->driverName(),
            'resources' => [
                'memory_bytes' => $memoryBytes,
                'memory_human' => ConfigValue::formatBytes($memoryBytes),
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
            $currentNormalized = ConfigValue::normalize($currentValue);
            $recommendedNormalized = ConfigValue::normalize($recommendedValue);

            $needsChange = $currentNormalized !== $recommendedNormalized;

            $comparison[] = [
                'name' => $name,
                'current' => $currentValue ?? 'not set',
                'current_human' => $currentValue !== null ? ConfigValue::format($currentValue) : 'not set',
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
            $command = $this->strategy?->command($name, $value);
            if ($command !== null) {
                $commands[] = $command;
            }
        }

        return $commands;
    }
}
