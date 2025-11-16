<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\cli\DatabaseManager;
use tommyknocker\pdodb\exceptions\DatabaseException;

/**
 * DatabaseManager tests for MariaDB.
 *
 * These tests require CREATE DATABASE and DROP DATABASE privileges.
 * Tests will be skipped if permissions are insufficient.
 */
final class DatabaseManagerTests extends BaseMariaDBTestCase
{
    private const string TEST_DB_NAME = 'test_db_manager';

    public function setUp(): void
    {
        parent::setUp();

        // Clean up test database if exists
        try {
            if (DatabaseManager::exists(self::TEST_DB_NAME, static::$db)) {
                DatabaseManager::drop(self::TEST_DB_NAME, static::$db);
            }
        } catch (\Throwable $e) {
            // Ignore errors in setUp - just try to clean up
        }
    }

    /**
     * Check if we have sufficient privileges for database management.
     * If not, skip all tests in this class.
     */
    protected function checkPrivileges(): void
    {
        try {
            // Try to list databases - this requires minimal privileges
            DatabaseManager::list(static::$db);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') ||
                str_contains($message, 'privilege') ||
                str_contains($message, 'denied'
            )) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }
        }
    }

    public function tearDown(): void
    {
        // Clean up test database
        try {
            if (DatabaseManager::exists(self::TEST_DB_NAME, static::$db)) {
                DatabaseManager::drop(self::TEST_DB_NAME, static::$db);
            }
        } catch (\Throwable $e) {
            // Ignore all errors in tearDown - just try to clean up
        }
        parent::tearDown();
    }

    public function testCreateDatabase(): void
    {
        $this->checkPrivileges();

        try {
            $result = DatabaseManager::create(self::TEST_DB_NAME, static::$db);
            $this->assertTrue($result);
            $this->assertTrue(DatabaseManager::exists(self::TEST_DB_NAME, static::$db));
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'Access denied') ||
                str_contains($message, 'privilege') ||
                str_contains($message, 'denied')
            ) {
                $this->markTestSkipped('Insufficient privileges: ' . $message);
            }

            throw $e;
        }
    }

    public function testDatabaseExists(): void
    {
        $this->checkPrivileges();

        try {
            // Create database first
            DatabaseManager::create(self::TEST_DB_NAME, static::$db);
            $this->assertTrue(DatabaseManager::exists(self::TEST_DB_NAME, static::$db));
            $this->assertFalse(DatabaseManager::exists('non_existent_database_xyz', static::$db));
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'Access denied') || str_contains($e->getMessage(), 'privilege')) {
                $this->markTestSkipped('Insufficient privileges: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    public function testListDatabases(): void
    {
        $this->checkPrivileges();

        try {
            $databases = DatabaseManager::list(static::$db);
            $this->assertIsArray($databases);
            // Should have at least one database (the test database or mysql)
            $this->assertNotEmpty($databases);
            // Should contain the current database
            $this->assertContains(static::DB_NAME, $databases);
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'Access denied') || str_contains($e->getMessage(), 'privilege')) {
                $this->markTestSkipped('Insufficient privileges: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    public function testGetDatabaseInfo(): void
    {
        $this->checkPrivileges();

        try {
            $info = DatabaseManager::getInfo(static::$db);
            $this->assertIsArray($info);
            $this->assertArrayHasKey('driver', $info);
            $this->assertEquals('mariadb', $info['driver']);
            // Should have current database info
            if (isset($info['current_database'])) {
                $this->assertNotEmpty($info['current_database']);
            }
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'Access denied') || str_contains($e->getMessage(), 'privilege')) {
                $this->markTestSkipped('Insufficient privileges: ' . $e->getMessage());
            }

            throw $e;
        }
    }

    public function testDropDatabase(): void
    {
        $this->checkPrivileges();

        try {
            // Create database first
            DatabaseManager::create(self::TEST_DB_NAME, static::$db);
            $result = DatabaseManager::drop(self::TEST_DB_NAME, static::$db);
            $this->assertTrue($result);
            $this->assertFalse(DatabaseManager::exists(self::TEST_DB_NAME, static::$db));
        } catch (DatabaseException $e) {
            if (str_contains($e->getMessage(), 'Access denied') || str_contains($e->getMessage(), 'privilege')) {
                $this->markTestSkipped('Insufficient privileges: ' . $e->getMessage());
            }

            throw $e;
        }
    }
}
