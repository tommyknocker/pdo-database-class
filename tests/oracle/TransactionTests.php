<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * TransactionTests tests for Oracle.
 */
final class TransactionTests extends BaseOracleTestCase
{
    public function testTransaction(): void
    {
        $db = self::$db;

        // First transaction: insert and rollback
        $db->startTransaction();
        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $db->rollback();

        $exists = $db->find()
            ->from('users')
            ->where('name', 'Alice')
            ->exists();
        $this->assertFalse($exists, 'Rolled back insert should not exist');

        // Second transaction: insert and commit
        $db->startTransaction();
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 40]);
        $db->commit();

        $exists = $db->find()
            ->from('users')
            ->where('name', 'Bob')
            ->exists();
        $this->assertTrue($exists, 'Committed insert should exist');
    }
}

