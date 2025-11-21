<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\postgresql;

use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * PostgreSQL-specific DDL Query Builder.
 *
 * Provides PostgreSQL-specific column types and features like UUID, JSONB,
 * SERIAL, INET, arrays, etc.
 */
class PostgreSQLDdlQueryBuilder extends DdlQueryBuilder
{
    /**
     * Create UUID column schema.
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->uuid()->defaultExpression('gen_random_uuid()')
     */
    public function uuid(): ColumnSchema
    {
        return new ColumnSchema('UUID');
    }

    /**
     * Create JSONB column schema.
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->jsonb() // Binary JSON storage
     */
    public function jsonb(): ColumnSchema
    {
        return new ColumnSchema('JSONB');
    }

    /**
     * Create SERIAL column schema (auto-incrementing integer).
     *
     * @return ColumnSchema
     */
    public function serial(): ColumnSchema
    {
        return new ColumnSchema('SERIAL');
    }

    /**
     * Create BIGSERIAL column schema (auto-incrementing big integer).
     *
     * @return ColumnSchema
     */
    public function bigSerial(): ColumnSchema
    {
        return new ColumnSchema('BIGSERIAL');
    }

    /**
     * Create SMALLSERIAL column schema (auto-incrementing small integer).
     *
     * @return ColumnSchema
     */
    public function smallSerial(): ColumnSchema
    {
        return new ColumnSchema('SMALLSERIAL');
    }

    /**
     * Create INET column schema (IPv4/IPv6 addresses).
     *
     * @return ColumnSchema
     */
    public function inet(): ColumnSchema
    {
        return new ColumnSchema('INET');
    }

    /**
     * Create CIDR column schema (network addresses).
     *
     * @return ColumnSchema
     */
    public function cidr(): ColumnSchema
    {
        return new ColumnSchema('CIDR');
    }

    /**
     * Create MACADDR column schema (MAC addresses).
     *
     * @return ColumnSchema
     */
    public function macaddr(): ColumnSchema
    {
        return new ColumnSchema('MACADDR');
    }

    /**
     * Create MACADDR8 column schema (MAC addresses in EUI-64 format).
     *
     * @return ColumnSchema
     */
    public function macaddr8(): ColumnSchema
    {
        return new ColumnSchema('MACADDR8');
    }

    /**
     * Create TSVECTOR column schema (text search vector).
     *
     * @return ColumnSchema
     */
    public function tsvector(): ColumnSchema
    {
        return new ColumnSchema('TSVECTOR');
    }

    /**
     * Create TSQUERY column schema (text search query).
     *
     * @return ColumnSchema
     */
    public function tsquery(): ColumnSchema
    {
        return new ColumnSchema('TSQUERY');
    }

    /**
     * Create BYTEA column schema (binary data).
     *
     * @return ColumnSchema
     */
    public function bytea(): ColumnSchema
    {
        return new ColumnSchema('BYTEA');
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
     * Create INTERVAL column schema.
     *
     * @return ColumnSchema
     */
    public function interval(): ColumnSchema
    {
        return new ColumnSchema('INTERVAL');
    }

    /**
     * Create ARRAY column schema.
     *
     * @param string $baseType Base type for array (e.g., 'INTEGER', 'TEXT')
     * @param int|null $dimensions Number of dimensions
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->array('INTEGER') // INTEGER[]
     * $schema->array('TEXT', 2) // TEXT[][]
     */
    public function array(string $baseType, ?int $dimensions = null): ColumnSchema
    {
        $arrayType = $baseType . str_repeat('[]', $dimensions ?? 1);
        return new ColumnSchema($arrayType);
    }

    /**
     * Create POINT column schema (geometric point).
     *
     * @return ColumnSchema
     */
    public function point(): ColumnSchema
    {
        return new ColumnSchema('POINT');
    }

    /**
     * Create LINE column schema (geometric line).
     *
     * @return ColumnSchema
     */
    public function line(): ColumnSchema
    {
        return new ColumnSchema('LINE');
    }

    /**
     * Create LSEG column schema (line segment).
     *
     * @return ColumnSchema
     */
    public function lseg(): ColumnSchema
    {
        return new ColumnSchema('LSEG');
    }

    /**
     * Create BOX column schema (rectangular box).
     *
     * @return ColumnSchema
     */
    public function box(): ColumnSchema
    {
        return new ColumnSchema('BOX');
    }

    /**
     * Create PATH column schema (geometric path).
     *
     * @return ColumnSchema
     */
    public function path(): ColumnSchema
    {
        return new ColumnSchema('PATH');
    }

    /**
     * Create POLYGON column schema (geometric polygon).
     *
     * @return ColumnSchema
     */
    public function polygon(): ColumnSchema
    {
        return new ColumnSchema('POLYGON');
    }

    /**
     * Create CIRCLE column schema (geometric circle).
     *
     * @return ColumnSchema
     */
    public function circle(): ColumnSchema
    {
        return new ColumnSchema('CIRCLE');
    }

    /**
     * Override tinyInteger for PostgreSQL (uses SMALLINT).
     *
     * @param int|null $length Integer length (ignored in PostgreSQL)
     *
     * @return ColumnSchema
     */
    public function tinyInteger(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('SMALLINT'); // PostgreSQL doesn't have TINYINT
    }

    /**
     * Override boolean for PostgreSQL native boolean.
     *
     * @return ColumnSchema
     */
    public function boolean(): ColumnSchema
    {
        return new ColumnSchema('BOOLEAN');
    }

    /**
     * Override longText for PostgreSQL (uses TEXT).
     *
     * @return ColumnSchema
     */
    public function longText(): ColumnSchema
    {
        return new ColumnSchema('TEXT'); // PostgreSQL TEXT has no size limit
    }
}
