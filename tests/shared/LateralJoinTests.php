<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

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
}
