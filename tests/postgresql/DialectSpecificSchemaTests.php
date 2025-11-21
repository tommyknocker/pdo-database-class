<?php

declare(strict_types=1);

namespace tests\postgresql;

use tommyknocker\pdodb\tests\postgresql\BasePostgreSQLTestCase;

/**
 * Tests for PostgreSQL-specific schema builder features.
 * These tests run only when PostgreSQL is available and test PostgreSQL-specific types.
 */
class DialectSpecificSchemaTests extends BasePostgreSQLTestCase
{
    public function testPostgreSQLUUIDType(): void
    {
        $schema = static::$db->schema();

        // Test native UUID type
        $uuidColumn = $schema->uuid();
        static::assertSame('UUID', $uuidColumn->getType());
    }

    public function testPostgreSQLJSONTypes(): void
    {
        $schema = static::$db->schema();

        // Test JSONB type (binary JSON)
        static::assertSame('JSONB', $schema->jsonb()->getType());

        // Regular JSON should still work
        static::assertSame('JSON', $schema->json()->getType());
    }

    public function testPostgreSQLSerialTypes(): void
    {
        $schema = static::$db->schema();

        // Test serial types
        static::assertSame('SERIAL', $schema->serial()->getType());
        static::assertSame('BIGSERIAL', $schema->bigSerial()->getType());
        static::assertSame('SMALLSERIAL', $schema->smallSerial()->getType());
    }

    public function testPostgreSQLNetworkTypes(): void
    {
        $schema = static::$db->schema();

        // Test network address types
        static::assertSame('INET', $schema->inet()->getType());
        static::assertSame('CIDR', $schema->cidr()->getType());
        static::assertSame('MACADDR', $schema->macaddr()->getType());
        static::assertSame('MACADDR8', $schema->macaddr8()->getType());
    }

    public function testPostgreSQLTextSearchTypes(): void
    {
        $schema = static::$db->schema();

        // Test text search types
        static::assertSame('TSVECTOR', $schema->tsvector()->getType());
        static::assertSame('TSQUERY', $schema->tsquery()->getType());
    }

    public function testPostgreSQLSpecialTypes(): void
    {
        $schema = static::$db->schema();

        // Test PostgreSQL-specific types
        static::assertSame('BYTEA', $schema->bytea()->getType());
        static::assertSame('MONEY', $schema->money()->getType());
        static::assertSame('INTERVAL', $schema->interval()->getType());
    }

    public function testPostgreSQLArrayTypes(): void
    {
        $schema = static::$db->schema();

        // Test array types
        static::assertSame('INTEGER[]', $schema->array('INTEGER')->getType());
        static::assertSame('TEXT[]', $schema->array('TEXT')->getType());
        static::assertSame('TEXT[][]', $schema->array('TEXT', 2)->getType());
    }

    public function testPostgreSQLGeometricTypes(): void
    {
        $schema = static::$db->schema();

        // Test geometric types
        static::assertSame('POINT', $schema->point()->getType());
        static::assertSame('LINE', $schema->line()->getType());
        static::assertSame('LSEG', $schema->lseg()->getType());
        static::assertSame('BOX', $schema->box()->getType());
        static::assertSame('PATH', $schema->path()->getType());
        static::assertSame('POLYGON', $schema->polygon()->getType());
        static::assertSame('CIRCLE', $schema->circle()->getType());
    }

    public function testPostgreSQLTypeOverrides(): void
    {
        $schema = static::$db->schema();

        // Test PostgreSQL-specific overrides
        // tinyInteger maps to SMALLINT in PostgreSQL
        static::assertSame('SMALLINT', $schema->tinyInteger()->getType());

        // boolean is native in PostgreSQL
        static::assertSame('BOOLEAN', $schema->boolean()->getType());

        // longText maps to TEXT in PostgreSQL
        static::assertSame('TEXT', $schema->longText()->getType());
    }

    public function testPostgreSQLTableCreationWithSpecificTypes(): void
    {
        $tableName = 'postgresql_specific_test';

        // Drop table if exists
        try {
            static::$db->schema()->dropTable($tableName);
        } catch (\Throwable) {
            // Ignore if table doesn't exist
        }

        // Create table with PostgreSQL-specific types
        static::$db->schema()->createTable($tableName, [
            'id' => ['type' => 'SERIAL', 'primary_key' => true],
            'uuid_field' => static::$db->schema()->uuid(),
            'is_active' => static::$db->schema()->boolean()->defaultValue(true),
            'metadata' => static::$db->schema()->jsonb(),
            'tags' => static::$db->schema()->array('TEXT'),
            'ip_address' => static::$db->schema()->inet(),
            'mac_address' => static::$db->schema()->macaddr(),
            'search_vector' => static::$db->schema()->tsvector(),
            'binary_data' => static::$db->schema()->bytea(),
            'price' => static::$db->schema()->money(),
            'location' => static::$db->schema()->point(),
            'area' => static::$db->schema()->polygon(),
        ]);

        // Verify table was created successfully
        static::assertTrue(static::$db->schema()->tableExists($tableName));

        // Clean up
        static::$db->schema()->dropTable($tableName);
    }
}
