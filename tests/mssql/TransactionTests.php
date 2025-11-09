<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * TransactionTests tests for MSSQL.
 */
final class TransactionTests extends BaseMSSQLTestCase
{
    public function testTransaction(): void
    {
        $db = self::$db;

        // First transaction: insert and rollback
        $db->startTransaction();
        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $db->rollback();

        $count = $db->find()
            ->from('users')
            ->where('name', 'Alice')
            ->select([Db::count()])
            ->getValue();
        $this->assertEquals(0, $count, 'Rolled back insert should not exist');

        // Second transaction: insert and commit
        $db->startTransaction();
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 40]);
        $db->commit();

        $count = $db->find()
            ->from('users')
            ->where('name', 'Bob')
            ->select([Db::count()])
            ->getValue();
        $this->assertEquals(1, $count, 'Committed insert should exist');
    }

    public function testNestedTransactions(): void
    {
        // MSSQL doesn't support SAVEPOINT, so nested transactions are not supported
        // This test is skipped as savepoints are not available in MSSQL
        $this->markTestSkipped('MSSQL does not support SAVEPOINT for nested transactions');
    }

    public function testTransactionRollbackOnError(): void
    {
        $db = self::$db;

        $db->startTransaction();
        $db->find()->table('users')->insert(['name' => 'BeforeError', 'age' => 25]);

        // Try to insert duplicate (will fail)
        try {
            $db->find()->table('users')->insert(['name' => 'BeforeError', 'age' => 30]);
            $this->fail('Expected exception for duplicate key');
        } catch (\Exception $e) {
            // Expected
        }

        $db->rollback();

        // Verify nothing was committed
        $count = $db->find()
            ->from('users')
            ->where('name', 'BeforeError')
            ->select([Db::count()])
            ->getValue();
        $this->assertEquals(0, $count, 'Rolled back insert should not exist');
    }

    public function testTransactionCommitMultipleOperations(): void
    {
        $db = self::$db;

        $db->startTransaction();
        $id1 = $db->find()->table('users')->insert(['name' => 'User1', 'age' => 20]);
        $id2 = $db->find()->table('users')->insert(['name' => 'User2', 'age' => 25]);
        $db->find()->table('orders')->insert(['user_id' => $id1, 'amount' => 100.00]);
        $db->find()->table('orders')->insert(['user_id' => $id2, 'amount' => 200.00]);
        $db->commit();

        // Verify all operations were committed
        $userCount = $db->find()
            ->from('users')
            ->whereIn('name', ['User1', 'User2'])
            ->select([Db::count()])
            ->getValue();
        $this->assertEquals(2, $userCount, 'Both users should be committed');

        $orderCount = $db->find()
            ->from('orders')
            ->select([Db::count()])
            ->getValue();
        $this->assertEquals(2, $orderCount, 'Both orders should be committed');
    }
}

