<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\cli\UserManager;

/**
 * UserManager tests for MariaDB.
 *
 * These tests require CREATE USER and DROP USER privileges.
 * Tests will be skipped if permissions are insufficient.
 */
final class UserManagerTests extends BaseMariaDBTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Clean up test user if exists
        try {
            if (UserManager::exists('test_user_manager', '%', static::$db)) {
                UserManager::drop('test_user_manager', '%', static::$db);
            }
        } catch (\Throwable $e) {
            // Ignore errors in setUp - just try to clean up
        }
    }

    /**
     * Check if we have sufficient privileges for user management.
     * If not, skip all tests in this class.
     */
    protected function checkPrivileges(): void
    {
        try {
            // Try to list users - this requires minimal privileges
            UserManager::list(static::$db);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }
        }
    }

    public function tearDown(): void
    {
        // Clean up test user
        try {
            if (UserManager::exists('test_user_manager', '%', static::$db)) {
                UserManager::drop('test_user_manager', '%', static::$db);
            }
        } catch (\Throwable $e) {
            // Ignore all errors in tearDown - just try to clean up
        }
        parent::tearDown();
    }

    public function testCreateUser(): void
    {
        $this->checkPrivileges();

        try {
            $result = UserManager::create('test_user_manager', 'testpass123', '%', static::$db);
            $this->assertTrue($result);
            $this->assertTrue(UserManager::exists('test_user_manager', '%', static::$db));
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }

    public function testUserExists(): void
    {
        $this->checkPrivileges();

        try {
            // Create user first
            UserManager::create('test_user_manager', 'testpass123', '%', static::$db);
            $this->assertTrue(UserManager::exists('test_user_manager', '%', static::$db));
            $this->assertFalse(UserManager::exists('non_existent_user', '%', static::$db));
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }

    public function testListUsers(): void
    {
        $this->checkPrivileges();

        try {
            $users = UserManager::list(static::$db);
            $this->assertIsArray($users);
            $this->assertNotEmpty($users);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }

    public function testGetUserInfo(): void
    {
        $this->checkPrivileges();

        try {
            // Create user first
            UserManager::create('test_user_manager', 'testpass123', '%', static::$db);
            $info = UserManager::getInfo('test_user_manager', '%', static::$db);
            $this->assertIsArray($info);
            $this->assertArrayHasKey('username', $info);
            $this->assertEquals('test_user_manager', $info['username']);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }

    public function testGrantPrivileges(): void
    {
        $this->checkPrivileges();

        try {
            // Create user first
            UserManager::create('test_user_manager', 'testpass123', '%', static::$db);
            $result = UserManager::grant('test_user_manager', 'SELECT', static::DB_NAME, null, '%', static::$db);
            $this->assertTrue($result);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }

    public function testRevokePrivileges(): void
    {
        $this->checkPrivileges();

        try {
            // Create user and grant first
            UserManager::create('test_user_manager', 'testpass123', '%', static::$db);
            UserManager::grant('test_user_manager', 'SELECT', static::DB_NAME, null, '%', static::$db);
            $result = UserManager::revoke('test_user_manager', 'SELECT', static::DB_NAME, null, '%', static::$db);
            $this->assertTrue($result);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }

    public function testChangeUserPassword(): void
    {
        $this->checkPrivileges();

        try {
            // Create user first
            UserManager::create('test_user_manager', 'testpass123', '%', static::$db);
            $result = UserManager::changePassword('test_user_manager', 'newpass456', '%', static::$db);
            $this->assertTrue($result);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }

    public function testDropUser(): void
    {
        $this->checkPrivileges();

        try {
            // Create user first
            UserManager::create('test_user_manager', 'testpass123', '%', static::$db);
            $result = UserManager::drop('test_user_manager', '%', static::$db);
            $this->assertTrue($result);
            $this->assertFalse(UserManager::exists('test_user_manager', '%', static::$db));
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') || str_contains($message, 'privilege') || str_contains($message, 'denied')) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }
}
