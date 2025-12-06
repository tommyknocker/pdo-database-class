<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use ReflectionClass;
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
        if ($result !== null) {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('aggregated', $result);
            $this->assertArrayHasKey('by_query', $result);
        }
    }

    public function testMonitorManagerToString(): void
    {
        $reflection = new ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('toString');
        $method->setAccessible(true);

        $this->assertEquals('test', $method->invoke(null, 'test'));
        $this->assertEquals('123', $method->invoke(null, 123));
        $this->assertEquals('45.67', $method->invoke(null, 45.67));
        $this->assertEquals('', $method->invoke(null, null));
        $this->assertEquals('', $method->invoke(null, []));
    }

    public function testMonitorManagerToFloat(): void
    {
        $reflection = new ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('toFloat');
        $method->setAccessible(true);

        $this->assertEquals(1.5, $method->invoke(null, 1.5));
        $this->assertEquals(123.0, $method->invoke(null, 123));
        $this->assertEquals(45.67, $method->invoke(null, '45.67'));
        $this->assertEquals(0.0, $method->invoke(null, null));
        $this->assertEquals(0.0, $method->invoke(null, []));
    }

    public function testMonitorManagerGetActiveQueriesSQLite(): void
    {
        $reflection = new ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('getActiveQueriesSQLite');
        $method->setAccessible(true);

        $result = $method->invoke(null, self::$db);
        $this->assertIsArray($result);
        // SQLite doesn't have built-in query monitoring, should return empty array
        $this->assertEmpty($result);
    }

    public function testMonitorManagerGetActiveConnectionsSQLite(): void
    {
        $reflection = new ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('getActiveConnectionsSQLite');
        $method->setAccessible(true);

        $result = $method->invoke(null, self::$db);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('connections', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertIsArray($result['connections']);
        $this->assertIsArray($result['summary']);
    }

    public function testMonitorManagerGetSlowQueriesSQLite(): void
    {
        // Enable profiling for SQLite slow queries
        self::$db->enableProfiling();
        self::$db->rawQuery('SELECT 1');

        $reflection = new ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('getSlowQueriesSQLite');
        $method->setAccessible(true);

        $result = $method->invoke(null, self::$db, 0.001, 10);
        $this->assertIsArray($result);
    }

    public function testMonitorManagerGetSlowQueriesSQLiteWithoutProfiling(): void
    {
        // Disable profiling
        self::$db->disableProfiling();

        $reflection = new ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('getSlowQueriesSQLite');
        $method->setAccessible(true);

        $result = $method->invoke(null, self::$db, 0.001, 10);
        $this->assertIsArray($result);
        // Without profiling, should return empty array
        $this->assertEmpty($result);
    }
}
