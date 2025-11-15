<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use ReflectionClass;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;

/**
 * DatabaseManager SQL syntax tests for MySQL.
 *
 * Tests only SQL syntax generation, not actual database operations.
 */
final class DatabaseManagerTests extends BaseMySQLTestCase
{
    public function testBuildCreateDatabaseSql(): void
    {
        $dialect = new MySQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('buildCreateDatabaseSql');
        $method->setAccessible(true);

        $sql = $method->invoke($dialect, 'test_db');
        $this->assertStringContainsString('CREATE DATABASE', $sql);
        $this->assertStringContainsString('IF NOT EXISTS', $sql);
        $this->assertStringContainsString('test_db', $sql);
        $this->assertStringContainsString('utf8mb4', $sql);
    }

    public function testBuildDropDatabaseSql(): void
    {
        $dialect = new MySQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('buildDropDatabaseSql');
        $method->setAccessible(true);

        $sql = $method->invoke($dialect, 'test_db');
        $this->assertStringContainsString('DROP DATABASE', $sql);
        $this->assertStringContainsString('IF EXISTS', $sql);
        $this->assertStringContainsString('test_db', $sql);
    }

    public function testBuildListDatabasesSql(): void
    {
        $dialect = new MySQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('buildListDatabasesSql');
        $method->setAccessible(true);

        $sql = $method->invoke($dialect);
        $this->assertEquals('SHOW DATABASES', $sql);
    }

    public function testExtractDatabaseNames(): void
    {
        $dialect = new MySQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('extractDatabaseNames');
        $method->setAccessible(true);

        $result = [
            ['Database' => 'test_db1'],
            ['Database' => 'test_db2'],
        ];

        $names = $method->invoke($dialect, $result);
        $this->assertIsArray($names);
        $this->assertContains('test_db1', $names);
        $this->assertContains('test_db2', $names);
    }
}
