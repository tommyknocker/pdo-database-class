<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

/**
 * Trait for SQLite error codes and related functionality.
 */
trait SqliteErrorTrait
{
    // SQLite Error Codes
    public const SQLITE_ERROR = 1;
    public const SQLITE_INTERNAL = 2;
    public const SQLITE_PERM = 3;
    public const SQLITE_ABORT = 4;
    public const SQLITE_BUSY = 5;
    public const SQLITE_LOCKED = 6;
    public const SQLITE_NOMEM = 7;
    public const SQLITE_READONLY = 8;
    public const SQLITE_INTERRUPT = 9;
    public const SQLITE_IOERR = 10;
    public const SQLITE_CORRUPT = 11;
    public const SQLITE_NOTFOUND = 12;
    public const SQLITE_FULL = 13;
    public const SQLITE_CANTOPEN = 14;
    public const SQLITE_PROTOCOL = 15;
    public const SQLITE_EMPTY = 16;
    public const SQLITE_SCHEMA = 17;
    public const SQLITE_TOOBIG = 18;
    public const SQLITE_CONSTRAINT = 19;
    public const SQLITE_MISMATCH = 20;
    public const SQLITE_MISUSE = 21;
    public const SQLITE_NOLFS = 22;
    public const SQLITE_AUTH = 23;
    public const SQLITE_FORMAT = 24;
    public const SQLITE_RANGE = 25;
    public const SQLITE_NOTADB = 26;
    public const SQLITE_NOTICE = 27;
    public const SQLITE_WARNING = 28;
    public const SQLITE_ROW = 100;
    public const SQLITE_DONE = 101;

    /**
     * Get retryable error codes for SQLite.
     *
     * @return array<int>
     */
    public static function getSqliteRetryableErrors(): array
    {
        return [
            self::SQLITE_BUSY,
            self::SQLITE_LOCKED,
            self::SQLITE_INTERRUPT,
            self::SQLITE_IOERR,
            self::SQLITE_CORRUPT,
            self::SQLITE_FULL,
            self::SQLITE_CANTOPEN,
            self::SQLITE_PROTOCOL,
            self::SQLITE_SCHEMA,
            self::SQLITE_TOOBIG,
            self::SQLITE_CONSTRAINT,
            self::SQLITE_MISMATCH,
            self::SQLITE_MISUSE,
            self::SQLITE_NOLFS,
            self::SQLITE_AUTH,
            self::SQLITE_FORMAT,
            self::SQLITE_RANGE,
            self::SQLITE_NOTADB,
        ];
    }

    /**
     * Get SQLite error descriptions.
     *
     * @return array<int, string>
     */
    private static function getSqliteDescriptions(): array
    {
        return [
            self::SQLITE_ERROR => 'SQL error or missing database',
            self::SQLITE_INTERNAL => 'Internal logic error in SQLite',
            self::SQLITE_PERM => 'Access permission denied',
            self::SQLITE_ABORT => 'Callback routine requested an abort',
            self::SQLITE_BUSY => 'Database file is locked',
            self::SQLITE_LOCKED => 'Database table is locked',
            self::SQLITE_NOMEM => 'Out of memory',
            self::SQLITE_READONLY => 'Attempt to write a readonly database',
            self::SQLITE_INTERRUPT => 'Operation terminated by sqlite3_interrupt()',
            self::SQLITE_IOERR => 'I/O error',
            self::SQLITE_CORRUPT => 'Database disk image is malformed',
            self::SQLITE_NOTFOUND => 'Not found',
            self::SQLITE_FULL => 'Database or disk is full',
            self::SQLITE_CANTOPEN => 'Unable to open the database file',
            self::SQLITE_PROTOCOL => 'Database lock protocol error',
            self::SQLITE_EMPTY => 'Database is empty',
            self::SQLITE_SCHEMA => 'Database schema has changed',
            self::SQLITE_TOOBIG => 'String or BLOB exceeds size limit',
            self::SQLITE_CONSTRAINT => 'Abort due to constraint violation',
            self::SQLITE_MISMATCH => 'Data type mismatch',
            self::SQLITE_MISUSE => 'Library used incorrectly',
            self::SQLITE_NOLFS => 'Uses OS features not supported on host',
            self::SQLITE_AUTH => 'Authorization denied',
            self::SQLITE_FORMAT => 'Auxiliary database format error',
            self::SQLITE_RANGE => '2nd parameter to sqlite3_bind out of range',
            self::SQLITE_NOTADB => 'File opened that is not a database file',
            self::SQLITE_NOTICE => 'Notifications from sqlite3_log()',
            self::SQLITE_WARNING => 'Warnings from sqlite3_log()',
            self::SQLITE_ROW => 'sqlite3_step() has another row ready',
            self::SQLITE_DONE => 'sqlite3_step() has finished executing',
        ];
    }
}
