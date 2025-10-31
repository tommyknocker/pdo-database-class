<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\helpers\Db;

/**
 * Tests for LATERAL JOIN functionality.
 */
final class LateralJoinTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create test tables
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                created_at TEXT
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                amount DECIMAL(10, 2),
                created_at TEXT
            )
        ');

        // Insert test data
        self::$db->rawQuery("
            INSERT INTO test_users (name, created_at) VALUES
            ('Alice', '2024-01-01'),
            ('Bob', '2024-01-02'),
            ('Charlie', '2024-01-03')
        ");

        self::$db->rawQuery("
            INSERT INTO test_orders (user_id, amount, created_at) VALUES
            (1, 100.00, '2024-01-10'),
            (1, 200.00, '2024-01-15'),
            (2, 150.00, '2024-01-12'),
            (2, 300.00, '2024-01-20'),
            (3, 50.00, '2024-01-05')
        ");
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clear data but keep structure
        self::$db->rawQuery('DELETE FROM test_orders');
        self::$db->rawQuery('DELETE FROM test_users');
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name IN ('test_users', 'test_orders')");

        // Re-insert test data
        self::$db->rawQuery("
            INSERT INTO test_users (name, created_at) VALUES
            ('Alice', '2024-01-01'),
            ('Bob', '2024-01-02'),
            ('Charlie', '2024-01-03')
        ");

        self::$db->rawQuery("
            INSERT INTO test_orders (user_id, amount, created_at) VALUES
            (1, 100.00, '2024-01-10'),
            (1, 200.00, '2024-01-15'),
            (2, 150.00, '2024-01-12'),
            (2, 300.00, '2024-01-20'),
            (3, 50.00, '2024-01-05')
        ");
    }

    public function testSupportsLateralJoinCheck(): void
    {
        $dialect = self::$db->find()->getConnection()->getDialect();
        $driverName = $dialect->getDriverName();

        if ($driverName === 'sqlite') {
            $this->assertFalse($dialect->supportsLateralJoin());
        } else {
            $this->assertTrue($dialect->supportsLateralJoin());
        }
    }

    public function testLateralJoinThrowsExceptionOnUnsupportedDialect(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        if ($driverName !== 'sqlite') {
            $this->markTestSkipped('This test is only for SQLite');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by sqlite dialect');

        self::$db->find()
            ->from('test_users AS u')
            ->lateralJoin(function ($q) {
                $q->from('test_orders')->where('user_id', 'u.id')->limit(1);
            });
    }

    public function testLateralJoinWithSubqueryAndAlias(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        if ($driverName === 'sqlite') {
            $this->markTestSkipped('LATERAL JOIN is not supported by SQLite');
        }

        // Test SQL generation
        $query = self::$db->find()
            ->from('test_users AS u')
            ->select([
                'u.id',
                'u.name',
                'latest_order.amount',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders')
                  ->select(['amount'])
                  ->where('user_id', 'u.id')
                  ->limit(1);
            }, null, 'LEFT', 'latest_order')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('latest_order', $query['sql']);
        $this->assertStringContainsString('test_orders', $query['sql']);
    }

    public function testLateralJoinWithOnCondition(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        if ($driverName === 'sqlite') {
            $this->markTestSkipped('LATERAL JOIN is not supported by SQLite');
        }

        // Test SQL generation with ON condition
        $query = self::$db->find()
            ->from('test_users AS u')
            ->select([
                'u.id',
                'u.name',
                'order_stats.total',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders')
                  ->select(['total' => 'SUM(amount)']);
            }, 'order_stats.user_id = u.id', 'INNER', 'order_stats')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('ON', $query['sql']);
        $this->assertStringContainsString('order_stats', $query['sql']);
    }

    public function testLateralJoinWithoutAliasGeneratesOne(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        if ($driverName === 'sqlite') {
            $this->markTestSkipped('LATERAL JOIN is not supported by SQLite');
        }

        // Test that alias is auto-generated when not provided
        $query = self::$db->find()
            ->from('test_users AS u')
            ->select(['u.id', 'u.name'])
            ->lateralJoin(function ($q) {
                $q->from('test_orders')
                  ->select(['order_id' => 'id'])
                  ->where('user_id', 'u.id')
                  ->limit(1);
            })
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        // Should contain auto-generated alias (starts with lateral_)
        $this->assertMatchesRegularExpression('/AS\s+"?lateral_[a-f0-9]+"?/i', $query['sql']);
    }

    public function testLateralJoinExternalReferenceWithoutDbRaw(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        if ($driverName === 'sqlite') {
            $this->markTestSkipped('LATERAL JOIN is not supported by SQLite');
        }

        // Test that u.id works automatically without Db::raw()
        // This verifies that ExternalReferenceProcessingTrait correctly detects
        // external table references in LATERAL JOIN subqueries

        // First, verify SQL generation
        $query = self::$db->find()
            ->from('test_users AS u')
            ->select(['u.id', 'u.name'])
            ->lateralJoin(function ($q) {
                $q->from('test_orders')
                  ->select(['order_amount' => 'amount'])
                  ->where('user_id', 'u.id') // No Db::raw() needed!
                  ->orderBy('created_at', 'DESC')
                  ->limit(1);
            }, null, 'LEFT', 'latest_order')
            ->toSQL();

        $this->assertStringContainsString('LATERAL', $query['sql']);
        $this->assertStringContainsString('latest_order', $query['sql']);
        // Verify that u.id is used as raw SQL (not as a parameter)
        $this->assertStringContainsString('u.id', $query['sql']);
        $this->assertStringNotContainsString(':u_id', $query['sql']);

        // Now execute the query to verify it works correctly
        $results = self::$db->find()
            ->from('test_users AS u')
            ->select([
                'u.id',
                'u.name',
                'latest_order.amount',
            ])
            ->lateralJoin(function ($q) {
                $q->from('test_orders')
                  ->select(['amount'])
                  ->where('user_id', 'u.id') // External reference works without Db::raw()
                  ->orderBy('created_at', 'DESC')
                  ->limit(1);
            }, null, 'LEFT', 'latest_order')
            ->get();

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);

        // Verify structure - each user should have their latest order amount (if exists)
        foreach ($results as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('amount', $row);

            // Alice (id=1) should have latest order amount 200.00
            if ($row['name'] === 'Alice') {
                $this->assertEquals(1, $row['id']);
                $this->assertEquals(200.00, $row['amount']);
            }
            // Bob (id=2) should have latest order amount 300.00
            if ($row['name'] === 'Bob') {
                $this->assertEquals(2, $row['id']);
                $this->assertEquals(300.00, $row['amount']);
            }
        }
    }
}
