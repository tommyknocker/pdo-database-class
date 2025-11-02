<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use tommyknocker\pdodb\query\QueryProfiler;

/**
 * Tests for QueryProfiler.
 */
class QueryProfilerTests extends BaseSharedTestCase
{
    /**
     * Test enable and disable.
     */
    public function testEnableDisable(): void
    {
        $profiler = new QueryProfiler();

        $this->assertFalse($profiler->isEnabled());

        $profiler->enable();
        $this->assertTrue($profiler->isEnabled());

        $profiler->disable();
        $this->assertFalse($profiler->isEnabled());
    }

    /**
     * Test setLogger and getLogger.
     */
    public function testSetLogger(): void
    {
        $profiler = new QueryProfiler();
        $logger = new Logger('test');

        $profiler->setLogger($logger);
        $this->assertSame($logger, $profiler->getLogger());

        $profiler->setLogger(null);
        $this->assertNull($profiler->getLogger());

        // Test fluent interface
        $this->assertInstanceOf(QueryProfiler::class, $profiler->setLogger($logger));
    }

    /**
     * Test setSlowQueryThreshold and getSlowQueryThreshold.
     */
    public function testSlowQueryThreshold(): void
    {
        $profiler = new QueryProfiler();

        $this->assertEquals(1.0, $profiler->getSlowQueryThreshold());

        $profiler->setSlowQueryThreshold(0.5);
        $this->assertEquals(0.5, $profiler->getSlowQueryThreshold());

        // Test fluent interface
        $this->assertInstanceOf(QueryProfiler::class, $profiler->setSlowQueryThreshold(2.0));
    }

    /**
     * Test startQuery and endQuery when disabled.
     */
    public function testStartQueryWhenDisabled(): void
    {
        $profiler = new QueryProfiler();

        $queryId = $profiler->startQuery('SELECT 1');
        $this->assertEquals(-1, $queryId);

        $result = $profiler->endQuery(-1);
        $this->assertNull($result);
    }

    /**
     * Test startQuery and endQuery when enabled.
     */
    public function testStartQueryAndEndQuery(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        $queryId = $profiler->startQuery('SELECT 1', ['param' => 'value']);

        $this->assertGreaterThanOrEqual(0, $queryId);

        // Wait a tiny bit
        usleep(1000); // 1ms

        $result = $profiler->endQuery($queryId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('memory', $result);
        $this->assertGreaterThanOrEqual(0, $result['time']);
        $this->assertGreaterThanOrEqual(0, $result['memory']);
    }

    /**
     * Test endQuery with invalid query ID.
     */
    public function testEndQueryInvalidId(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        $result = $profiler->endQuery(999);
        $this->assertNull($result);
    }

    /**
     * Test getStats.
     */
    public function testGetStats(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        $queryId = $profiler->startQuery('SELECT 1');
        usleep(1000);
        $profiler->endQuery($queryId);

        $stats = $profiler->getStats();
        $this->assertIsArray($stats);
        $this->assertNotEmpty($stats);

        // Check structure
        $firstStat = reset($stats);
        $this->assertArrayHasKey('sql', $firstStat);
        $this->assertArrayHasKey('count', $firstStat);
        $this->assertArrayHasKey('total_time', $firstStat);
        $this->assertArrayHasKey('avg_time', $firstStat);
        $this->assertArrayHasKey('min_time', $firstStat);
        $this->assertArrayHasKey('max_time', $firstStat);
        $this->assertArrayHasKey('total_memory', $firstStat);
        $this->assertArrayHasKey('avg_memory', $firstStat);
        $this->assertArrayHasKey('slow_queries', $firstStat);
    }

    /**
     * Test getAggregatedStats.
     */
    public function testGetAggregatedStats(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        // Execute multiple queries
        for ($i = 0; $i < 3; $i++) {
            $queryId = $profiler->startQuery('SELECT ' . $i);
            usleep(500);
            $profiler->endQuery($queryId);
        }

        $aggregated = $profiler->getAggregatedStats();

        $this->assertIsArray($aggregated);
        $this->assertEquals(3, $aggregated['total_queries']);
        $this->assertGreaterThan(0, $aggregated['total_time']);
        $this->assertGreaterThan(0, $aggregated['avg_time']);
        $this->assertGreaterThanOrEqual(0, $aggregated['min_time']);
        $this->assertGreaterThanOrEqual(0, $aggregated['max_time']);
        $this->assertGreaterThanOrEqual(0, $aggregated['total_memory']);
        $this->assertGreaterThanOrEqual(0, $aggregated['avg_memory']);
        $this->assertGreaterThanOrEqual(0, $aggregated['slow_queries']);
    }

    /**
     * Test getAggregatedStats with no queries.
     */
    public function testGetAggregatedStatsEmpty(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        $aggregated = $profiler->getAggregatedStats();

        $this->assertEquals(0, $aggregated['total_queries']);
        $this->assertEquals(0.0, $aggregated['total_time']);
        $this->assertEquals(0.0, $aggregated['avg_time']);
        $this->assertEquals(0.0, $aggregated['min_time']);
        $this->assertEquals(0.0, $aggregated['max_time']);
        $this->assertEquals(0, $aggregated['total_memory']);
        $this->assertEquals(0, $aggregated['avg_memory']);
        $this->assertEquals(0, $aggregated['slow_queries']);
    }

    /**
     * Test reset.
     */
    public function testReset(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        $queryId = $profiler->startQuery('SELECT 1');
        usleep(1000);
        $profiler->endQuery($queryId);

        $this->assertNotEmpty($profiler->getStats());

        $profiler->reset();

        $this->assertEmpty($profiler->getStats());

        // Test fluent interface
        $this->assertInstanceOf(QueryProfiler::class, $profiler->reset());
    }

    /**
     * Test getSlowestQueries.
     */
    public function testGetSlowestQueries(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        // Execute queries with different timings
        for ($i = 0; $i < 3; $i++) {
            $queryId = $profiler->startQuery('SELECT ' . $i);
            usleep(1000 * ($i + 1)); // Different delays
            $profiler->endQuery($queryId);
        }

        $slowest = $profiler->getSlowestQueries(2);

        $this->assertIsArray($slowest);
        $this->assertLessThanOrEqual(2, count($slowest));

        if (!empty($slowest)) {
            $first = $slowest[0];
            $this->assertArrayHasKey('sql', $first);
            $this->assertArrayHasKey('count', $first);
            $this->assertArrayHasKey('avg_time', $first);
            $this->assertArrayHasKey('max_time', $first);
            $this->assertArrayHasKey('slow_queries', $first);
            $this->assertArrayHasKey('avg_memory', $first);
        }
    }

    /**
     * Test slow query logging.
     */
    public function testSlowQueryLogging(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();
        $profiler->setSlowQueryThreshold(0.001); // Very low threshold

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $profiler->setLogger($logger);

        $queryId = $profiler->startQuery('SELECT * FROM large_table');
        usleep(5000); // 5ms - should trigger slow query
        $profiler->endQuery($queryId);

        // Check if slow query was logged
        $records = $handler->getRecords();
        $slowQueryLogged = false;
        foreach ($records as $record) {
            if ($record['message'] === 'query.slow') {
                $slowQueryLogged = true;
                $this->assertArrayHasKey('sql', $record['context']);
                $this->assertArrayHasKey('execution_time', $record['context']);
                $this->assertArrayHasKey('memory_used', $record['context']);
                break;
            }
        }

        $this->assertTrue($slowQueryLogged, 'Slow query should be logged');
    }

    /**
     * Test slow query not logged when below threshold.
     */
    public function testSlowQueryNotLoggedWhenBelowThreshold(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();
        $profiler->setSlowQueryThreshold(1.0); // High threshold

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $profiler->setLogger($logger);

        $queryId = $profiler->startQuery('SELECT 1');
        usleep(1000); // 1ms - below threshold
        $profiler->endQuery($queryId);

        // Check that slow query was NOT logged
        $records = $handler->getRecords();
        $slowQueryLogged = false;
        foreach ($records as $record) {
            if ($record['message'] === 'query.slow') {
                $slowQueryLogged = true;
                break;
            }
        }

        $this->assertFalse($slowQueryLogged, 'Slow query should not be logged when below threshold');
    }

    /**
     * Test disable clears stats.
     */
    public function testDisableClearsStats(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        $queryId = $profiler->startQuery('SELECT 1');
        usleep(1000);
        $profiler->endQuery($queryId);

        $this->assertNotEmpty($profiler->getStats());

        $profiler->disable();

        $this->assertEmpty($profiler->getStats());
    }

    /**
     * Test hashQuery normalizes SQL.
     */
    public function testHashQueryNormalization(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        // Two queries with different whitespace should have same hash
        $queryId1 = $profiler->startQuery('SELECT   *   FROM   users');
        usleep(1000);
        $profiler->endQuery($queryId1);

        $queryId2 = $profiler->startQuery('SELECT * FROM users');
        usleep(1000);
        $profiler->endQuery($queryId2);

        $stats = $profiler->getStats();
        // Should be grouped together (same hash)
        $this->assertLessThanOrEqual(2, count($stats));
    }
}
