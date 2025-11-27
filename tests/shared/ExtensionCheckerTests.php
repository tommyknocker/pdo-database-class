<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\connection\ExtensionChecker;

/**
 * Tests for ExtensionChecker.
 */
class ExtensionCheckerTests extends TestCase
{
    /**
     * Test isAvailable with SQLite (should always be available).
     */
    public function testIsAvailableWithSqlite(): void
    {
        $this->assertTrue(ExtensionChecker::isAvailable('sqlite'));
    }

    /**
     * Test isAvailable with invalid driver.
     */
    public function testIsAvailableWithInvalidDriver(): void
    {
        $this->assertFalse(ExtensionChecker::isAvailable('invalid_driver'));
    }

    /**
     * Test getRequiredExtension.
     */
    public function testGetRequiredExtension(): void
    {
        $this->assertEquals('pdo_mysql', ExtensionChecker::getRequiredExtension('mysql'));
        $this->assertEquals('pdo_mysql', ExtensionChecker::getRequiredExtension('mariadb'));
        $this->assertEquals('pdo_pgsql', ExtensionChecker::getRequiredExtension('pgsql'));
        $this->assertEquals('pdo_sqlite', ExtensionChecker::getRequiredExtension('sqlite'));
        $this->assertEquals('pdo_sqlsrv', ExtensionChecker::getRequiredExtension('sqlsrv'));
        $this->assertEquals('pdo_oci', ExtensionChecker::getRequiredExtension('oci'));
        $this->assertNull(ExtensionChecker::getRequiredExtension('invalid_driver'));
    }

    /**
     * Test validate with SQLite (should always pass).
     */
    public function testValidateWithSqlite(): void
    {
        // Should not throw exception
        ExtensionChecker::validate('sqlite');
        $this->assertTrue(true);
    }

    /**
     * Test validate with invalid driver.
     */
    public function testValidateWithInvalidDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver: invalid_driver');

        ExtensionChecker::validate('invalid_driver');
    }

    /**
     * Test validate with unavailable extension.
     */
    public function testValidateWithUnavailableExtension(): void
    {
        // This test assumes pdo_oci is not installed (common case)
        // If it is installed, the test will pass, which is also fine
        if (!extension_loaded('pdo_oci')) {
            try {
                ExtensionChecker::validate('oci');
                $this->fail('Expected InvalidArgumentException was not thrown');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('pdo_oci', $e->getMessage());
                $this->assertStringContainsString('required', $e->getMessage());
                $this->assertStringContainsString('not installed', $e->getMessage());
            }
        } else {
            // If extension is loaded, validation should pass
            ExtensionChecker::validate('oci');
            $this->assertTrue(true);
        }
    }

    /**
     * Test getAvailableDrivers.
     */
    public function testGetAvailableDrivers(): void
    {
        $available = ExtensionChecker::getAvailableDrivers();
        $this->assertIsArray($available);
        $this->assertContains('sqlite', $available); // SQLite should always be available
    }

    /**
     * Test getSupportedDrivers.
     */
    public function testGetSupportedDrivers(): void
    {
        $supported = ExtensionChecker::getSupportedDrivers();
        $this->assertIsArray($supported);
        $this->assertContains('mysql', $supported);
        $this->assertContains('mariadb', $supported);
        $this->assertContains('pgsql', $supported);
        $this->assertContains('sqlite', $supported);
        $this->assertContains('sqlsrv', $supported);
        $this->assertContains('oci', $supported);
    }

    /**
     * Test case-insensitive driver names.
     */
    public function testCaseInsensitiveDriverNames(): void
    {
        $this->assertEquals('pdo_mysql', ExtensionChecker::getRequiredExtension('MYSQL'));
        $this->assertEquals('pdo_mysql', ExtensionChecker::getRequiredExtension('MySQL'));
        $this->assertTrue(ExtensionChecker::isAvailable('SQLITE'));
        $this->assertTrue(ExtensionChecker::isAvailable('Sqlite'));
    }
}

