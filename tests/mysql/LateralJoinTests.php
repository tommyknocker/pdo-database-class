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
}
