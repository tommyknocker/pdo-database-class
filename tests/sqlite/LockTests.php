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
 * LockTests tests for sqlite.
 */
final class LockTests extends BaseSqliteTestCase
{
    public function testLockUnlock(): void
    {
    $this->expectException(RuntimeException::class);
    $ok = self::$db->lock('users');
    $this->assertTrue($ok);
    
    $ok = self::$db->unlock();
    $this->assertTrue($ok);
    }

    public function testLockMultipleTableWrite(): void
    {
    $db = self::$db;
    
    $this->expectException(RuntimeException::class);
    $this->assertTrue($db->setLockMethod('WRITE')->lock(['users', 'orders']));
    
    $this->assertSame(
    'LOCK TABLES `users` WRITE, `orders` WRITE',
    $db->lastQuery
    );
    
    $ok = self::$db->unlock();
    $this->assertTrue($ok);
    }
}
