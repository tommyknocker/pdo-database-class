<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\dialects\config\ConfigOptimizerStrategy;
use tommyknocker\pdodb\dialects\config\DatabaseConfigOptimizerEngine;

/**
 * The optimizer engine knows no database by name: recommendations and commands come from a strategy, which the
 * connected dialect supplies.
 */
final class ConfigOptimizerStrategyTests extends BaseSharedTestCase
{
    public function testEveryBuiltInDialectSuppliesAStrategy(): void
    {
        $this->assertInstanceOf(ConfigOptimizerStrategy::class, self::$db->schema()->getDialect()->configOptimizerStrategy());
    }

    public function testTheEngineUsesTheStrategyItIsGiven(): void
    {
        $strategy = new class () implements ConfigOptimizerStrategy {
            /** @var list<array<int|string|null>> */
            public array $calls = [];

            public function recommend(int $memoryBytes, int $cpuCores, string $workload, string $diskType, ?int $expectedConnections, array $currentVars): array
            {
                $this->calls[] = [$memoryBytes, $cpuCores, $workload, $diskType, $expectedConnections];

                return [
                    'pool_size' => ['value' => '512M', 'value_human' => '512 MB', 'priority' => 'high', 'reason' => 'test'],
                    'log_level' => ['value' => 'warn', 'value_human' => 'warn', 'priority' => 'low', 'reason' => 'test'],
                ];
            }

            public function command(string $name, string $value): ?string
            {
                return $name === 'pool_size' ? "SET {$name} = {$value}" : null;
            }
        };

        $result = (new DatabaseConfigOptimizerEngine(self::$db, $strategy))->analyze(2 * 1024 * 1024 * 1024, 4, 'olap', 'nvme', 50);

        $this->assertSame([[2 * 1024 * 1024 * 1024, 4, 'olap', 'nvme', 50]], $strategy->calls);
        $this->assertSame(['SET pool_size = 512M'], $result['sql_commands'], 'a null command is left out');
        $this->assertSame(['pool_size', 'log_level'], array_column($result['comparison'], 'name'), 'high priority first');
        $this->assertSame(2, $result['summary']['total_settings']);
        $this->assertSame(1, $result['summary']['high_priority']);
        $this->assertSame('2 GB', $result['resources']['memory_human']);
    }

    public function testADialectWithoutRecommendationsGivesAnEmptyReport(): void
    {
        $strategy = new class () implements ConfigOptimizerStrategy {
            public function recommend(int $memoryBytes, int $cpuCores, string $workload, string $diskType, ?int $expectedConnections, array $currentVars): array
            {
                return [];
            }

            public function command(string $name, string $value): ?string
            {
                return null;
            }
        };

        $result = (new DatabaseConfigOptimizerEngine(self::$db, $strategy))->analyze(1024 * 1024 * 1024, 1);

        $this->assertSame([], $result['recommendations']);
        $this->assertSame([], $result['sql_commands']);
        $this->assertSame(0, $result['summary']['total_settings']);
    }
}
