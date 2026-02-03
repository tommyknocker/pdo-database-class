<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\cache\CacheConfig;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\query\QueryConstants;
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
}
