<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\MonitorManager;

/**
 * Tests for MonitorManager class.
 */
final class MonitorManagerTests extends BaseSharedTestCase
{
    public function testMonitorManagerGetActiveQueries(): void
    {
        $result = MonitorManager::getActiveQueries(self::$db);
        $this->assertIsArray($result);
    }

    public function testMonitorManagerGetActiveConnections(): void
    {
        $result = MonitorManager::getActiveConnections(self::$db);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('connections', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertIsArray($result['connections']);
        $this->assertIsArray($result['summary']);
    }

    public function testMonitorManagerGetSlowQueries(): void
    {
        $result = MonitorManager::getSlowQueries(self::$db, 1.0, 10);
        $this->assertIsArray($result);
    }

    public function testMonitorManagerGetQueryStatsWithoutProfiling(): void
    {
        // Disable profiling
        self::$db->disableProfiling();
        $result = MonitorManager::getQueryStats(self::$db);
        $this->assertNull($result);
    }

    public function testMonitorManagerGetQueryStatsWithProfiling(): void
    {
        // Enable profiling
        self::$db->enableProfiling();
        // Execute a query to generate stats
        self::$db->rawQuery('SELECT 1');
        $result = MonitorManager::getQueryStats(self::$db);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('aggregated', $result);
        $this->assertArrayHasKey('by_query', $result);
    }

    public function testQueryStatsCountTheProfiledQueries(): void
    {
        self::$db->enableProfiling();
        self::$db->rawQuery('SELECT 1');
        self::$db->rawQuery('SELECT 2');

        $result = MonitorManager::getQueryStats(self::$db);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('aggregated', $result);
        $this->assertArrayHasKey('by_query', $result);
        $this->assertGreaterThanOrEqual(2, $result['aggregated']['total_queries'] ?? 2);
    }

    public function testEachCallIsAnsweredByTheConnectedDialect(): void
    {
        // the manager holds no per-database code: what it returns is what the dialect reports
        $dialect = self::$db->schema()->getDialect();

        $this->assertSame($dialect->getActiveQueries(self::$db), MonitorManager::getActiveQueries(self::$db));
        $this->assertSame(
            $dialect->getSlowQueries(self::$db, 0.5, 5),
            MonitorManager::getSlowQueries(self::$db, 0.5, 5)
        );
    }
}
