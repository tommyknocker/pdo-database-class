<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\config;

/**
 * What a database server's configuration should look like for a machine and a workload, and how to apply it.
 *
 * Every dialect that has tunable server settings supplies one through `DialectInterface::configOptimizerStrategy()`;
 * {@see DatabaseConfigOptimizerEngine} compares its recommendations with the server's current values and does not know
 * any database by name.
 */
interface ConfigOptimizerStrategy
{
    /**
     * Recommended settings.
     *
     * @param int $memoryBytes Memory available to the server in bytes
     * @param int $cpuCores CPU cores
     * @param string $workload Workload type (oltp|olap|mixed)
     * @param string $diskType Disk type (ssd|hdd|nvme)
     * @param int|null $expectedConnections Expected number of connections
     * @param array<string, string> $currentVars Current server variables
     *
     * @return array<string, array<string, mixed>> Setting name => value, human value, priority and reason
     */
    public function recommend(
        int $memoryBytes,
        int $cpuCores,
        string $workload,
        string $diskType,
        ?int $expectedConnections,
        array $currentVars
    ): array;

    /**
     * Statement that applies a setting, or null when it cannot be applied with SQL.
     *
     * @param string $name Setting name
     * @param string $value Setting value as recommended
     */
    public function command(string $name, string $value): ?string;
}
