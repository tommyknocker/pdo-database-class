<?php

declare(strict_types=1);

namespace tests\sqlite;

use tommyknocker\pdodb\tests\sqlite\BaseSQLiteTestCase;

/**
 * Tests for SQLite-specific schema builder features.
 * These tests run only when SQLite is available and test SQLite type mapping.
 */
class DialectSpecificSchemaTests extends BaseSQLiteTestCase
{
    public function testSQLiteTypeMapping(): void
    {
        $schema = static::$db->schema();

        // Test that all integer types map to INTEGER in SQLite
        static::assertSame('INTEGER', $schema->tinyInteger()->getType());
        static::assertSame('INTEGER', $schema->smallInteger()->getType());
        static::assertSame('INTEGER', $schema->mediumInteger()->getType());
        static::assertSame('INT', $schema->integer()->getType());
        static::assertSame('INTEGER', $schema->bigInteger()->getType());

        // Boolean maps to INTEGER in SQLite
        static::assertSame('INTEGER', $schema->boolean()->getType());
    }

    public function testSQLiteTextTypes(): void
    {
        $schema = static::$db->schema();

        // Test that all text types map to TEXT in SQLite
        static::assertSame('TEXT', $schema->string()->getType());
        static::assertSame('TEXT', $schema->string(255)->getType());
        static::assertSame('TEXT', $schema->text()->getType());
        static::assertSame('TEXT', $schema->tinyText()->getType());
        static::assertSame('TEXT', $schema->mediumText()->getType());
        static::assertSame('TEXT', $schema->longText()->getType());

        // UUID maps to TEXT in SQLite
        static::assertSame('TEXT', $schema->uuid()->getType());

        // JSON maps to TEXT in SQLite
        static::assertSame('TEXT', $schema->json()->getType());
    }

    public function testSQLiteBinaryTypes(): void
    {
        $schema = static::$db->schema();

        // Test that all binary types map to BLOB in SQLite
        static::assertSame('BLOB', $schema->binary()->getType());
        static::assertSame('BLOB', $schema->binary(16)->getType());
        static::assertSame('BLOB', $schema->varbinary()->getType());
        static::assertSame('BLOB', $schema->varbinary(255)->getType());
        static::assertSame('BLOB', $schema->blob()->getType());
        static::assertSame('BLOB', $schema->tinyBlob()->getType());
        static::assertSame('BLOB', $schema->mediumBlob()->getType());
        static::assertSame('BLOB', $schema->longBlob()->getType());
    }

    public function testSQLiteFloatTypes(): void
    {
        $schema = static::$db->schema();

        // Test that float and decimal map to REAL in SQLite
        static::assertSame('REAL', $schema->float()->getType());
        static::assertSame('REAL', $schema->float(10, 2)->getType());
        static::assertSame('REAL', $schema->decimal()->getType());
        static::assertSame('REAL', $schema->decimal(10, 2)->getType());
    }

    public function testSQLiteDateTimeTypes(): void
    {
        $schema = static::$db->schema();

        // Test that datetime types map to TEXT in SQLite
        static::assertSame('TEXT', $schema->datetime()->getType());
        static::assertSame('TEXT', $schema->timestamp()->getType());
        static::assertSame('TEXT', $schema->date()->getType());
        static::assertSame('TEXT', $schema->time()->getType());
    }

    public function testSQLiteNumericType(): void
    {
        $schema = static::$db->schema();

        // Test SQLite's NUMERIC affinity type
        static::assertSame('NUMERIC', $schema->numeric()->getType());
        static::assertSame('NUMERIC', $schema->numeric(10, 2)->getType());
        static::assertSame(10, $schema->numeric(10, 2)->getLength());
        static::assertSame(2, $schema->numeric(10, 2)->getScale());
    }

    public function testSQLiteTableCreationWithMappedTypes(): void
    {
        $tableName = 'test_sqlite_mapped';

        // Drop table if exists
        try {
            static::$db->schema()->dropTable($tableName);
        } catch (\Throwable) {
            // Ignore if table doesn't exist
        }

        // Create table with various types that get mapped in SQLite
        static::$db->schema()->createTable($tableName, [
            'id' => ['type' => 'INTEGER', 'primary_key' => true, 'auto_increment' => true],
            'tiny_num' => static::$db->schema()->tinyInteger(), // -> INTEGER
            'big_num' => static::$db->schema()->bigInteger(), // -> INTEGER
            'is_active' => static::$db->schema()->boolean(), // -> INTEGER
            'name' => static::$db->schema()->string(255), // -> TEXT
            'description' => static::$db->schema()->longText(), // -> TEXT
            'uuid_field' => static::$db->schema()->uuid(), // -> TEXT
            'price' => static::$db->schema()->decimal(10, 2), // -> REAL
            'weight' => static::$db->schema()->float(), // -> REAL
            'binary_data' => static::$db->schema()->varbinary(255), // -> BLOB
            'large_binary' => static::$db->schema()->longBlob(), // -> BLOB
            'created_at' => static::$db->schema()->datetime(), // -> TEXT
            'updated_at' => static::$db->schema()->timestamp(), // -> TEXT
            'metadata' => static::$db->schema()->json(), // -> TEXT
            'score' => static::$db->schema()->numeric(5, 2), // -> NUMERIC
        ]);

        // Verify table was created successfully
        static::assertTrue(static::$db->schema()->tableExists($tableName));

        // Clean up
        static::$db->schema()->dropTable($tableName);
    }
}
