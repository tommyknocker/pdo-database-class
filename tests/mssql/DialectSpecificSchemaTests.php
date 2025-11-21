<?php

declare(strict_types=1);

namespace tests\mssql;

use tommyknocker\pdodb\tests\mssql\BaseMSSQLTestCase;

/**
 * Tests for MSSQL-specific schema builder features.
 * These tests run only when MSSQL is available and test MSSQL-specific types.
 */
class DialectSpecificSchemaTests extends BaseMSSQLTestCase
{
    public function testMSSQLUniqueidentifierType(): void
    {
        $schema = static::$db->schema();

        // Test UNIQUEIDENTIFIER type
        static::assertSame('UNIQUEIDENTIFIER', $schema->uniqueidentifier()->getType());

        // Test UUID alias
        $uuidColumn = $schema->uuid();
        static::assertSame('UNIQUEIDENTIFIER', $uuidColumn->getType());
    }

    public function testMSSQLUnicodeStringTypes(): void
    {
        $schema = static::$db->schema();

        // Test Unicode string types
        static::assertSame('NVARCHAR', $schema->nvarchar()->getType());
        static::assertSame('NVARCHAR', $schema->nvarchar(255)->getType());
        static::assertSame(255, $schema->nvarchar(255)->getLength());

        static::assertSame('NCHAR', $schema->nchar()->getType());
        static::assertSame('NCHAR', $schema->nchar(10)->getType());
        static::assertSame(10, $schema->nchar(10)->getLength());

        static::assertSame('NTEXT', $schema->ntext()->getType());
    }

    public function testMSSQLMoneyTypes(): void
    {
        $schema = static::$db->schema();

        // Test money types
        static::assertSame('MONEY', $schema->money()->getType());
        static::assertSame('SMALLMONEY', $schema->smallMoney()->getType());
    }

    public function testMSSQLDateTimeTypes(): void
    {
        $schema = static::$db->schema();

        // Test enhanced datetime types
        static::assertSame('DATETIME2', $schema->datetime2()->getType());
        static::assertSame('DATETIME2', $schema->datetime2(7)->getType());
        static::assertSame(7, $schema->datetime2(7)->getLength());

        static::assertSame('SMALLDATETIME', $schema->smallDatetime()->getType());

        static::assertSame('DATETIMEOFFSET', $schema->datetimeOffset()->getType());
        static::assertSame('DATETIMEOFFSET', $schema->datetimeOffset(3)->getType());
        static::assertSame(3, $schema->datetimeOffset(3)->getLength());

        static::assertSame('TIME', $schema->time()->getType());
        static::assertSame('TIME', $schema->time(5)->getType());
        static::assertSame(5, $schema->time(5)->getLength());
    }

    public function testMSSQLBinaryTypes(): void
    {
        $schema = static::$db->schema();

        // Test binary types
        static::assertSame('BINARY', $schema->binary()->getType());
        static::assertSame('BINARY', $schema->binary(16)->getType());
        static::assertSame(16, $schema->binary(16)->getLength());

        static::assertSame('VARBINARY', $schema->varbinary()->getType());
        static::assertSame('VARBINARY', $schema->varbinary(255)->getType());
        static::assertSame(255, $schema->varbinary(255)->getLength());

        static::assertSame('IMAGE', $schema->image()->getType());
    }

    public function testMSSQLSpecialTypes(): void
    {
        $schema = static::$db->schema();

        // Test MSSQL-specific types
        static::assertSame('REAL', $schema->real()->getType());
        static::assertSame('XML', $schema->xml()->getType());
        static::assertSame('GEOGRAPHY', $schema->geography()->getType());
        static::assertSame('GEOMETRY', $schema->geometry()->getType());
        static::assertSame('HIERARCHYID', $schema->hierarchyid()->getType());
        static::assertSame('SQL_VARIANT', $schema->sqlVariant()->getType());
    }

    public function testMSSQLTypeOverrides(): void
    {
        $schema = static::$db->schema();

        // Test MSSQL-specific overrides
        // tinyInteger is 0-255 in MSSQL
        static::assertSame('TINYINT', $schema->tinyInteger()->getType());

        // boolean maps to BIT in MSSQL
        static::assertSame('BIT', $schema->boolean()->getType());

        // longText maps to NVARCHAR(MAX) in MSSQL
        static::assertSame('NVARCHAR', $schema->longText()->getType());

        // string maps to NVARCHAR for Unicode support
        static::assertSame('NVARCHAR', $schema->string()->getType());
        static::assertSame('NVARCHAR', $schema->string(100)->getType());
        static::assertSame(100, $schema->string(100)->getLength());

        // text maps to NVARCHAR(MAX) instead of deprecated NTEXT
        static::assertSame('NVARCHAR', $schema->text()->getType());
    }

    public function testMSSQLTableCreationWithSpecificTypes(): void
    {
        $tableName = 'mssql_specific_test';

        // Drop table if exists
        try {
            static::$db->schema()->dropTable($tableName);
        } catch (\Throwable) {
            // Ignore if table doesn't exist
        }

        // Create table with MSSQL-specific types
        static::$db->schema()->createTable($tableName, [
            'id' => static::$db->schema()->primaryKey(),
            'uuid_field' => static::$db->schema()->uniqueidentifier(),
            'name' => static::$db->schema()->nvarchar(255),
            'description' => static::$db->schema()->ntext(),
            'is_active' => static::$db->schema()->boolean()->defaultValue(true),
            'price' => static::$db->schema()->money(),
            'small_price' => static::$db->schema()->smallMoney(),
            'created_at' => static::$db->schema()->datetime2(3),
            'updated_at' => static::$db->schema()->datetimeOffset(),
            'time_only' => static::$db->schema()->time(0),
            'binary_data' => static::$db->schema()->varbinary(255),
            'xml_data' => static::$db->schema()->xml(),
            'location' => static::$db->schema()->geography(),
        ]);

        // Verify table was created successfully
        static::assertTrue(static::$db->schema()->tableExists($tableName));

        // Clean up
        static::$db->schema()->dropTable($tableName);
    }
}
