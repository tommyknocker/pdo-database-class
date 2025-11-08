<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\helpers\Db;

/**
 * Shared tests for UPDATE/DELETE with JOIN functionality.
 */
final class UpdateDeleteJoinTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        // Create tables for UPDATE/DELETE with JOIN tests
        self::$db->rawQuery('DROP TABLE IF EXISTS update_delete_join_orders');
        self::$db->rawQuery('DROP TABLE IF EXISTS update_delete_join_users');

        if ($driverName === 'pgsql') {
            self::$db->rawQuery('
                CREATE TABLE update_delete_join_users (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(100),
                    status VARCHAR(50),
                    balance DECIMAL(10,2) DEFAULT 0
                )
            ');

            self::$db->rawQuery('
                CREATE TABLE update_delete_join_orders (
                    id SERIAL PRIMARY KEY,
                    user_id INTEGER,
                    amount DECIMAL(10,2),
                    status VARCHAR(50)
                )
            ');
        } elseif ($driverName === 'mysql' || $driverName === 'mariadb') {
            self::$db->rawQuery('
                CREATE TABLE update_delete_join_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100),
                    status VARCHAR(50),
                    balance DECIMAL(10,2) DEFAULT 0
                )
            ');

            self::$db->rawQuery('
                CREATE TABLE update_delete_join_orders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    amount DECIMAL(10,2),
                    status VARCHAR(50)
                )
            ');
        } else {
            // SQLite
            self::$db->rawQuery('
                CREATE TABLE update_delete_join_users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT,
                    status TEXT,
                    balance NUMERIC(10,2) DEFAULT 0
                )
            ');

            self::$db->rawQuery('
                CREATE TABLE update_delete_join_orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    amount NUMERIC(10,2),
                    status TEXT
                )
            ');
        }
    }

    public function setUp(): void
    {
        parent::setUp();
        // Clean up tables before each test
        self::$db->rawQuery('DELETE FROM update_delete_join_orders');
        self::$db->rawQuery('DELETE FROM update_delete_join_users');
        // Reset auto-increment counters
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();
        if ($driverName === 'sqlite') {
            try {
                self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name IN ('update_delete_join_users', 'update_delete_join_orders')");
            } catch (\PDOException $e) {
                // Ignore if sequence doesn't exist
            }
        }
    }

    public function testUpdateWithJoin(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        if (!self::$db->find()->getConnection()->getDialect()->supportsJoinInUpdateDelete()) {
            $this->expectException(RuntimeException::class);
        }

        // Insert test data
        $userId1 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);
        $userId2 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active', 'balance' => 200]);

        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);
        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId2, 'amount' => 75, 'status' => 'completed']);

        // Update user balance based on order amount using JOIN
        $affected = self::$db->find()
            ->table('update_delete_join_users')
            ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->where('update_delete_join_orders.status', 'completed')
            ->update(['balance' => Db::raw('update_delete_join_users.balance + update_delete_join_orders.amount')]);

        if ($driverName === 'sqlite') {
            // SQLite doesn't support JOIN in UPDATE, exception should be thrown
            return;
        }

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify update
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertNotNull($user1);
        // Balance should be updated (100 + 50 = 150)
        $this->assertEquals(150, (float)$user1['balance']);
    }

    public function testDeleteWithJoin(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        if (!self::$db->find()->getConnection()->getDialect()->supportsJoinInUpdateDelete()) {
            $this->expectException(RuntimeException::class);
        }

        // Insert test data
        $userId1 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active']);
        $userId2 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active']);

        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'cancelled']);
        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId2, 'amount' => 75, 'status' => 'completed']);

        // Delete users who have cancelled orders using JOIN
        $affected = self::$db->find()
            ->table('update_delete_join_users')
            ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->where('update_delete_join_orders.status', 'cancelled')
            ->delete();

        if ($driverName === 'sqlite') {
            // SQLite doesn't support JOIN in DELETE, exception should be thrown
            return;
        }

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify deletion
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertNull($user1);

        $user2 = self::$db->find()->from('update_delete_join_users')->where('id', $userId2)->getOne();
        $this->assertNotNull($user2);
    }

    public function testUpdateWithLeftJoin(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        if (!self::$db->find()->getConnection()->getDialect()->supportsJoinInUpdateDelete()) {
            $this->expectException(RuntimeException::class);
        }

        // Insert test data
        $userId1 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);
        $userId2 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active', 'balance' => 200]);

        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);

        // Update users who have orders using LEFT JOIN
        // Use qualified column name to avoid ambiguity
        $affected = self::$db->find()
            ->table('update_delete_join_users')
            ->leftJoin('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->where('update_delete_join_orders.id', null, 'IS NOT')
            ->update(['update_delete_join_users.status' => 'has_orders']);

        if ($driverName === 'sqlite') {
            // SQLite doesn't support JOIN in UPDATE, exception should be thrown
            return;
        }

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify update
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertNotNull($user1);
        $this->assertEquals('has_orders', $user1['status']);
    }
}
