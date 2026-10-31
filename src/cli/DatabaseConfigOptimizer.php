<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\dialects\config\DatabaseConfigOptimizerEngine;
use tommyknocker\pdodb\PdoDb;

/**
 * Database configuration optimizer (CLI facade).
 */
class DatabaseConfigOptimizer
{
    protected DatabaseConfigOptimizerEngine $engine;

    public function __construct(PdoDb $db)
    {
        $this->engine = new DatabaseConfigOptimizerEngine($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(
        int $memoryBytes,
        int $cpuCores,
        string $workload = 'oltp',
        string $diskType = 'ssd',
        ?int $expectedConnections = null
    ): array {
        return $this->engine->analyze($memoryBytes, $cpuCores, $workload, $diskType, $expectedConnections);
    }
}
