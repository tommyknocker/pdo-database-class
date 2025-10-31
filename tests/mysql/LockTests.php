<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

/**
 * LockTests tests for mysql.
 */
final class LockTests extends BaseMySQLTestCase
{
    public function testLockUnlock(): void
    {
        $ok = self::$db->lock('users');
        $this->assertTrue($ok);

        $this->assertSame('LOCK TABLES `users` WRITE', self::$db->lastQuery);

        $ok = self::$db->unlock();
        $this->assertTrue($ok);

        $this->assertSame('UNLOCK TABLES', self::$db->lastQuery);
    }

    public function testLockMultipleTableWrite(): void
    {
        $db = self::$db;

        $this->assertTrue($db->setLockMethod('WRITE')->lock(['users', 'orders']));

        $this->assertSame(
            'LOCK TABLES `users` WRITE, `orders` WRITE',
            $db->lastQuery
        );

        $ok = self::$db->unlock();
        $this->assertTrue($ok);

        $this->assertSame('UNLOCK TABLES', $db->lastQuery);
    }
}
