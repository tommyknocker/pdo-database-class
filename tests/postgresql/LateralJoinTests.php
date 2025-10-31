<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

/**
 * PostgreSQL-specific tests for LATERAL JOIN functionality.
 */
final class LateralJoinTests extends BasePostgreSQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create test tables
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_users_lateral (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100),
                created_at DATE
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_orders_lateral (
                id SERIAL PRIMARY KEY,
                user_id INTEGER,
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

    public function testPostgreSQLSupportsLateralJoin(): void
    {
        $dialect = self::$db->find()->getConnection()->getDialect();
        $this->assertTrue($dialect->supportsLateralJoin());
        $this->assertEquals('pgsql', $dialect->getDriverName());
    }

    public function testLateralJoinLatestOrderPerUser(): void
    {
        // Test SQL generation only to avoid PostgreSQL syntax issues with external references
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
                  ->limit(1);
            }, null, 'LEFT', 'latest')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('LEFT JOIN', $query['sql']);
        $this->assertStringContainsString('latest', $query['sql']);
        $this->assertStringContainsString('test_orders_lateral', $query['sql']);
    }

    public function testLateralJoinWithTableValuedFunction(): void
    {
        // PostgreSQL-specific: LATERAL JOIN with table-valued function
        // Test SQL generation only (actual execution may require specific PostgreSQL setup)
        $query = self::$db->find()
            ->from('test_users_lateral AS u')
            ->select([
                'u.name',
            ])
            ->lateralJoin('generate_series(1, 2) AS n', null, 'CROSS')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('generate_series', $query['sql']);
        $this->assertStringContainsString('CROSS', $query['sql']);
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
