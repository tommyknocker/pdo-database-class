<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use ReflectionClass;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;

/**
 * DatabaseManager SQL syntax tests for PostgreSQL.
 *
 * Tests only SQL syntax generation, not actual database operations.
 */
final class DatabaseManagerTests extends BasePostgreSQLTestCase
{
    public function testBuildCreateDatabaseSql(): void
    {
        $dialect = new PostgreSQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('buildCreateDatabaseSql');
        $method->setAccessible(true);

        $sql = $method->invoke($dialect, 'test_db');
        $this->assertStringContainsString('CREATE DATABASE', $sql);
        $this->assertStringContainsString('test_db', $sql);
    }

    public function testBuildDropDatabaseSql(): void
    {
        $dialect = new PostgreSQLDialect();
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
        $dialect = new PostgreSQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('buildListDatabasesSql');
        $method->setAccessible(true);

        $sql = $method->invoke($dialect);
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('pg_database', $sql);
        $this->assertStringContainsString('datname', $sql);
    }

    public function testExtractDatabaseNames(): void
    {
        $dialect = new PostgreSQLDialect();
        $reflection = new ReflectionClass($dialect);
        $method = $reflection->getMethod('extractDatabaseNames');
        $method->setAccessible(true);

        $result = [
            ['datname' => 'test_db1'],
            ['datname' => 'test_db2'],
        ];

        $names = $method->invoke($dialect, $result);
        $this->assertIsArray($names);
        $this->assertContains('test_db1', $names);
        $this->assertContains('test_db2', $names);
    }
}
