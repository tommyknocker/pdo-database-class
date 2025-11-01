<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

/**
 * MySQL-specific tests for LATERAL JOIN functionality.
 */
final class LateralJoinTests extends BaseMariaDBTestCase
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

    public function testMariaDBSupportsLateralJoin(): void
    {
        $dialect = self::$db->find()->getConnection()->getDialect();
        $this->assertFalse($dialect->supportsLateralJoin());
        $this->assertEquals('mariadb', $dialect->getDriverName());
    }

    public function testLateralJoinLatestOrderPerUser(): void
    {
        // MariaDB doesn't support LATERAL JOIN, should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by mariadb dialect');

        self::$db->find()
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
    }

    public function testLateralJoinWithAggregation(): void
    {
        // MariaDB doesn't support LATERAL JOIN, should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by mariadb dialect');

        self::$db->find()
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
    }

    public function testLateralJoinSqlGeneration(): void
    {
        // MariaDB doesn't support LATERAL JOIN, should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by mariadb dialect');

        self::$db->find()
            ->from('test_users_lateral AS u')
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->where('user_id', 'u.id')
                  ->limit(1);
            }, null, 'LEFT', 'latest')
            ->toSQL();
    }

    public function testLateralJoinWithSubqueryAndAlias(): void
    {
        // MariaDB doesn't support LATERAL JOIN, should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by mariadb dialect');

        self::$db->find()
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
    }

    public function testLateralJoinWithOnCondition(): void
    {
        // MariaDB doesn't support LATERAL JOIN, should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by mariadb dialect');

        self::$db->find()
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
    }

    public function testLateralJoinWithoutAliasGeneratesOne(): void
    {
        // MariaDB doesn't support LATERAL JOIN, should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by mariadb dialect');

        self::$db->find()
            ->from('test_users_lateral AS u')
            ->select(['u.id', 'u.name'])
            ->lateralJoin(function ($q) {
                $q->from('test_orders_lateral')
                  ->select(['order_id' => 'id'])
                  ->where('user_id', 'u.id')
                  ->limit(1);
            })
            ->toSQL();
    }

    public function testLateralJoinExternalReferenceWithoutDbRaw(): void
    {
        // MariaDB doesn't support LATERAL JOIN, should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by mariadb dialect');

        self::$db->find()
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
    }
}
