<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * MSSQL-specific DDL Query Builder.
 *
 * Provides MSSQL-specific column types and features like UNIQUEIDENTIFIER,
 * NVARCHAR, NTEXT, MONEY, IMAGE, etc.
 */
class MSSQLDdlQueryBuilder extends DdlQueryBuilder
{
    /**
     * Create UNIQUEIDENTIFIER column schema (UUID).
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->uniqueidentifier()->defaultExpression('NEWID()')
     */
    public function uniqueidentifier(): ColumnSchema
    {
        return new ColumnSchema('UNIQUEIDENTIFIER');
    }

    /**
     * Create NVARCHAR column schema (Unicode variable-length string).
     *
     * @param int|null $length String length (default: MAX)
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->nvarchar(255) // NVARCHAR(255)
     * $schema->nvarchar() // NVARCHAR(MAX)
     */
    public function nvarchar(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('NVARCHAR', $length);
    }

    /**
     * Create NCHAR column schema (Unicode fixed-length string).
     *
     * @param int|null $length String length
     *
     * @return ColumnSchema
     */
    public function nchar(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('NCHAR', $length);
    }

    /**
     * Create NTEXT column schema (Unicode text).
     *
     * @return ColumnSchema
     */
    public function ntext(): ColumnSchema
    {
        return new ColumnSchema('NTEXT');
    }

    /**
     * Create IMAGE column schema (binary data).
     *
     * @return ColumnSchema
     */
    public function image(): ColumnSchema
    {
        return new ColumnSchema('IMAGE');
    }

    /**
     * Create MONEY column schema.
     *
     * @return ColumnSchema
     */
    public function money(): ColumnSchema
    {
        return new ColumnSchema('MONEY');
    }

    /**
     * Create SMALLMONEY column schema.
     *
     * @return ColumnSchema
     */
    public function smallMoney(): ColumnSchema
    {
        return new ColumnSchema('SMALLMONEY');
    }

    /**
     * Create REAL column schema (single precision float).
     *
     * @return ColumnSchema
     */
    public function real(): ColumnSchema
    {
        return new ColumnSchema('REAL');
    }

    /**
     * Create DATETIME2 column schema (enhanced datetime).
     *
     * @param int|null $precision Fractional seconds precision (0-7)
     *
     * @return ColumnSchema
     */
    public function datetime2(?int $precision = null): ColumnSchema
    {
        return new ColumnSchema('DATETIME2', $precision);
    }

    /**
     * Create SMALLDATETIME column schema.
     *
     * @return ColumnSchema
     */
    public function smallDatetime(): ColumnSchema
    {
        return new ColumnSchema('SMALLDATETIME');
    }

    /**
     * Create DATETIMEOFFSET column schema (datetime with timezone).
     *
     * @param int|null $precision Fractional seconds precision (0-7)
     *
     * @return ColumnSchema
     */
    public function datetimeOffset(?int $precision = null): ColumnSchema
    {
        return new ColumnSchema('DATETIMEOFFSET', $precision);
    }

    /**
     * Create TIME column schema.
     *
     * @param int|null $precision Fractional seconds precision (0-7)
     *
     * @return ColumnSchema
     */
    public function time(?int $precision = null): ColumnSchema
    {
        return new ColumnSchema('TIME', $precision);
    }

    /**
     * Create BINARY column schema.
     *
     * @param int|null $length Binary length
     *
     * @return ColumnSchema
     */
    public function binary(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('BINARY', $length);
    }

    /**
     * Create VARBINARY column schema.
     *
     * @param int|null $length Binary length (default: MAX)
     *
     * @return ColumnSchema
     */
    public function varbinary(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('VARBINARY', $length);
    }

    /**
     * Create XML column schema.
     *
     * @return ColumnSchema
     */
    public function xml(): ColumnSchema
    {
        return new ColumnSchema('XML');
    }

    /**
     * Create GEOGRAPHY column schema (spatial data).
     *
     * @return ColumnSchema
     */
    public function geography(): ColumnSchema
    {
        return new ColumnSchema('GEOGRAPHY');
    }

    /**
     * Create GEOMETRY column schema (spatial data).
     *
     * @return ColumnSchema
     */
    public function geometry(): ColumnSchema
    {
        return new ColumnSchema('GEOMETRY');
    }

    /**
     * Create HIERARCHYID column schema.
     *
     * @return ColumnSchema
     */
    public function hierarchyid(): ColumnSchema
    {
        return new ColumnSchema('HIERARCHYID');
    }

    /**
     * Create SQL_VARIANT column schema.
     *
     * @return ColumnSchema
     */
    public function sqlVariant(): ColumnSchema
    {
        return new ColumnSchema('SQL_VARIANT');
    }

    /**
     * Override UUID for MSSQL (uses UNIQUEIDENTIFIER).
     *
     * @return ColumnSchema
     */
    public function uuid(): ColumnSchema
    {
        return $this->uniqueidentifier();
    }

    /**
     * Override tinyInteger for MSSQL (TINYINT is 0-255).
     *
     * @param int|null $length Integer length (ignored in MSSQL)
     *
     * @return ColumnSchema
     */
    public function tinyInteger(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('TINYINT'); // MSSQL TINYINT is 0-255
    }

    /**
     * Override boolean for MSSQL (uses BIT).
     *
     * @return ColumnSchema
     */
    public function boolean(): ColumnSchema
    {
        return new ColumnSchema('BIT');
    }

    /**
     * Override longText for MSSQL (uses NTEXT or NVARCHAR(MAX)).
     *
     * @return ColumnSchema
     */
    public function longText(): ColumnSchema
    {
        return new ColumnSchema('NVARCHAR'); // NVARCHAR(MAX)
    }

    /**
     * Override string for MSSQL (uses NVARCHAR for Unicode support).
     *
     * @param int|null $length String length
     *
     * @return ColumnSchema
     */
    public function string(?int $length = null): ColumnSchema
    {
        return $this->nvarchar($length);
    }

    /**
     * Override text for MSSQL (uses NTEXT).
     *
     * @return ColumnSchema
     */
    public function text(): ColumnSchema
    {
        return $this->nvarchar(); // NVARCHAR(MAX) instead of deprecated NTEXT
    }
}
