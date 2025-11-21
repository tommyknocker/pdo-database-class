<?php

declare(strict_types=1);

namespace tests\mariadb;

use tommyknocker\pdodb\tests\mariadb\BaseMariaDBTestCase;

/**
 * Tests for MariaDB-specific schema builder features.
 * These tests run only when MariaDB is available and test MariaDB-specific types.
 *
 * MariaDB inherits most MySQL features, so we test compatibility
 * and any MariaDB-specific extensions.
 */
class DialectSpecificSchemaTests extends BaseMariaDBTestCase
{
    public function testMariaDBInheritsMySQLTypes(): void
    {
        $schema = static::$db->schema();

        // Test that MariaDB inherits MySQL ENUM
        $enumColumn = $schema->enum(['active', 'inactive', 'pending']);
        static::assertSame("ENUM('active','inactive','pending')", $enumColumn->getType());

        // Test that MariaDB inherits MySQL SET
        $setColumn = $schema->set(['read', 'write', 'admin']);
        static::assertSame("SET('read','write','admin')", $setColumn->getType());
    }

    public function testMariaDBMySQLCompatibility(): void
    {
        $schema = static::$db->schema();

        // Test MySQL-compatible integer types
        static::assertSame('TINYINT', $schema->tinyInteger()->getType());
        static::assertSame('MEDIUMINT', $schema->mediumInteger()->getType());

        // Test MySQL-compatible text types
        static::assertSame('TINYTEXT', $schema->tinyText()->getType());
        static::assertSame('MEDIUMTEXT', $schema->mediumText()->getType());
        static::assertSame('LONGTEXT', $schema->longText()->getType());

        // Test MySQL-compatible binary types
        static::assertSame('BINARY', $schema->binary()->getType());
        static::assertSame('VARBINARY', $schema->varbinary()->getType());
        static::assertSame('TINYBLOB', $schema->tinyBlob()->getType());
        static::assertSame('MEDIUMBLOB', $schema->mediumBlob()->getType());
        static::assertSame('LONGBLOB', $schema->longBlob()->getType());
    }

    public function testMariaDBOptimizedTypes(): void
    {
        $schema = static::$db->schema();

        // Test MariaDB-optimized UUID (inherited from MySQL - CHAR(36))
        $uuidColumn = $schema->uuid();
        static::assertSame('CHAR', $uuidColumn->getType());
        static::assertSame(36, $uuidColumn->getLength());

        // Test MariaDB-optimized boolean (inherited from MySQL - TINYINT(1))
        $boolColumn = $schema->boolean();
        static::assertSame('TINYINT', $boolColumn->getType());
        static::assertSame(1, $boolColumn->getLength());
    }

    public function testMariaDBSpatialTypes(): void
    {
        $schema = static::$db->schema();

        // Test spatial types (inherited from MySQL)
        static::assertSame('GEOMETRY', $schema->geometry()->getType());
        static::assertSame('POINT', $schema->point()->getType());
        static::assertSame('LINESTRING', $schema->lineString()->getType());
        static::assertSame('POLYGON', $schema->polygon()->getType());
    }

    public function testMariaDBTableCreationWithInheritedTypes(): void
    {
        $tableName = 'mariadb_compatibility_test';

        // Drop table if exists
        try {
            static::$db->schema()->dropTable($tableName);
        } catch (\Throwable) {
            // Ignore if table doesn't exist
        }

        // Create table with MySQL-compatible types in MariaDB
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
