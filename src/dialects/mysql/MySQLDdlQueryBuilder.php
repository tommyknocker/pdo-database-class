<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * MySQL-specific DDL Query Builder.
 *
 * Provides MySQL-specific column types and features like ENUM, SET, TINYINT,
 * MEDIUMINT, LONGTEXT, ZEROFILL, etc.
 */
class MySQLDdlQueryBuilder extends DdlQueryBuilder
{
    /**
     * Create ENUM column schema.
     *
     * @param array<int, string> $values Allowed values
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->enum(['active', 'inactive', 'pending'])
     */
    public function enum(array $values): ColumnSchema
    {
        $escapedValues = array_map(static fn ($value) => "'" . addslashes($value) . "'", $values);
        $valuesList = implode(',', $escapedValues);
        return new ColumnSchema("ENUM($valuesList)");
    }

    /**
     * Create SET column schema.
     *
     * @param array<int, string> $values Allowed values
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->set(['read', 'write', 'admin'])
     */
    public function set(array $values): ColumnSchema
    {
        $escapedValues = array_map(static fn ($value) => "'" . addslashes($value) . "'", $values);
        $valuesList = implode(',', $escapedValues);
        return new ColumnSchema("SET($valuesList)");
    }

    /**
     * Create TINYINT column schema.
     *
     * @param int|null $length Integer length
     *
     * @return ColumnSchema
     *
     * @example
     * $schema->tinyInteger()->unsigned() // TINYINT UNSIGNED (0-255)
     * $schema->tinyInteger(1) // TINYINT(1) for boolean-like values
     */
    public function tinyInteger(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('TINYINT', $length);
    }

    /**
     * Create MEDIUMINT column schema.
     *
     * @param int|null $length Integer length
     *
     * @return ColumnSchema
     */
    public function mediumInteger(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('MEDIUMINT', $length);
    }

    /**
     * Create LONGTEXT column schema.
     *
     * @return ColumnSchema
     */
    public function longText(): ColumnSchema
    {
        return new ColumnSchema('LONGTEXT');
    }

    /**
     * Create MEDIUMTEXT column schema.
     *
     * @return ColumnSchema
     */
    public function mediumText(): ColumnSchema
    {
        return new ColumnSchema('MEDIUMTEXT');
    }

    /**
     * Create TINYTEXT column schema.
     *
     * @return ColumnSchema
     */
    public function tinyText(): ColumnSchema
    {
        return new ColumnSchema('TINYTEXT');
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
     * @param int|null $length Binary length
     *
     * @return ColumnSchema
     */
    public function varbinary(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('VARBINARY', $length);
    }

    /**
     * Create BLOB column schema.
     *
     * @return ColumnSchema
     */
    public function blob(): ColumnSchema
    {
        return new ColumnSchema('BLOB');
    }

    /**
     * Create LONGBLOB column schema.
     *
     * @return ColumnSchema
     */
    public function longBlob(): ColumnSchema
    {
        return new ColumnSchema('LONGBLOB');
    }

    /**
     * Create MEDIUMBLOB column schema.
     *
     * @return ColumnSchema
     */
    public function mediumBlob(): ColumnSchema
    {
        return new ColumnSchema('MEDIUMBLOB');
    }

    /**
     * Create TINYBLOB column schema.
     *
     * @return ColumnSchema
     */
    public function tinyBlob(): ColumnSchema
    {
        return new ColumnSchema('TINYBLOB');
    }

    /**
     * Create YEAR column schema.
     *
     * @param int|null $length Year length (2 or 4)
     *
     * @return ColumnSchema
     */
    public function year(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('YEAR', $length);
    }

    /**
     * Create GEOMETRY column schema.
     *
     * @return ColumnSchema
     */
    public function geometry(): ColumnSchema
    {
        return new ColumnSchema('GEOMETRY');
    }

    /**
     * Create POINT column schema.
     *
     * @return ColumnSchema
     */
    public function point(): ColumnSchema
    {
        return new ColumnSchema('POINT');
    }

    /**
     * Create LINESTRING column schema.
     *
     * @return ColumnSchema
     */
    public function lineString(): ColumnSchema
    {
        return new ColumnSchema('LINESTRING');
    }

    /**
     * Create POLYGON column schema.
     *
     * @return ColumnSchema
     */
    public function polygon(): ColumnSchema
    {
        return new ColumnSchema('POLYGON');
    }

    /**
     * Override UUID for MySQL-optimized storage.
     *
     * @return ColumnSchema
     */
    public function uuid(): ColumnSchema
    {
        return new ColumnSchema('CHAR', 36);
    }

    /**
     * Override boolean for MySQL-optimized storage.
     *
     * @return ColumnSchema
     */
    public function boolean(): ColumnSchema
    {
        return new ColumnSchema('TINYINT', 1);
    }
}
