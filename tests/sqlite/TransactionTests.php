<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * TransactionTests tests for sqlite.
 */
final class TransactionTests extends BaseSqliteTestCase
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
