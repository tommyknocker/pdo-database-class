<?php

declare(strict_types=1);

namespace tests\mysql;

use tommyknocker\pdodb\tests\mysql\BaseMySQLTestCase;

/**
 * Tests for MySQL-specific schema builder features.
 * These tests run only when MySQL is available and test MySQL-specific types.
 */
class DialectSpecificSchemaTests extends BaseMySQLTestCase
{
    public function testMySQLEnumType(): void
    {
        $schema = static::$db->schema();

        // Test ENUM creation
        $enumColumn = $schema->enum(['active', 'inactive', 'pending']);
        static::assertSame("ENUM('active','inactive','pending')", $enumColumn->getType());

        // Test with special characters (should be escaped)
        $enumColumn = $schema->enum(['O\'Reilly', 'Smith & Co']);
        static::assertSame("ENUM('O\\'Reilly','Smith & Co')", $enumColumn->getType());
    }

    public function testMySQLSetType(): void
    {
        $schema = static::$db->schema();

        // Test SET creation
        $setColumn = $schema->set(['read', 'write', 'admin']);
        static::assertSame("SET('read','write','admin')", $setColumn->getType());
    }

    public function testMySQLIntegerTypes(): void
    {
        $schema = static::$db->schema();

        // Test MySQL-specific integer types
        static::assertSame('TINYINT', $schema->tinyInteger()->getType());
        static::assertSame('TINYINT', $schema->tinyInteger(1)->getType());
        static::assertSame(1, $schema->tinyInteger(1)->getLength());

        static::assertSame('MEDIUMINT', $schema->mediumInteger()->getType());
        static::assertSame('MEDIUMINT', $schema->mediumInteger(8)->getType());
        static::assertSame(8, $schema->mediumInteger(8)->getLength());
    }

    public function testMySQLTextTypes(): void
    {
        $schema = static::$db->schema();

        // Test MySQL-specific text types
        static::assertSame('TINYTEXT', $schema->tinyText()->getType());
        static::assertSame('MEDIUMTEXT', $schema->mediumText()->getType());
        static::assertSame('LONGTEXT', $schema->longText()->getType());
    }

    public function testMySQLBinaryTypes(): void
    {
        $schema = static::$db->schema();

        // Test MySQL binary types
        static::assertSame('BINARY', $schema->binary()->getType());
        static::assertSame('BINARY', $schema->binary(16)->getType());
        static::assertSame(16, $schema->binary(16)->getLength());

        static::assertSame('VARBINARY', $schema->varbinary()->getType());
        static::assertSame('VARBINARY', $schema->varbinary(255)->getType());
        static::assertSame(255, $schema->varbinary(255)->getLength());
    }

    public function testMySQLBlobTypes(): void
    {
        $schema = static::$db->schema();

        // Test MySQL blob types
        static::assertSame('BLOB', $schema->blob()->getType());
        static::assertSame('TINYBLOB', $schema->tinyBlob()->getType());
        static::assertSame('MEDIUMBLOB', $schema->mediumBlob()->getType());
        static::assertSame('LONGBLOB', $schema->longBlob()->getType());
    }

    public function testMySQLSpatialTypes(): void
    {
        $schema = static::$db->schema();

        // Test MySQL spatial types
        static::assertSame('GEOMETRY', $schema->geometry()->getType());
        static::assertSame('POINT', $schema->point()->getType());
        static::assertSame('LINESTRING', $schema->lineString()->getType());
        static::assertSame('POLYGON', $schema->polygon()->getType());
    }

    public function testMySQLYearType(): void
    {
        $schema = static::$db->schema();

        // Test YEAR type
        static::assertSame('YEAR', $schema->year()->getType());
        static::assertSame('YEAR', $schema->year(4)->getType());
        static::assertSame(4, $schema->year(4)->getLength());
    }

    public function testMySQLOptimizedTypes(): void
    {
        $schema = static::$db->schema();

        // Test MySQL-optimized UUID (CHAR(36))
        $uuidColumn = $schema->uuid();
        static::assertSame('CHAR', $uuidColumn->getType());
        static::assertSame(36, $uuidColumn->getLength());

        // Test MySQL-optimized boolean (TINYINT(1))
        $boolColumn = $schema->boolean();
        static::assertSame('TINYINT', $boolColumn->getType());
        static::assertSame(1, $boolColumn->getLength());
    }

    public function testMySQLTableCreationWithSpecificTypes(): void
    {
        $tableName = 'mysql_specific_test';

        // Drop table if exists
        try {
            static::$db->schema()->dropTable($tableName);
        } catch (\Throwable) {
            // Ignore if table doesn't exist
        }

        // Create table with MySQL-specific types
        static::$db->schema()->createTable($tableName, [
            'id' => ['type' => 'INT', 'primary_key' => true, 'auto_increment' => true],
            'status' => static::$db->schema()->enum(['draft', 'published', 'archived']),
            'permissions' => static::$db->schema()->set(['read', 'write', 'delete']),
            'tiny_num' => static::$db->schema()->tinyInteger()->unsigned(),
            'medium_num' => static::$db->schema()->mediumInteger(),
            'uuid_field' => static::$db->schema()->uuid(),
            'is_active' => static::$db->schema()->boolean()->defaultValue(true),
            'description' => static::$db->schema()->mediumText(),
            'binary_data' => static::$db->schema()->varbinary(255),
            'created_year' => static::$db->schema()->year(),
            'location' => static::$db->schema()->point(),
        ]);

        // Verify table was created successfully
        static::assertTrue(static::$db->schema()->tableExists($tableName));

        // Clean up
        static::$db->schema()->dropTable($tableName);
    }
}
