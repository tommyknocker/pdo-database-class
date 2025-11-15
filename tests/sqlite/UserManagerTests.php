<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\cli\UserManager;
use tommyknocker\pdodb\exceptions\ResourceException;

/**
 * UserManager tests for SQLite.
 *
 * SQLite does not support user management, so all operations should throw ResourceException.
 */
final class UserManagerTests extends BaseSqliteTestCase
{
    public function testCreateUserThrowsException(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('not supported');
        UserManager::create('test_user', 'password', null, static::$db);
    }

    public function testDropUserThrowsException(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('not supported');
        UserManager::drop('test_user', null, static::$db);
    }

    public function testUserExistsThrowsException(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('not supported');
        UserManager::exists('test_user', null, static::$db);
    }

    public function testListUsersThrowsException(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('not supported');
        UserManager::list(static::$db);
    }

    public function testGetUserInfoThrowsException(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('not supported');
        UserManager::getInfo('test_user', null, static::$db);
    }

    public function testGrantPrivilegesThrowsException(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('not supported');
        UserManager::grant('test_user', 'SELECT', null, null, null, static::$db);
    }

    public function testRevokePrivilegesThrowsException(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('not supported');
        UserManager::revoke('test_user', 'SELECT', null, null, null, static::$db);
    }

    public function testChangeUserPasswordThrowsException(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('not supported');
        UserManager::changePassword('test_user', 'newpass', null, static::$db);
    }
}
