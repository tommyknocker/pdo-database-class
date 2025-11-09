<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use RuntimeException;

/**
 * LockTests tests for MSSQL.
 */
final class LockTests extends BaseMSSQLTestCase
{
    public function testLockThrowsException(): void
    {
        // MSSQL doesn't support LOCK TABLES like MySQL
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Table locking is not supported');

        self::$db->lock('users');
    }

    public function testUnlockThrowsException(): void
    {
        // MSSQL doesn't support UNLOCK TABLES like MySQL
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Table unlocking is not supported');

        self::$db->unlock();
    }
}
