<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;

/**
 * Tests for TableManagementTrait.
 */
class TableManagementTraitTests extends BaseSharedTestCase
{
    /**
     * Test setTable via DdlQueryBuilder.
     */
    public function testSetTable(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        // Test via DdlQueryBuilder which uses TableManagementTrait
        $reflection = new \ReflectionClass($schema);
        $method = $reflection->getMethod('setTable');
        $method->setAccessible(true);

        $result = $method->invoke($schema, 'test_table');
        $this->assertInstanceOf(\tommyknocker\pdodb\query\DdlQueryBuilder::class, $result);
    }

    /**
     * Test setPrefix via DdlQueryBuilder.
     */
    public function testSetPrefix(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        // Test via DdlQueryBuilder which uses TableManagementTrait
        $reflection = new \ReflectionClass($schema);
        $method = $reflection->getMethod('setPrefix');
        $method->setAccessible(true);

        // Set prefix
        $result = $method->invoke($schema, 'pref_');
        $this->assertInstanceOf(\tommyknocker\pdodb\query\DdlQueryBuilder::class, $result);

        // Set null prefix
        $result = $method->invoke($schema, null);
        $this->assertInstanceOf(\tommyknocker\pdodb\query\DdlQueryBuilder::class, $result);
    }

    /**
     * Test normalizeTable with null table name throws exception.
     */
    public function testNormalizeTableWithNullThrowsException(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        // normalizeTable is protected, test it directly via reflection
        $reflection = new \ReflectionClass($schema);

        // Set prefix to test prefix handling
        $prefixProperty = $reflection->getProperty('prefix');
        $prefixProperty->setAccessible(true);
        $prefixProperty->setValue($schema, 'test_');

        // Set table to null
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableProperty->setValue($schema, null);

        // This should throw RuntimeException when normalizeTable is called with null
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Table name is required');

        // Call normalizeTable directly
        $method = $reflection->getMethod('normalizeTable');
        $method->setAccessible(true);
        $method->invoke($schema, null);
    }

    /**
     * Test normalizeTable with prefix.
     */
    public function testNormalizeTableWithPrefix(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        // Use reflection to set prefix
        $reflection = new \ReflectionClass($schema);
        $method = $reflection->getMethod('setPrefix');
        $method->setAccessible(true);
        $method->invoke($schema, 'test_');

        // normalizeTable is protected, test it directly
        $schema->dropTableIfExists('normalize_prefix');
        $schema->dropTableIfExists('test_normalize_prefix');

        // Test normalizeTable directly first
        $normalizeMethod = $reflection->getMethod('normalizeTable');
        $normalizeMethod->setAccessible(true);
        $normalized = $normalizeMethod->invoke($schema, 'normalize_prefix');
        $this->assertIsString($normalized);
        $this->assertStringContainsString('test_normalize_prefix', $normalized);

        // Create table - prefix should be applied automatically via normalizeTable
        // Note: createTable also applies prefix, so we pass name without prefix
        $schema->createTable('normalize_prefix', [
            'id' => $schema->primaryKey(),
        ]);

        // Verify table was created - tableExists also applies prefix, so pass name without prefix
        $exists = $schema->tableExists('normalize_prefix');
        $this->assertTrue($exists, 'Table should exist with prefix applied');

        // Cleanup - use name without prefix, dropTableIfExists will apply prefix
        $schema->dropTableIfExists('normalize_prefix');
    }

    /**
     * Test setTable fluent interface via DdlQueryBuilder.
     */
    public function testSetTableFluentInterface(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        // Test via DdlQueryBuilder which uses TableManagementTrait
        $reflection = new \ReflectionClass($schema);
        $method = $reflection->getMethod('setTable');
        $method->setAccessible(true);

        // Verify fluent interface
        $result = $method->invoke($schema, 'test_table');
        $this->assertInstanceOf(\tommyknocker\pdodb\query\DdlQueryBuilder::class, $result);
        $this->assertSame($schema, $result);
    }

    /**
     * Test normalizeTable with table name.
     */
    public function testNormalizeTableWithTableName(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $reflection = new \ReflectionClass($schema);

        // Set table
        $setTableMethod = $reflection->getMethod('setTable');
        $setTableMethod->setAccessible(true);
        $setTableMethod->invoke($schema, 'users');

        // Test normalizeTable
        $normalizeMethod = $reflection->getMethod('normalizeTable');
        $normalizeMethod->setAccessible(true);
        $normalized = $normalizeMethod->invoke($schema);

        $this->assertIsString($normalized);
        $this->assertStringContainsString('users', $normalized);
    }
}
