<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

/**
 * MySQL-specific tests for LATERAL JOIN functionality.
 */
final class LateralJoinTests extends BaseMySQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Drop existing tables to ensure clean state
        self::$db->rawQuery('DROP TABLE IF EXISTS test_orders_lateral');
        self::$db->rawQuery('DROP TABLE IF EXISTS test_users_lateral');

        // Create test tables
        self::$db->rawQuery('
            CREATE TABLE test_users_lateral (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100),
                created_at DATE
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE test_orders_lateral (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                amount DECIMAL(10, 2),
                created_at DATE
            )
        ');

        // Insert test data
        self::$db->rawQuery("
            INSERT INTO test_users_lateral (name, created_at) VALUES
            ('Alice', '2024-01-01'),
            ('Bob', '2024-01-02'),
            ('Charlie', '2024-01-03')
        ");

        self::$db->rawQuery("
            INSERT INTO test_orders_lateral (user_id, amount, created_at) VALUES
            (1, 100.00, '2024-01-10'),
            (1, 200.00, '2024-01-15'),
            (2, 150.00, '2024-01-12'),
            (2, 300.00, '2024-01-20'),
            (3, 50.00, '2024-01-05')
        ");
    }

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM test_orders_lateral');
        self::$db->rawQuery('DELETE FROM test_users_lateral');

        // Re-insert test data
        self::$db->rawQuery("
            INSERT INTO test_users_lateral (name, created_at) VALUES
            ('Alice', '2024-01-01'),
            ('Bob', '2024-01-02'),
            ('Charlie', '2024-01-03')
        ");

        self::$db->rawQuery("
            INSERT INTO test_orders_lateral (user_id, amount, created_at) VALUES
            (1, 100.00, '2024-01-10'),
            (1, 200.00, '2024-01-15'),
            (2, 150.00, '2024-01-12'),
            (2, 300.00, '2024-01-20'),
            (3, 50.00, '2024-01-05')
        ");
    }

    public function testMySQLSupportsLateralJoin(): void
    {
        $dialect = self::$db->find()->getConnection()->getDialect();
        $this->assertTrue($dialect->supportsLateralJoin());
        $this->assertEquals('mysql', $dialect->getDriverName());
    }

    public function testLateralJoinLatestOrderPerUser(): void
    {
        // Test SQL generation for LATERAL JOIN with subquery
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select([
                'u.id',
                'u.name',
                'latest.amount',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select(['amount'])
                  ->where('user_id', 'u.id')
                  ->orderBy('created_at', 'DESC')
                  ->limit(1);
            }, null, 'LEFT', 'latest')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('LEFT JOIN', $query['sql']);
        $this->assertStringContainsString('latest', $query['sql']);
        $this->assertStringContainsString('ORDER BY', $query['sql']);
        $this->assertStringContainsString('LIMIT', $query['sql']);
    }

    public function testLateralJoinWithAggregation(): void
    {
        // Test SQL generation - aggregation in LATERAL JOIN subquery
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select([
                'u.name',
                'stats.total_amount',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select([
                      'total_amount' => 'SUM(amount)',
                      'order_count' => 'COUNT(*)',
                  ])
                  ->where('user_id', 'u.id');
            }, null, 'LEFT', 'stats')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('SUM(amount)', $query['sql']);
        $this->assertStringContainsString('COUNT(*)', $query['sql']);
        $this->assertStringContainsString('stats', $query['sql']);
    }

    public function testLateralJoinSqlGeneration(): void
    {
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->where('user_id', 'u.id')
                  ->limit(1);
            }, null, 'LEFT', 'latest')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('LEFT', $query['sql']);
        $this->assertStringContainsString('latest', $query['sql']);
    }

    public function testLateralJoinWithSubqueryAndAlias(): void
    {
        // Test SQL generation
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select([
                'u.id',
                'u.name',
                'latest_order.amount',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select(['amount'])
                  ->where('user_id', 'u.id')
                  ->limit(1);
            }, null, 'LEFT', 'latest_order')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('latest_order', $query['sql']);
        $this->assertStringContainsString('test_orders_lateral', $query['sql']);
    }

    public function testLateralJoinWithOnCondition(): void
    {
        // Test SQL generation with ON condition
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select([
                'u.id',
                'u.name',
                'order_stats.total',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select(['total' => 'SUM(amount)']);
            }, 'order_stats.user_id = u.id', 'INNER', 'order_stats')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('ON', $query['sql']);
        $this->assertStringContainsString('order_stats', $query['sql']);
    }

    public function testLateralJoinWithoutAliasGeneratesOne(): void
    {
        // Test that alias is auto-generated when not provided
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select(['u.id', 'u.name'])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select(['order_id' => 'id'])
                  ->where('user_id', 'u.id')
                  ->limit(1);
            })
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        // Should contain auto-generated alias (starts with lateral_)
        // MySQL uses backticks, PostgreSQL uses double quotes
        $this->assertMatchesRegularExpression('/AS\s+[`"]?lateral_[a-f0-9]+[`"]?/i', $query['sql']);
    }

    public function testLateralJoinExternalReferenceWithoutDbRaw(): void
    {
        // Test that u.id works automatically without Db::raw()
        // This verifies that ExternalReferenceProcessingTrait correctly detects
        // external table references in LATERAL JOIN subqueries

        // First, verify SQL generation
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select(['u.id', 'u.name'])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select(['order_amount' => 'amount'])
                  ->where('user_id', 'u.id') // No Db::raw() needed!
                  ->orderBy('created_at', 'DESC')
                  ->limit(1);
            }, null, 'LEFT', 'latest_order')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('latest_order', $query['sql']);
        // Verify that u.id is used as raw SQL (not as a parameter)
        // MySQL uses backticks for identifiers, so check for `u`.`id` pattern
        $this->assertMatchesRegularExpression('/`u`\s*\.\s*`id`/', $query['sql']);
        $this->assertStringNotContainsString(':u_id', $query['sql']);

        // Now execute the query to verify it works correctly
        $results = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select([
                'u.id',
                'u.name',
                'latest_order.amount',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select(['amount'])
                  ->where('user_id', 'u.id') // External reference works without Db::raw()
                  ->orderBy('created_at', 'DESC')
                  ->limit(1);
            }, null, 'LEFT', 'latest_order')
            ->get();

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);

        // Verify structure - each user should have columns from both tables
        foreach ($results as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('amount', $row);
        }
    }
}
