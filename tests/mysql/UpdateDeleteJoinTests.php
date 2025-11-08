<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\helpers\Db;

/**
 * MySQL-specific tests for UPDATE/DELETE with JOIN functionality.
 */
final class UpdateDeleteJoinTests extends BaseMySQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create tables for UPDATE/DELETE with JOIN tests
        self::$db->rawQuery('DROP TABLE IF EXISTS update_delete_join_orders');
        self::$db->rawQuery('DROP TABLE IF EXISTS update_delete_join_users');

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
    }

    public function setUp(): void
    {
        parent::setUp();
        // Clean up tables before each test
        self::$db->rawQuery('SET FOREIGN_KEY_CHECKS=0');
        self::$db->rawQuery('TRUNCATE TABLE update_delete_join_orders');
        self::$db->rawQuery('TRUNCATE TABLE update_delete_join_users');
        self::$db->rawQuery('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testUpdateWithJoin(): void
    {
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

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify update
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertNotNull($user1);
        // Balance should be updated (100 + 50 = 150)
        $this->assertEquals(150, (float)$user1['balance']);

        $user2 = self::$db->find()->from('update_delete_join_users')->where('id', $userId2)->getOne();
        $this->assertNotNull($user2);
        // Balance should be updated (200 + 75 = 275)
        $this->assertEquals(275, (float)$user2['balance']);
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
            ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->where('update_delete_join_orders.status', 'cancelled')
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
            ->leftJoin('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->where('update_delete_join_orders.id', null, 'IS NOT')
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
            ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->where('update_delete_join_users.id', $userId1)
            ->where('update_delete_join_orders.status', 'completed')
            ->update(['status' => 'has_completed_orders']);

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify update
        $user1 = self::$db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        $this->assertNotNull($user1);
        $this->assertEquals('has_completed_orders', $user1['status']);
    }
}
