<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\sqlite;

use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * SQLite-specific DDL Query Builder.
 *
 * SQLite has a limited set of native types (INTEGER, REAL, TEXT, BLOB, NULL),
 * but this builder provides convenient aliases that map to appropriate SQLite types.
 */
class SQLiteDdlQueryBuilder extends DdlQueryBuilder
{
    /**
     * Override UUID for SQLite (stored as TEXT).
     *
     * @return ColumnSchema
     */
    public function uuid(): ColumnSchema
    {
        return new ColumnSchema('TEXT'); // SQLite stores UUID as TEXT
    }

    /**
     * Override tinyInteger for SQLite (uses INTEGER).
     *
     * @param int|null $length Integer length (ignored in SQLite)
     *
     * @return ColumnSchema
     */
    public function tinyInteger(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('INTEGER'); // SQLite has only INTEGER type
    }

    /**
     * Override smallInteger for SQLite (uses INTEGER).
     *
     * @param int|null $length Integer length (ignored in SQLite)
     *
     * @return ColumnSchema
     */
    public function smallInteger(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('INTEGER');
    }

    /**
     * Override mediumInteger for SQLite (uses INTEGER).
     *
     * @param int|null $length Integer length (ignored in SQLite)
     *
     * @return ColumnSchema
     */
    public function mediumInteger(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('INTEGER');
    }

    /**
     * Override bigInteger for SQLite (uses INTEGER).
     *
     * @return ColumnSchema
     */
    public function bigInteger(): ColumnSchema
    {
        return new ColumnSchema('INTEGER');
    }

    /**
     * Override boolean for SQLite (stored as INTEGER).
     *
     * @return ColumnSchema
     */
    public function boolean(): ColumnSchema
    {
        return new ColumnSchema('INTEGER'); // SQLite stores boolean as INTEGER (0/1)
    }

    /**
     * Override float for SQLite (uses REAL).
     *
     * @param int|null $precision Precision (ignored in SQLite)
     * @param int|null $scale Scale (ignored in SQLite)
     *
     * @return ColumnSchema
     */
    public function float(?int $precision = null, ?int $scale = null): ColumnSchema
    {
        return new ColumnSchema('REAL'); // SQLite uses REAL for floating point
    }

    /**
     * Override decimal for SQLite (uses REAL).
     *
     * @param int $precision Precision (ignored in SQLite)
     * @param int $scale Scale (ignored in SQLite)
     *
     * @return ColumnSchema
     */
    public function decimal(int $precision = 10, int $scale = 2): ColumnSchema
    {
        return new ColumnSchema('REAL'); // SQLite doesn't have exact decimal, uses REAL
    }

    /**
     * Override longText for SQLite (uses TEXT).
     *
     * @return ColumnSchema
     */
    public function longText(): ColumnSchema
    {
        return new ColumnSchema('TEXT'); // SQLite TEXT has no size limit
    }

    /**
     * Override mediumText for SQLite (uses TEXT).
     *
     * @return ColumnSchema
     */
    public function mediumText(): ColumnSchema
    {
        return new ColumnSchema('TEXT');
    }

    /**
     * Override tinyText for SQLite (uses TEXT).
     *
     * @return ColumnSchema
     */
    public function tinyText(): ColumnSchema
    {
        return new ColumnSchema('TEXT');
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
     * Override binary for SQLite (uses BLOB).
     *
     * @param int|null $length Binary length (ignored in SQLite)
     *
     * @return ColumnSchema
     */
    public function binary(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('BLOB'); // SQLite stores binary data as BLOB
    }

    /**
     * Override varbinary for SQLite (uses BLOB).
     *
     * @param int|null $length Binary length (ignored in SQLite)
     *
     * @return ColumnSchema
     */
    public function varbinary(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('BLOB');
    }

    /**
     * Override longBlob for SQLite (uses BLOB).
     *
     * @return ColumnSchema
     */
    public function longBlob(): ColumnSchema
    {
        return new ColumnSchema('BLOB');
    }

    /**
     * Override mediumBlob for SQLite (uses BLOB).
     *
     * @return ColumnSchema
     */
    public function mediumBlob(): ColumnSchema
    {
        return new ColumnSchema('BLOB');
    }

    /**
     * Override tinyBlob for SQLite (uses BLOB).
     *
     * @return ColumnSchema
     */
    public function tinyBlob(): ColumnSchema
    {
        return new ColumnSchema('BLOB');
    }

    /**
     * Override datetime for SQLite (uses TEXT with ISO8601 format).
     *
     * @return ColumnSchema
     */
    public function datetime(): ColumnSchema
    {
        return new ColumnSchema('TEXT'); // SQLite stores datetime as TEXT in ISO8601 format
    }

    /**
     * Override timestamp for SQLite (uses TEXT with ISO8601 format).
     *
     * @return ColumnSchema
     */
    public function timestamp(): ColumnSchema
    {
        return new ColumnSchema('TEXT'); // SQLite stores timestamp as TEXT
    }

    /**
     * Override date for SQLite (uses TEXT).
     *
     * @return ColumnSchema
     */
    public function date(): ColumnSchema
    {
        return new ColumnSchema('TEXT'); // SQLite stores date as TEXT
    }

    /**
     * Override time for SQLite (uses TEXT).
     *
     * @return ColumnSchema
     */
    public function time(): ColumnSchema
    {
        return new ColumnSchema('TEXT'); // SQLite stores time as TEXT
    }

    /**
     * Override json for SQLite (uses TEXT).
     *
     * @return ColumnSchema
     */
    public function json(): ColumnSchema
    {
        return new ColumnSchema('TEXT'); // SQLite stores JSON as TEXT (with JSON functions)
    }

    /**
     * Create NUMERIC column schema (SQLite's affinity-based type).
     *
     * @param int|null $precision Precision (for documentation only)
     * @param int|null $scale Scale (for documentation only)
     *
     * @return ColumnSchema
     */
    public function numeric(?int $precision = null, ?int $scale = null): ColumnSchema
    {
        return new ColumnSchema('NUMERIC', $precision, $scale);
    }
}
