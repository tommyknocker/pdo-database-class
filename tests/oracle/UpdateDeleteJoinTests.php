<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * Oracle-specific tests for UPDATE/DELETE with JOIN functionality.
 */
final class UpdateDeleteJoinTests extends BaseOracleTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create tables for UPDATE/DELETE with JOIN tests
        // Drop triggers first (they depend on tables and sequences)
        try {
            self::$db->rawQuery('DROP TRIGGER update_delete_join_users_trigger');
        } catch (\Throwable) {
            // Trigger doesn't exist, continue
        }

        try {
            self::$db->rawQuery('DROP TRIGGER update_delete_join_orders_trigger');
        } catch (\Throwable) {
            // Trigger doesn't exist, continue
        }

        // Drop sequences before tables
        try {
            self::$db->rawQuery('DROP SEQUENCE update_delete_join_users_seq');
        } catch (\Throwable) {
            // Sequence doesn't exist, continue
        }

        try {
            self::$db->rawQuery('DROP SEQUENCE update_delete_join_orders_seq');
        } catch (\Throwable) {
            // Sequence doesn't exist, continue
        }

        // Drop tables
        try {
            self::$db->rawQuery('DROP TABLE "UPDATE_DELETE_JOIN_ORDERS" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        try {
            self::$db->rawQuery('DROP TABLE "UPDATE_DELETE_JOIN_USERS" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        self::$db->rawQuery('
            CREATE TABLE "UPDATE_DELETE_JOIN_USERS" (
                "ID" NUMBER PRIMARY KEY,
                "NAME" VARCHAR2(100),
                "STATUS" VARCHAR2(50),
                "BALANCE" NUMBER(10,2) DEFAULT 0
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE "UPDATE_DELETE_JOIN_ORDERS" (
                "ID" NUMBER PRIMARY KEY,
                "USER_ID" NUMBER,
                "AMOUNT" NUMBER(10,2),
                "STATUS" VARCHAR2(50)
            )
        ');

        self::$db->rawQuery('CREATE SEQUENCE update_delete_join_users_seq START WITH 1 INCREMENT BY 1');
        self::$db->rawQuery('CREATE SEQUENCE update_delete_join_orders_seq START WITH 1 INCREMENT BY 1');

        self::$db->rawQuery('
            CREATE OR REPLACE TRIGGER update_delete_join_users_trigger
            BEFORE INSERT ON "UPDATE_DELETE_JOIN_USERS"
            FOR EACH ROW
            BEGIN
                IF :NEW."ID" IS NULL THEN
                    SELECT update_delete_join_users_seq.NEXTVAL INTO :NEW."ID" FROM DUAL;
                END IF;
            END;
        ');

        self::$db->rawQuery('
            CREATE OR REPLACE TRIGGER update_delete_join_orders_trigger
            BEFORE INSERT ON "UPDATE_DELETE_JOIN_ORDERS"
            FOR EACH ROW
            BEGIN
                IF :NEW."ID" IS NULL THEN
                    SELECT update_delete_join_orders_seq.NEXTVAL INTO :NEW."ID" FROM DUAL;
                END IF;
            END;
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        // Clean up tables before each test
        self::$db->rawQuery('TRUNCATE TABLE "UPDATE_DELETE_JOIN_ORDERS"');
        self::$db->rawQuery('TRUNCATE TABLE "UPDATE_DELETE_JOIN_USERS"');
    }

    public function testUpdateWithJoin(): void
    {
        // Insert test data
        $userId1 = self::$db->find()->table('UPDATE_DELETE_JOIN_USERS')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);
        $userId2 = self::$db->find()->table('UPDATE_DELETE_JOIN_USERS')->insert(['name' => 'Bob', 'status' => 'active', 'balance' => 200]);

        self::$db->find()->table('UPDATE_DELETE_JOIN_ORDERS')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);
        self::$db->find()->table('UPDATE_DELETE_JOIN_ORDERS')->insert(['user_id' => $userId2, 'amount' => 75, 'status' => 'completed']);

        // Update user balance based on order amount using JOIN
        $affected = self::$db->find()
            ->table('UPDATE_DELETE_JOIN_USERS')
            ->join('UPDATE_DELETE_JOIN_ORDERS', 'UPDATE_DELETE_JOIN_ORDERS.USER_ID = UPDATE_DELETE_JOIN_USERS.ID')
            ->where('UPDATE_DELETE_JOIN_ORDERS.STATUS', 'completed')
            ->update(['balance' => Db::raw('UPDATE_DELETE_JOIN_USERS.BALANCE + UPDATE_DELETE_JOIN_ORDERS.AMOUNT')]);

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify update
        $user1 = self::$db->find()->from('UPDATE_DELETE_JOIN_USERS')->where('id', $userId1)->getOne();
        $this->assertNotNull($user1);
        // Balance should be updated (100 + 50 = 150)
        $this->assertEquals(150, (float)$user1['BALANCE']);
    }

    public function testDeleteWithJoin(): void
    {
        // Insert test data
        $userId1 = self::$db->find()->table('UPDATE_DELETE_JOIN_USERS')->insert(['name' => 'Alice', 'status' => 'active']);
        $userId2 = self::$db->find()->table('UPDATE_DELETE_JOIN_USERS')->insert(['name' => 'Bob', 'status' => 'active']);

        self::$db->find()->table('UPDATE_DELETE_JOIN_ORDERS')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'cancelled']);
        self::$db->find()->table('UPDATE_DELETE_JOIN_ORDERS')->insert(['user_id' => $userId2, 'amount' => 75, 'status' => 'completed']);

        // Delete users who have cancelled orders using JOIN
        $affected = self::$db->find()
            ->table('UPDATE_DELETE_JOIN_USERS')
            ->join('UPDATE_DELETE_JOIN_ORDERS', 'UPDATE_DELETE_JOIN_ORDERS.USER_ID = UPDATE_DELETE_JOIN_USERS.ID')
            ->where('UPDATE_DELETE_JOIN_ORDERS.STATUS', 'cancelled')
            ->delete();

        $this->assertGreaterThanOrEqual(1, $affected);

        // Verify deletion
        $user1 = self::$db->find()->from('UPDATE_DELETE_JOIN_USERS')->where('id', $userId1)->getOne();
        $this->assertFalse($user1);

        $user2 = self::$db->find()->from('UPDATE_DELETE_JOIN_USERS')->where('id', $userId2)->getOne();
        $this->assertNotNull($user2);
    }
}
