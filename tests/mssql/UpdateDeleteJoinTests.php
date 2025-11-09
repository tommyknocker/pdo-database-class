<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * MSSQL-specific tests for UPDATE/DELETE with JOIN functionality.
 */
final class UpdateDeleteJoinTests extends BaseMSSQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $connection = self::$db->connection;
        assert($connection !== null);

        // Create tables for UPDATE/DELETE with JOIN tests
        $connection->query('IF OBJECT_ID(\'update_delete_join_orders\', \'U\') IS NOT NULL DROP TABLE update_delete_join_orders');
        $connection->query('IF OBJECT_ID(\'update_delete_join_users\', \'U\') IS NOT NULL DROP TABLE update_delete_join_users');

        $connection->query('
            CREATE TABLE update_delete_join_users (
                id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(100),
                status NVARCHAR(50),
                balance DECIMAL(10,2) DEFAULT 0
            )
        ');

        $connection->query('
            CREATE TABLE update_delete_join_orders (
                id INT IDENTITY(1,1) PRIMARY KEY,
                user_id INT,
                amount DECIMAL(10,2),
                status NVARCHAR(50)
            )
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        $connection = self::$db->connection;
        assert($connection !== null);
        // Clean up tables before each test - delete in correct order due to foreign key
        $connection->query('DELETE FROM update_delete_join_orders');
        $connection->query('DELETE FROM update_delete_join_users');
        // Reset IDENTITY seed
        $connection->query('DBCC CHECKIDENT(\'update_delete_join_users\', RESEED, 0)');
        $connection->query('DBCC CHECKIDENT(\'update_delete_join_orders\', RESEED, 0)');
        // Ensure balances are reset
        $connection->query('UPDATE update_delete_join_users SET balance = 0');
    }

    public function testUpdateWithJoin(): void
    {
        // Insert test data
        $userId1 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);
        $userId2 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active', 'balance' => 200]);

        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);
        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId2, 'amount' => 75, 'status' => 'completed']);

        // Update user balance based on order amount using JOIN
        // Note: In MSSQL, UPDATE with JOIN can update rows multiple times if JOIN returns multiple rows
        // So we use a subquery with SUM to aggregate amounts per user
        // Get initial balances
        $user1Before = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $user2Before = self::$db->find()->from('update_delete_join_users')->where('id', $userId2)->getOne();
        $initialBalance1 = (float)$user1Before['balance'];
        $initialBalance2 = (float)$user2Before['balance'];

        $query = self::$db->find()
            ->table('update_delete_join_users')
            ->where('id', [$userId1, $userId2], 'IN')
            ->whereRaw('EXISTS (
                SELECT 1 FROM update_delete_join_orders
                WHERE update_delete_join_orders.[user_id] = update_delete_join_users.[id]
                AND update_delete_join_orders.[status] = \'completed\'
            )');
        $affected = $query->update([
            'balance' => Db::raw('update_delete_join_users.[balance] + (
                SELECT ISNULL(SUM(update_delete_join_orders.[amount]), 0)
                FROM update_delete_join_orders
                WHERE update_delete_join_orders.[user_id] = update_delete_join_users.[id]
                AND update_delete_join_orders.[status] = \'completed\'
            )'),
        ]);

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify update
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertNotNull($user1);
        // Balance should be updated (initial + 50)
        $this->assertEquals($initialBalance1 + 50, (float)$user1['balance']);

        $user2 = self::$db->find()->from('update_delete_join_users')->where('id', $userId2)->getOne();
        $this->assertNotNull($user2);
        // Balance should be updated (initial + 75)
        $this->assertEquals($initialBalance2 + 75, (float)$user2['balance']);
    }

    public function testDeleteWithJoin(): void
    {
        // Insert test data
        $userId1 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active']);
        $userId2 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active']);

        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'cancelled']);
        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId2, 'amount' => 75, 'status' => 'completed']);

        // Delete users who have cancelled orders using JOIN
        $affected = self::$db->find()
            ->table('update_delete_join_users')
            ->join('update_delete_join_orders', 'update_delete_join_orders.[user_id] = update_delete_join_users.[id]')
            ->where('update_delete_join_orders.[status]', 'cancelled')
            ->delete();

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify deletion
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertFalse($user1);

        $user2 = self::$db->find()->from('update_delete_join_users')->where('id', $userId2)->getOne();
        $this->assertNotNull($user2);
    }

    public function testUpdateWithLeftJoin(): void
    {
        // Insert test data
        $userId1 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);
        $userId2 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active', 'balance' => 200]);

        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);

        // Update users who have orders using LEFT JOIN
        // Use qualified column name to avoid ambiguity
        $affected = self::$db->find()
            ->table('update_delete_join_users')
            ->leftJoin('update_delete_join_orders', 'update_delete_join_orders.[user_id] = update_delete_join_users.[id]')
            ->where('update_delete_join_orders.[id]', null, 'IS NOT')
            ->update(['update_delete_join_users.status' => 'has_orders']);

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify update
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertNotNull($user1);
        $this->assertEquals('has_orders', $user1['status']);
    }

    public function testUpdateWithMultipleJoins(): void
    {
        // Insert test data
        $userId1 = self::$db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);

        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);
        self::$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 25, 'status' => 'completed']);

        // Update with multiple conditions using JOIN
        $affected = self::$db->find()
            ->table('update_delete_join_users')
            ->join('update_delete_join_orders', 'update_delete_join_orders.[user_id] = update_delete_join_users.[id]')
            ->where('update_delete_join_users.[id]', $userId1)
            ->where('update_delete_join_orders.[status]', 'completed')
            ->update(['status' => 'has_completed_orders']);

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify update
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertNotNull($user1);
        $this->assertEquals('has_completed_orders', $user1['status']);
    }
}
