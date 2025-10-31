<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * LockTests tests for postgresql.
 */
final class LockTests extends BasePostgreSQLTestCase
{
    public function testLockUnlock(): void
    {
    $db = self::$db;
    $db->startTransaction();
    $ok = self::$db->lock('users');
    $this->assertTrue($ok);
    
    $ok = self::$db->unlock();
    $this->assertTrue($ok);
    $db->commit();
    }

    public function testLockMultipleTableWrite(): void
    {
    $db = self::$db;
    
    $db->startTransaction();
    $this->assertTrue($db->setLockMethod('WRITE')->lock(['users', 'orders']));
    
    $this->assertSame(
    'LOCK TABLE "users", "orders" IN ACCESS EXCLUSIVE MODE',
    $db->lastQuery
    );
    
    $ok = self::$db->unlock();
    $this->assertTrue($ok);
    $db->commit();
    }
}
