<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\oracle;

use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Oracle-specific DDL Query Builder.
 *
 * Provides Oracle-specific column types and features like NUMBER, VARCHAR2,
 * CLOB, BLOB, TIMESTAMP, etc.
 */
class OracleDdlQueryBuilder extends DdlQueryBuilder
{
    /**
     * Create NUMBER column schema.
     *
     * @param int|null $precision Precision (total digits)
     * @param int|null $scale Scale (decimal places)
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->number(10, 2) // NUMBER(10,2)
     */
    public function number(?int $precision = null, ?int $scale = null): ColumnSchema
    {
        $type = 'NUMBER';
        if ($precision !== null) {
            if ($scale !== null) {
                $type .= '(' . $precision . ',' . $scale . ')';
            } else {
                $type .= '(' . $precision . ')';
            }
        }
        return new ColumnSchema($type);
    }

    /**
     * Create VARCHAR2 column schema.
     *
     * @param int $length Maximum length
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->varchar2(255) // VARCHAR2(255)
     */
    public function varchar2(int $length): ColumnSchema
    {
        return new ColumnSchema('VARCHAR2(' . $length . ')');
    }

    /**
     * Create NVARCHAR2 column schema (Unicode).
     *
     * @param int $length Maximum length
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->nvarchar2(255) // NVARCHAR2(255)
     */
    public function nvarchar2(int $length): ColumnSchema
    {
        return new ColumnSchema('NVARCHAR2(' . $length . ')');
    }

    /**
     * Create CLOB column schema (Character Large Object).
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->clob() // CLOB
     */
    public function clob(): ColumnSchema
    {
        return new ColumnSchema('CLOB');
    }

    /**
     * Create BLOB column schema (Binary Large Object).
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->blob() // BLOB
     */
    public function blob(): ColumnSchema
    {
        return new ColumnSchema('BLOB');
    }

    /**
     * Create TIMESTAMP column schema.
     *
     * @param int|null $precision Fractional seconds precision (0-9)
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->timestamp(6) // TIMESTAMP(6)
     */
    public function timestamp(?int $precision = null): ColumnSchema
    {
        $type = 'TIMESTAMP';
        if ($precision !== null) {
            $type .= '(' . $precision . ')';
        }
        return new ColumnSchema($type);
    }

    /**
     * Create TIMESTAMP WITH TIME ZONE column schema.
     *
     * @param int|null $precision Fractional seconds precision (0-9)
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->timestampTz(6) // TIMESTAMP(6) WITH TIME ZONE
     */
    public function timestampTz(?int $precision = null): ColumnSchema
    {
        $type = 'TIMESTAMP';
        if ($precision !== null) {
            $type .= '(' . $precision . ')';
        }
        $type .= ' WITH TIME ZONE';
        return new ColumnSchema($type);
    }

    /**
     * Create DATE column schema.
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->date() // DATE
     */
    public function date(): ColumnSchema
    {
        return new ColumnSchema('DATE');
    }

    /**
     * Create TIME column schema.
     * Oracle doesn't have TIME type, use TIMESTAMP instead.
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->time() // TIMESTAMP
     */
    public function time(): ColumnSchema
    {
        // Oracle doesn't support TIME type, use TIMESTAMP instead
        return new ColumnSchema('TIMESTAMP');
    }

    /**
     * Create UUID column schema (RAW(16) in Oracle).
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->uuid() // RAW(16)
     */
    public function uuid(): ColumnSchema
    {
        return new ColumnSchema('RAW', 16);
    }

    /**
     * Create JSON column schema (VARCHAR2(4000) IS JSON in Oracle 12c+).
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->json() // VARCHAR2(4000) IS JSON
     */
    public function json(): ColumnSchema
    {
        // Oracle 12c+ supports JSON data type, often stored as VARCHAR2 or CLOB with IS JSON constraint
        // For simplicity, we'll define it as VARCHAR2(4000) with a check constraint.
        // Actual JSON type (introduced in 21c) would be 'JSON'
        return new ColumnSchema('VARCHAR2', 4000)->defaultExpression('NULL')->comment('IS JSON');
    }

    /**
     * Create NCLOB column schema (National Character Large Object - Unicode).
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->nclob() // NCLOB
     */
    public function nclob(): ColumnSchema
    {
        return new ColumnSchema('NCLOB');
    }

    /**
     * Create XMLTYPE column schema.
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->xmltype() // XMLTYPE
     */
    public function xmltype(): ColumnSchema
    {
        return new ColumnSchema('XMLTYPE');
    }
}
