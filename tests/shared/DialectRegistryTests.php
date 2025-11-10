<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use tommyknocker\pdodb\connection\DialectRegistry;
use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * Tests for DialectRegistry class.
 */
final class DialectRegistryTests extends BaseSharedTestCase
{
    protected function tearDown(): void
    {
        // Reset dialect registry to default state
        // This is done by clearing any custom registrations
        parent::tearDown();
    }

    public function testIsSupportedWithValidDriver(): void
    {
        $this->assertTrue(DialectRegistry::isSupported('mysql'));
        $this->assertTrue(DialectRegistry::isSupported('mariadb'));
        $this->assertTrue(DialectRegistry::isSupported('sqlite'));
        $this->assertTrue(DialectRegistry::isSupported('pgsql'));
        $this->assertTrue(DialectRegistry::isSupported('sqlsrv'));
    }

    public function testIsSupportedWithInvalidDriver(): void
    {
        $this->assertFalse(DialectRegistry::isSupported('invalid'));
        $this->assertFalse(DialectRegistry::isSupported('oracle'));
    }

    public function testResolveWithValidDriver(): void
    {
        $dialect = DialectRegistry::resolve('mysql');
        $this->assertInstanceOf(DialectInterface::class, $dialect);
        $this->assertEquals('mysql', $dialect->getDriverName());
    }

    public function testResolveWithInvalidDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver: invalid');
        DialectRegistry::resolve('invalid');
    }

    public function testGetSupportedDrivers(): void
    {
        $drivers = DialectRegistry::getSupportedDrivers();
        $this->assertContains('mysql', $drivers);
        $this->assertContains('mariadb', $drivers);
        $this->assertContains('mssql', $drivers);
        $this->assertContains('sqlite', $drivers);
        $this->assertContains('pgsql', $drivers);
        $this->assertContains('sqlsrv', $drivers);
    }

    public function testRegisterCustomDialect(): void
    {
        // Use an existing dialect class to test registration
        $dialectClass = \tommyknocker\pdodb\dialects\SqliteDialect::class;

        // Register it with a test driver name
        DialectRegistry::register('test_driver', $dialectClass);

        $this->assertTrue(DialectRegistry::isSupported('test_driver'));
        $resolved = DialectRegistry::resolve('test_driver');
        $this->assertInstanceOf($dialectClass, $resolved);
        $this->assertEquals('sqlite', $resolved->getDriverName());
    }

    public function testRegisterWithInvalidClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement DialectInterface');
        DialectRegistry::register('invalid', \stdClass::class);
    }
}
