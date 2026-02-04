<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use tommyknocker\pdodb\cache\CacheConfig;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\connection\ConnectionRouter;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\helpers\values\FulltextMatchValue;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\query\QueryConstants;
use tommyknocker\pdodb\query\cache\QueryCompilationCache;
use tommyknocker\pdodb\seeds\SeedDataGenerator;
use tommyknocker\pdodb\seeds\SeedRunner;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

/**
 * Tests to increase line coverage for shared/dialect-independent code.
 */
final class SharedCoverageTests extends BaseSharedTestCase
{
    public function testQueryConstantsComparisonOperators(): void
    {
        $ops = QueryConstants::getComparisonOperators();
        $this->assertContains(QueryConstants::OP_EQUAL, $ops);
        $this->assertContains(QueryConstants::OP_LESS_THAN, $ops);
        $this->assertContains(QueryConstants::OP_GREATER_THAN, $ops);
        $this->assertTrue(QueryConstants::isValidComparisonOperator('='));
        $this->assertFalse(QueryConstants::isValidComparisonOperator('invalid'));
    }

    public function testQueryConstantsBooleanOperators(): void
    {
        $ops = QueryConstants::getBooleanOperators();
        $this->assertContains(QueryConstants::BOOLEAN_AND, $ops);
        $this->assertContains(QueryConstants::BOOLEAN_OR, $ops);
        $this->assertTrue(QueryConstants::isValidBooleanOperator('AND'));
        $this->assertFalse(QueryConstants::isValidBooleanOperator('XOR'));
    }

    public function testQueryConstantsSqlOperators(): void
    {
        $ops = QueryConstants::getSqlOperators();
        $this->assertContains(QueryConstants::OP_IN, $ops);
        $this->assertContains(QueryConstants::OP_BETWEEN, $ops);
        $this->assertContains(QueryConstants::OP_LIKE, $ops);
        $this->assertTrue(QueryConstants::isValidSqlOperator('IN'));
        $this->assertTrue(QueryConstants::isValidSqlOperator('NOT IN'));
        $this->assertFalse(QueryConstants::isValidSqlOperator('invalid'));
    }

    public function testQueryConstantsLockAndConnection(): void
    {
        $this->assertSame('WRITE', QueryConstants::LOCK_WRITE);
        $this->assertSame('READ', QueryConstants::LOCK_READ);
        $this->assertSame('default', QueryConstants::CONNECTION_DEFAULT);
    }

    public function testConditionBuilderWhereWithBetween(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 5]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 15]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 25]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereBetween('value', 10, 20)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('B', $rows[0]['name']);
    }

    public function testConditionBuilderWhereWithNotBetween(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 5]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 15]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNotBetween('value', 10, 20)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('A', $rows[0]['name']);
    }

    public function testGetProfilerStatsAggregated(): void
    {
        $db = self::$db;
        $db->enableProfiling(0.1);
        self::$db->find()->from('test_coverage')->get();
        $stats = $db->getProfilerStats(true);
        $this->assertIsArray($stats);
        $db->disableProfiling();
    }

    public function testGetProfilerStatsWhenDisabled(): void
    {
        $db = self::$db;
        $db->setProfiler(null);
        $this->assertSame([], $db->getProfilerStats(false));
        $this->assertSame([], $db->getProfilerStats(true));
    }

    public function testSeedRunnerClearCollectedQueries(): void
    {
        $path = sys_get_temp_dir() . '/pdodb_seed_clear_' . uniqid();
        mkdir($path, 0755, true);
        $runner = new SeedRunner(self::$db, $path);
        $runner->clearCollectedQueries();
        $this->assertEmpty($runner->getCollectedQueries());
        rmdir($path);
    }

    public function testDdlQueryBuilderDropTableIfExists(): void
    {
        self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_ddl_drop (id INTEGER PRIMARY KEY)');
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_ddl_drop');
        $this->assertFalse($schema->tableExists('test_ddl_drop'));
    }

    public function testQueryBuilderInnerJoin(): void
    {
        self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_inner_a (id INTEGER PRIMARY KEY)');
        self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_inner_b (id INTEGER PRIMARY KEY, a_id INTEGER)');
        $qb = self::$db->find()
            ->from('test_inner_a AS a')
            ->innerJoin('test_inner_b AS b', 'b.a_id = a.id');
        $sql = $qb->toSQL();
        $this->assertStringContainsString('INNER', $sql['sql']);
    }

    public function testRawValueResolverInSelect(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'x', 'value' => 1]);
        $row = self::$db->find()
            ->from('test_coverage')
            ->select(['id', 'name', 'val' => Db::raw('value * 2')])
            ->where('name', 'x')
            ->getOne();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('val', $row);
    }

    public function testCacheManagerGettersAndEventDispatcher(): void
    {
        $cache = new ArrayCache();
        $config = new CacheConfig(prefix: 'cov_', enabled: true);
        $manager = new CacheManager($cache, $config);

        $this->assertSame($config, $manager->getConfig());
        $this->assertSame($cache, $manager->getCache());

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $manager->setEventDispatcher($dispatcher);
        $this->assertSame($dispatcher, $manager->getEventDispatcher());

        $manager->setEventDispatcher(null);
        $this->assertNull($manager->getEventDispatcher());
    }

    public function testCacheManagerGenerateKey(): void
    {
        $cache = new ArrayCache();
        $config = new CacheConfig(prefix: 'q_', enabled: true);
        $manager = new CacheManager($cache, $config);

        $key = $manager->generateKey('SELECT * FROM t WHERE id = ?', [1], 'sqlite');
        $this->assertIsString($key);
        $this->assertNotEmpty($key);
    }

    public function testSeedDataGeneratorGenerateWithOptions(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'a', 'value' => 1]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'b', 'value' => 2]);

        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('test_coverage', ['limit' => 1, 'exclude' => ['created_at']]);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('rowCount', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertIsString($result['content']);
        $this->assertGreaterThanOrEqual(0, $result['rowCount']);
    }

    public function testQueryBuilderHavingAndGroupBy(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'x', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'x', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'y', 'value' => 5]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->select(['name', 'total' => Db::raw('SUM(value)')])
            ->groupBy('name')
            ->having(Db::raw('SUM(value)'), 15, '>=')
            ->get();

        $this->assertIsArray($rows);
        $names = array_column($rows, 'name');
        $this->assertContains('x', $names);
    }

    public function testQueryBuilderWhereExists(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'exists', 'value' => 1]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereExists(function ($q): void {
                $q->from('test_coverage')->where('value', 1);
            })
            ->get();

        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(0, count($rows));
    }

    public function testQueryBuilderOrWhereWithCallback(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'a', 'value' => 1]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'b', 'value' => 2]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'a')
            ->orWhere('value', function ($q): void {
                $q->from('test_coverage')->select('value')->where('name', 'b');
            }, 'IN')
            ->get();

        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    public function testMigrationRunnerGetMigrationHistoryWithLimit(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $path = sys_get_temp_dir() . '/pdodb_mig_hist_' . uniqid();
        mkdir($path, 0755, true);

        $runner = new MigrationRunner(self::$db, $path);
        $history = $runner->getMigrationHistory(5);
        $this->assertIsArray($history);
        $this->assertCount(0, $history);

        self::$db->rawQuery('DROP TABLE IF EXISTS __migrations');
        rmdir($path);
    }

    public function testSeedDataGeneratorGenerateWithOrderBy(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'z', 'value' => 1]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'a', 'value' => 2]);

        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('test_coverage', ['limit' => 1, 'order_by' => 'id ASC']);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('rowCount', $result);
        $this->assertGreaterThanOrEqual(0, $result['rowCount']);
    }

    public function testQueryBuilderWhereNotExists(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'n', 'value' => 99]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNotExists(function ($q): void {
                $q->from('test_coverage')->where('value', 999);
            })
            ->get();

        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    public function testQueryBuilderIndexColumn(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'idx_a', 'value' => 1]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'idx_b', 'value' => 2]);

        $indexed = self::$db->find()
            ->from('test_coverage')
            ->select(['id', 'name', 'value'])
            ->index('id')
            ->get();

        $this->assertIsArray($indexed);
        $this->assertGreaterThanOrEqual(2, count($indexed));
        foreach ($indexed as $key => $row) {
            $this->assertIsInt($key);
            $this->assertArrayHasKey('name', $row);
        }
    }

    public function testQueryBuilderOrHaving(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'g1', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'g1', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'g2', 'value' => 5]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->select(['name', 'total' => Db::raw('SUM(value)')])
            ->groupBy('name')
            ->having(Db::raw('SUM(value)'), 15, '>=')
            ->orHaving(Db::raw('SUM(value)'), 5, '=')
            ->get();

        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    public function testQueryBuilderOffsetAndLimit(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'o1', 'value' => 1]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'o2', 'value' => 2]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'o3', 'value' => 3]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->offset(1)
            ->limit(1)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('name', $rows[0]);
    }

    public function testPdoDbConnectionRouterAndReadWriteSplitting(): void
    {
        $db = self::$db;
        $this->assertNull($db->getConnectionRouter());

        $db->enableReadWriteSplitting(null);
        $this->assertInstanceOf(ConnectionRouter::class, $db->getConnectionRouter());

        $db->disableReadWriteSplitting();
        $this->assertNull($db->getConnectionRouter());
    }

    public function testPdoDbGetCacheManagerAndGetCompilationCache(): void
    {
        $cache = new ArrayCache();
        $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:'], [], null, $cache);
        $this->assertInstanceOf(CacheManager::class, $db->getCacheManager());
        $this->assertInstanceOf(QueryCompilationCache::class, $db->getCompilationCache());
    }

    public function testPdoDbSetCompilationCacheAndGetCompilationCache(): void
    {
        $db = self::$db;
        $compilationCache = new QueryCompilationCache(new ArrayCache());
        $db->setCompilationCache($compilationCache);
        $this->assertSame($compilationCache, $db->getCompilationCache());
        $db->setCompilationCache(null);
        $this->assertNull($db->getCompilationCache());
    }

    public function testQueryCompilationCacheGetPrefixAndClear(): void
    {
        $cache = new QueryCompilationCache(new ArrayCache());
        $this->assertSame('pdodb_compiled_', $cache->getPrefix());
        $cache->clear();
        $this->assertSame('pdodb_compiled_', $cache->getPrefix());
    }

    public function testQueryBuilderGetUnionsIsDistinctGetDistinctOnExecuteStatement(): void
    {
        $qb = self::$db->find()->from('test_coverage');
        $this->assertSame([], $qb->getUnions());
        $this->assertFalse($qb->isDistinct());
        $this->assertSame([], $qb->getDistinctOn());

        $qb->distinct();
        $this->assertTrue($qb->isDistinct());

        $qb2 = self::$db->find()->from('test_coverage')->union(self::$db->find()->from('test_coverage')->select('id'));
        $unions = $qb2->getUnions();
        $this->assertCount(1, $unions);

        $stmt = self::$db->find()->executeStatement('SELECT 1', []);
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $stmt->closeCursor();
    }

    public function testCteManagerClear(): void
    {
        $qb = self::$db->find()
            ->with('cte_cover', self::$db->find()->from('test_coverage')->select('id'))
            ->from('cte_cover');
        $cteManager = $qb->getCteManager();
        $this->assertNotNull($cteManager);
        $cteManager->clear();
        $this->assertSame([], $cteManager->getParams());
    }

    public function testSelectQueryBuilderSetDistinctOnSetCompilationCacheViaReflection(): void
    {
        $qb = self::$db->find()->from('test_coverage');
        $ref = new ReflectionClass($qb);
        $prop = $ref->getProperty('selectQueryBuilder');
        $prop->setAccessible(true);
        $selectQb = $prop->getValue($qb);

        $selectQb->setDistinctOn(['name', 'value']);

        $compilationCache = new QueryCompilationCache(new ArrayCache());
        $selectQb->setCompilationCache($compilationCache);
        $cacheProp = (new ReflectionClass($selectQb))->getProperty('compilationCache');
        $cacheProp->setAccessible(true);
        $this->assertSame($compilationCache, $cacheProp->getValue($selectQb));
    }

    public function testQueryProfilerGetLoggerAndSlowQueryLogging(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with('query.slow', $this->anything());
        $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:'], [], $logger);
        $db->rawQuery('CREATE TABLE t (id INTEGER)');
        $db->enableProfiling(0.0);
        $db->find()->from('t')->get();
        $profiler = $db->getProfiler();
        $this->assertSame($logger, $profiler->getLogger());
    }

    public function testFulltextMatchValueGetters(): void
    {
        $value = new FulltextMatchValue(['title', 'body'], 'search term', 'boolean', true);
        $this->assertSame(['title', 'body'], $value->getColumns());
        $this->assertSame('search term', $value->getSearchTerm());
        $this->assertSame('boolean', $value->getMode());
        $this->assertTrue($value->isWithQueryExpansion());

        $value2 = new FulltextMatchValue('title', 'word', null, false);
        $this->assertSame('title', $value2->getColumns());
        $this->assertNull($value2->getMode());
        $this->assertFalse($value2->isWithQueryExpansion());
    }

    public function testDbHelperWindowFunctionsNtileAndNthValue(): void
    {
        $qb = self::$db->find()->from('test_coverage')->select(['id', Db::as(Db::ntile(2), 'bucket')]);
        $sql = $qb->toSQL();
        $this->assertArrayHasKey('sql', $sql);

        $qb2 = self::$db->find()->from('test_coverage')->select(['id', Db::as(Db::nthValue('value', 1), 'nth')]);
        $sql2 = $qb2->toSQL();
        $this->assertArrayHasKey('sql', $sql2);
    }

    public function testDbHelperStringLpadRpadRegexpMatch(): void
    {
        $qb = self::$db->find()->from('test_coverage')->select([Db::as(Db::lpad('name', 10, ' '), 'lp')]);
        $sql = $qb->toSQL();
        $this->assertArrayHasKey('sql', $sql);

        $qb2 = self::$db->find()->from('test_coverage')->select([Db::as(Db::rpad('name', 10, 'x'), 'rp')]);
        $sql2 = $qb2->toSQL();
        $this->assertArrayHasKey('sql', $sql2);

        $qb3 = self::$db->find()->from('test_coverage')->select([Db::as(Db::regexpMatch('name', '^a'), 'match')]);
        $sql3 = $qb3->toSQL();
        $this->assertArrayHasKey('sql', $sql3);
    }

    public function testDbHelperNumericExpLnLog(): void
    {
        $qb = self::$db->find()->from('test_coverage')->select([Db::as(Db::exp('value'), 'e')]);
        $sql = $qb->toSQL();
        $this->assertArrayHasKey('sql', $sql);

        $qb2 = self::$db->find()->from('test_coverage')->select([Db::as(Db::ln('value'), 'l')]);
        $sql2 = $qb2->toSQL();
        $this->assertArrayHasKey('sql', $sql2);

        $qb3 = self::$db->find()->from('test_coverage')->select([Db::as(Db::log('value', 2), 'log2')]);
        $sql3 = $qb3->toSQL();
        $this->assertArrayHasKey('sql', $sql3);
    }

    public function testDbHelperJsonExtractAndJsonArray(): void
    {
        $qb = self::$db->find()->from('test_coverage')->select([Db::as(Db::jsonExtract('meta', '$.x', true), 'j')]);
        $sql = $qb->toSQL();
        $this->assertArrayHasKey('sql', $sql);

        $arr = Db::jsonArray(1, 2, 3);
        $this->assertIsString($arr);
        $this->assertNotEmpty($arr);
    }

    public function testDbHelperArrayAggAndJsonArrayAgg(): void
    {
        $qb = self::$db->find()->from('test_coverage')->select([Db::as(Db::arrayAgg('name'), 'names')]);
        $sql = $qb->toSQL();
        $this->assertArrayHasKey('sql', $sql);

        $qb2 = self::$db->find()->from('test_coverage')->select([Db::as(Db::jsonArrayAgg('name'), 'jnames')]);
        $sql2 = $qb2->toSQL();
        $this->assertArrayHasKey('sql', $sql2);
    }

    public function testParameterManagerSetParams(): void
    {
        $qb = self::$db->find()->from('test_coverage');
        $ref = new ReflectionClass($qb);
        $prop = $ref->getProperty('parameterManager');
        $prop->setAccessible(true);
        $pm = $prop->getValue($qb);
        $pm->setParams([':x' => 1, ':y' => 2]);
        $this->assertSame([':x' => 1, ':y' => 2], $pm->getParams());
    }

    public function testSelectQueryBuilderResultCacheAndGenerateCacheKey(): void
    {
        $cache = new ArrayCache();
        $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:'], [], null, $cache);
        $db->rawQuery('CREATE TABLE t (id INTEGER)');
        $db->rawQuery('INSERT INTO t (id) VALUES (1)');

        $result = $db->find()->from('t')->cache(60)->get();
        $this->assertIsArray($result);
        $result2 = $db->find()->from('t')->cache(60)->get();
        $this->assertEquals($result, $result2);

        $qb = $db->find()->from('t');
        $ref = new ReflectionClass($qb);
        $prop = $ref->getProperty('selectQueryBuilder');
        $prop->setAccessible(true);
        $selectQb = $prop->getValue($qb);
        $method = (new ReflectionClass($selectQb))->getMethod('generateCacheKey');
        $method->setAccessible(true);
        $key = $method->invoke($selectQb);
        $this->assertIsString($key);
    }
}
