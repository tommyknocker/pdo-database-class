<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use ReflectionClass;
use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;

/**
 * DatabaseManager SQL syntax tests for MSSQL.
 *
 * Tests only SQL syntax generation, not actual database operations.
 */
final class DatabaseManagerTests extends BaseMSSQLTestCase
{
    public function testBuildCreateDatabaseSql(): void
    {
        $dialect = new MSSQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('buildCreateDatabaseSql');
        $method->setAccessible(true);

        $sql = $method->invoke($dialect, 'test_db');
        $this->assertStringContainsString('CREATE DATABASE', $sql);
        $this->assertStringContainsString('test_db', $sql);
    }

    public function testBuildDropDatabaseSql(): void
    {
        $dialect = new MSSQLDialect();
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
        $dialect = new MSSQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('buildListDatabasesSql');
        $method->setAccessible(true);

        $sql = $method->invoke($dialect);
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('sys.databases', $sql);
        $this->assertStringContainsString('name', $sql);
    }

    public function testExtractDatabaseNames(): void
    {
        $dialect = new MSSQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('extractDatabaseNames');
        $method->setAccessible(true);

        $result = [
            ['name' => 'test_db1'],
            ['name' => 'test_db2'],
        ];

        $names = $method->invoke($dialect, $result);
        $this->assertIsArray($names);
        $this->assertContains('test_db1', $names);
        $this->assertContains('test_db2', $names);
    }
}
