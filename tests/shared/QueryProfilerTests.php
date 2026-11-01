<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use tommyknocker\pdodb\ai\QueryContextBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\SessionQuoteMode;
use tommyknocker\pdodb\PdoDb;
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

    public function testOverlappingQueriesKeepDistinctIds(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();

        $first = $profiler->startQuery('SELECT 1');
        $second = $profiler->startQuery('SELECT 2');
        $profiler->endQuery($first);
        $third = $profiler->startQuery('SELECT 3');

        $this->assertNotSame($second, $third);
        $profiler->endQuery($second);
        $profiler->endQuery($third);
        $this->assertCount(3, $profiler->getStats());
    }

    public function testSlowestQueriesRankByTotalTimeAndFlagRepeatedShapes(): void
    {
        $profiler = new QueryProfiler();
        $stats = new \ReflectionProperty($profiler, 'stats');
        $stats->setValue($profiler, [
            'many' => [
                'sql' => 'SELECT id FROM orders WHERE id = ?',
                'count' => 20,
                'total_time' => 2.0,
                'avg_time' => 0.1,
                'min_time' => 0.1,
                'max_time' => 0.1,
                'total_memory' => 0,
                'avg_memory' => 0,
                'slow_queries' => 0,
            ],
            'one' => [
                'sql' => "SELECT * FROM orders WHERE note = 'secret'",
                'count' => 1,
                'total_time' => 5.0,
                'avg_time' => 5.0,
                'min_time' => 5.0,
                'max_time' => 5.0,
                'total_memory' => 0,
                'avg_memory' => 0,
                'slow_queries' => 1,
            ],
        ]);

        $slowest = $profiler->getSlowestQueries(2);

        $this->assertSame(5.0, $slowest[0]['total_time']);
        $this->assertSame(1, $slowest[0]['count']);
        $this->assertFalse($slowest[0]['possible_n_plus_one']);
        $this->assertStringNotContainsString('secret', $slowest[0]['sql']);
        $this->assertSame(20, $slowest[1]['count']);
        $this->assertTrue($slowest[1]['possible_n_plus_one']);
    }

    public function testSlowQueryLogOmitsParametersUnlessEnabled(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();
        $profiler->setSlowQueryThreshold(0.0);
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $profiler->setLogger($logger);

        $queryId = $profiler->startQuery("SELECT * FROM users WHERE name = 'Ada'", ['name' => 'Ada']);
        $profiler->endQuery($queryId);

        $context = $handler->getRecords()[0]['context'];
        $this->assertArrayNotHasKey('params', $context);
        $this->assertFalse($context['parameters_included']);
        $this->assertStringNotContainsString('Ada', $context['sql']);

        $profiler->setLogParameters(true);
        $queryId = $profiler->startQuery("SELECT * FROM users WHERE name = 'Ada'", ['name' => 'Ada']);
        $profiler->endQuery($queryId);
        $detailed = $handler->getRecords()[1]['context'];
        $this->assertSame(['name' => 'Ada'], $detailed['params']);
    }

    public function testRedactionRemovesNumbersAndComments(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();
        $profiler->setSlowQueryThreshold(0.0);
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $profiler->setLogger($logger);

        $profiler->endQuery($profiler->startQuery('SELECT id FROM users WHERE id = 42 -- secret note'));

        $sql = $profiler->getSlowestQueries(1)[0]['sql'];
        $this->assertStringNotContainsString('42', $sql);
        $this->assertStringNotContainsString('secret', $sql);
        $logged = $handler->getRecords()[0]['context']['sql'];
        $this->assertStringNotContainsString('42', $logged);
        $this->assertStringNotContainsString('secret', $logged);
    }

    public function testSlowQueryLogContinuesAfterTheShapeLimit(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();
        $profiler->setSlowQueryThreshold(0.0);
        $profiler->setMaxTrackedQueries(1);
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $profiler->setLogger($logger);

        $profiler->endQuery($profiler->startQuery('SELECT 1'));
        $profiler->endQuery($profiler->startQuery('SELECT 2'));

        $this->assertTrue($profiler->isTruncated());
        $this->assertCount(1, $profiler->getStats());
        $this->assertCount(2, $handler->getRecords());
    }

    public function testDistinctSqlShapesAreCapped(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();
        $profiler->setMaxTrackedQueries(1);

        $first = $profiler->startQuery('SELECT 1');
        $profiler->endQuery($first);
        $second = $profiler->startQuery('SELECT 2');
        $profiler->endQuery($second);

        $this->assertTrue($profiler->isTruncated());
        $this->assertCount(1, $profiler->getStats());
    }

    public function testDoubleQuotedTextIsRedactedOnSqlitePrivacyPaths(): void
    {
        self::$db->enableProfiling(0.0);

        try {
            try {
                self::$db->rawQuery('SELECT "PRIVATE_VALUE_428"');
            } catch (PDOException) {
                // A SQLite build may reject the statement. The SQL is still profiled.
            }
            $summary = self::$db->profileReport()->summary();
            $encoded = json_encode($summary);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('PRIVATE_VALUE_428', $encoded);
            $this->assertStringContainsString('not a complete privacy boundary', $summary['note']);

            $context = (new QueryContextBuilder(self::$db))->build(
                'SELECT "PRIVATE_VALUE_428"',
                null,
                null,
                ['context' => ['tables' => []]]
            );
            $this->assertStringNotContainsString('PRIVATE_VALUE_428', (string)$context['sql']);
        } finally {
            self::$db->disableProfiling();
        }
    }

    public function testEnableProfilingBeforeConnectionDoesNotRequireDatabase(): void
    {
        $db = new PdoDb();
        $db->enableProfiling(0.5);
        $this->assertTrue($db->isProfilingEnabled());

        $db->addConnection('main', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->connection('main');
        $db->rawQuery('SELECT "PRIVATE_VALUE_428"');
        $encoded = json_encode($db->profileReport()->summary());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('PRIVATE_VALUE_428', $encoded);
    }

    public function testProfileReportDiagnosesWithoutAModelRequest(): void
    {
        self::$db->enableProfiling(0.0);

        try {
            self::$db->find()->from('test_coverage')->get();
            $summary = self::$db->profileReport()->summary();
            $this->assertTrue($summary['enabled']);
            $this->assertFalse($summary['parameters_included']);
            $this->assertNotSame('', self::$db->profileReport()->export());

            $diagnosis = self::$db->profileReport()->diagnose('SELECT * FROM test_coverage');
            $this->assertFalse($diagnosis['model_request']);
            $this->assertArrayHasKey('source', $diagnosis);

            $ranked = self::$db->profileReport()->diagnoseRankedQuery(1);
            $this->assertSame(1, $ranked['rank']);
            $this->assertArrayHasKey('sql', $ranked);
            $this->assertStringContainsString('test_coverage', $ranked['sql']);
        } finally {
            self::$db->disableProfiling();
        }
    }
    public function testProfileKeepsTheOriginatingRedactionAfterModeChange(): void
    {
        $mode = true;
        $profiler = new QueryProfiler();
        $profiler->setDoubleQuotedTextIsLiteral(static function () use (&$mode): bool {
            return $mode;
        });
        $profiler->enable();
        $id = $profiler->startQuery('SELECT "PROFILE_HISTORY_SECRET"');
        $mode = false;
        $profiler->endQuery($id);
        $this->assertStringNotContainsString('PROFILE_HISTORY_SECRET', $profiler->getSlowestQueries()[0]['sql']);
    }

    public function testAmbiguousEscapingIsNotPartiallyExposed(): void
    {
        $profiler = new QueryProfiler();
        $profiler->setDoubleQuotedTextIsLiteral(static fn (): bool => true);
        $profiler->enable();
        $id = $profiler->startQuery('SELECT "before\\"PROFILE_ESCAPE_SECRET"');
        $profiler->endQuery($id);
        $this->assertStringNotContainsString('PROFILE_ESCAPE_SECRET', $profiler->getSlowestQueries()[0]['sql']);
    }

    public function testUnknownMysqlQuoteModeUsesSafeDefault(): void
    {
        $this->assertTrue(SessionQuoteMode::doubleQuotesAreLiterals(null));
    }
}
