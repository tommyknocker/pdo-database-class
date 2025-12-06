<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\dialects\oracle\OracleDdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Tests for OracleDdlQueryBuilder class.
 */
final class OracleDdlQueryBuilderTests extends BaseOracleTestCase
{
    protected function createBuilder(): OracleDdlQueryBuilder
    {
        return new OracleDdlQueryBuilder(
            self::$db->connection,
            ''
        );
    }

    public function testNumberWithoutPrecision(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->number();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('NUMBER', $column->getType());
    }

    public function testNumberWithPrecision(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->number(10);
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('NUMBER(10)', $column->getType());
    }

    public function testNumberWithPrecisionAndScale(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->number(10, 2);
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('NUMBER(10,2)', $column->getType());
    }

    public function testVarchar2(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->varchar2(255);
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('VARCHAR2(255)', $column->getType());
    }

    public function testNvarchar2(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->nvarchar2(255);
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('NVARCHAR2(255)', $column->getType());
    }

    public function testClob(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->clob();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('CLOB', $column->getType());
    }

    public function testBlob(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->blob();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('BLOB', $column->getType());
    }

    public function testTimestampWithoutPrecision(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->timestamp();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('TIMESTAMP', $column->getType());
    }

    public function testTimestampWithPrecision(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->timestamp(6);
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('TIMESTAMP(6)', $column->getType());
    }

    public function testTimestampTzWithoutPrecision(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->timestampTz();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('TIMESTAMP WITH TIME ZONE', $column->getType());
    }

    public function testTimestampTzWithPrecision(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->timestampTz(6);
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('TIMESTAMP(6) WITH TIME ZONE', $column->getType());
    }

    public function testDate(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->date();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('DATE', $column->getType());
    }

    public function testTime(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->time();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        // Oracle doesn't support TIME type, uses TIMESTAMP instead
        $this->assertEquals('TIMESTAMP', $column->getType());
    }

    public function testUuid(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->uuid();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('RAW', $column->getType());
        $this->assertEquals(16, $column->getLength());
    }

    public function testJson(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->json();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('VARCHAR2', $column->getType());
        $this->assertEquals(4000, $column->getLength());
    }

    public function testNclob(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->nclob();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('NCLOB', $column->getType());
    }

    public function testXmltype(): void
    {
        $builder = $this->createBuilder();
        $column = $builder->xmltype();
        $this->assertInstanceOf(ColumnSchema::class, $column);
        $this->assertEquals('XMLTYPE', $column->getType());
    }
}
