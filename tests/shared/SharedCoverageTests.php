<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\query\QueryConstants;
use tommyknocker\pdodb\seeds\SeedRunner;

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
}
