<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

/**
 * MSSQL-specific tests for LATERAL JOIN functionality (CROSS APPLY).
 */
final class LateralJoinTests extends BaseMSSQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $connection = self::$db->connection;
        assert($connection !== null);

        // Drop existing tables to ensure clean state
        $connection->query('IF OBJECT_ID(\'test_orders_lateral\', \'U\') IS NOT NULL DROP TABLE test_orders_lateral');
        $connection->query('IF OBJECT_ID(\'test_users_lateral\', \'U\') IS NOT NULL DROP TABLE test_users_lateral');

        // Create test tables
        $connection->query('
            CREATE TABLE test_users_lateral (
                id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(100),
                created_at DATE
            )
        ');

        $connection->query('
            CREATE TABLE test_orders_lateral (
                id INT IDENTITY(1,1) PRIMARY KEY,
                user_id INT,
                amount DECIMAL(10, 2),
                created_at DATE
            )
        ');

        // Insert test data
        $connection->query("
            INSERT INTO test_users_lateral (name, created_at) VALUES
            ('Alice', '2024-01-01'),
            ('Bob', '2024-01-02'),
            ('Charlie', '2024-01-03')
        ");

        $connection->query("
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
        $connection = self::$db->connection;
        assert($connection !== null);
        $connection->query('DELETE FROM test_orders_lateral');
        $connection->query('DELETE FROM test_users_lateral');

        // Re-insert test data
        $connection->query("
            INSERT INTO test_users_lateral (name, created_at) VALUES
            ('Alice', '2024-01-01'),
            ('Bob', '2024-01-02'),
            ('Charlie', '2024-01-03')
        ");

        $connection->query("
            INSERT INTO test_orders_lateral (user_id, amount, created_at) VALUES
            (1, 100.00, '2024-01-10'),
            (1, 200.00, '2024-01-15'),
            (2, 150.00, '2024-01-12'),
            (2, 300.00, '2024-01-20'),
            (3, 50.00, '2024-01-05')
        ");
    }

    public function testMSSQLSupportsLateralJoin(): void
    {
        $dialect = self::$db->find()->getConnection()->getDialect();
        $this->assertTrue($dialect->supportsLateralJoin());
        $this->assertEquals('sqlsrv', $dialect->getDriverName());
    }

    public function testLateralJoinLatestOrderPerUser(): void
    {
        // Test SQL generation for LATERAL JOIN with subquery (OUTER APPLY in MSSQL for LEFT JOIN)
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
                  ->where('user_id', '[u].[id]')
                  ->orderBy('created_at', 'DESC')
                  ->limit(1);
            }, null, 'LEFT', 'latest')
            ->toSQL();

        $this->assertStringContainsString('OUTER APPLY', $query['sql']);
        $this->assertStringNotContainsString('CROSS APPLY', $query['sql']);
        $this->assertStringContainsString('latest', $query['sql']);
        $this->assertStringContainsString('ORDER BY', $query['sql']);
    }

    public function testLateralJoinWithAggregation(): void
    {
        // Test SQL generation - aggregation in LATERAL JOIN subquery (OUTER APPLY in MSSQL for LEFT JOIN)
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select([
                'u.name',
                'stats.total_amount',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select([
                      'total_amount' => 'SUM([amount])',
                      'order_count' => 'COUNT(*)',
                  ])
                  ->where('user_id', '[u].[id]');
            }, null, 'LEFT', 'stats')
            ->toSQL();

        $this->assertStringContainsString('OUTER APPLY', $query['sql']);
        $this->assertStringNotContainsString('CROSS APPLY', $query['sql']);
        $this->assertStringContainsString('SUM([amount])', $query['sql']);
        $this->assertStringContainsString('COUNT(*)', $query['sql']);
        $this->assertStringContainsString('stats', $query['sql']);
    }
}
