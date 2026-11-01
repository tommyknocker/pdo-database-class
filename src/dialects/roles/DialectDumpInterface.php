<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\PdoDb;

/**
 * Dump and restore of a database.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectDumpInterface
{
    /**
     * Dump database schema (CREATE TABLE, indexes, foreign keys).
     *
     * @param PdoDb $db Database instance
     * @param string|null $table Table name (null = all tables)
     * @param bool $dropTables Whether to add DROP TABLE IF EXISTS before CREATE TABLE
     *
     * @return string SQL schema dump
     * @throws ResourceException If operation fails or not supported
     */
    public function dumpSchema(PdoDb $db, ?string $table = null, bool $dropTables = true): string;

    /**
     * Dump database data (INSERT statements).
     *
     * @param PdoDb $db Database instance
     * @param string|null $table Table name (null = all tables)
     *
     * @return string SQL data dump
     * @throws ResourceException If operation fails or not supported
     */
    public function dumpData(PdoDb $db, ?string $table = null): string;

    /**
     * Restore database from SQL dump.
     *
     * @param PdoDb $db Database instance
     * @param string $sql SQL dump content
     * @param bool $continueOnError Continue on errors (skip failed statements)
     *
     * @throws ResourceException If restore fails
     */
    public function restoreFromSql(PdoDb $db, string $sql, bool $continueOnError = false): void;
}
