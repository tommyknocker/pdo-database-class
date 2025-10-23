<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * Database error codes constants for different dialects
 * 
 * This class provides standardized error codes for MySQL, PostgreSQL, and SQLite
 * to improve code readability and maintainability.
 */
class DbError
{
    // MySQL Error Codes
    public const MYSQL_CONNECTION_LOST = 2006;
    public const MYSQL_CANNOT_CONNECT = 2002;
    public const MYSQL_CONNECTION_KILLED = 2013;
    public const MYSQL_LOCK_WAIT_TIMEOUT = 1205;
    public const MYSQL_DEADLOCK = 1213;
    public const MYSQL_DUPLICATE_KEY = 1062;
    public const MYSQL_TABLE_EXISTS = 1050;
    public const MYSQL_TABLE_NOT_EXISTS = 1146;
    public const MYSQL_COLUMN_NOT_EXISTS = 1054;
    public const MYSQL_SYNTAX_ERROR = 1064;
    public const MYSQL_ACCESS_DENIED = 1045;
    public const MYSQL_DATABASE_NOT_EXISTS = 1049;
    public const MYSQL_TOO_MANY_CONNECTIONS = 1040;
    public const MYSQL_QUERY_INTERRUPTED = 1317;

    // PostgreSQL Error Codes (SQLSTATE)
    public const POSTGRESQL_CONNECTION_FAILURE = '08006';
    public const POSTGRESQL_CONNECTION_DOES_NOT_EXIST = '08003';
    public const POSTGRESQL_CONNECTION_FAILURE_SQLSERVER = '08001';
    public const POSTGRESQL_CONNECTION_FAILURE_AUTH = '28P01';
    public const POSTGRESQL_CONNECTION_FAILURE_DB = '3D000';
    public const POSTGRESQL_DEADLOCK_DETECTED = '40P01';
    public const POSTGRESQL_LOCK_NOT_AVAILABLE = '55P03';
    public const POSTGRESQL_UNIQUE_VIOLATION = '23505';
    public const POSTGRESQL_FOREIGN_KEY_VIOLATION = '23503';
    public const POSTGRESQL_NOT_NULL_VIOLATION = '23502';
    public const POSTGRESQL_CHECK_VIOLATION = '23514';
    public const POSTGRESQL_UNDEFINED_TABLE = '42P01';
    public const POSTGRESQL_UNDEFINED_COLUMN = '42703';
    public const POSTGRESQL_SYNTAX_ERROR = '42601';
    public const POSTGRESQL_INSUFFICIENT_PRIVILEGE = '42501';
    public const POSTGRESQL_TOO_MANY_CONNECTIONS = '53300';
    public const POSTGRESQL_QUERY_CANCELED = '57014';

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
     * Get retryable error codes for MySQL
     * 
     * @return array<int>
     */
    public static function getMysqlRetryableErrors(): array
    {
        return [
            self::MYSQL_CONNECTION_LOST,
            self::MYSQL_CANNOT_CONNECT,
            self::MYSQL_CONNECTION_KILLED,
            self::MYSQL_LOCK_WAIT_TIMEOUT,
            self::MYSQL_DEADLOCK,
            self::MYSQL_TOO_MANY_CONNECTIONS,
            self::MYSQL_QUERY_INTERRUPTED,
        ];
    }

    /**
     * Get retryable error codes for PostgreSQL
     * 
     * @return array<string>
     */
    public static function getPostgresqlRetryableErrors(): array
    {
        return [
            self::POSTGRESQL_CONNECTION_FAILURE,
            self::POSTGRESQL_CONNECTION_DOES_NOT_EXIST,
            self::POSTGRESQL_CONNECTION_FAILURE_SQLSERVER,
            self::POSTGRESQL_DEADLOCK_DETECTED,
            self::POSTGRESQL_LOCK_NOT_AVAILABLE,
            self::POSTGRESQL_TOO_MANY_CONNECTIONS,
            self::POSTGRESQL_QUERY_CANCELED,
        ];
    }

    /**
     * Get retryable error codes for SQLite
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
     * Get retryable error codes for a specific driver
     * 
     * @param string $driver
     * @return array<int|string>
     */
    public static function getRetryableErrors(string $driver): array
    {
        return match (strtolower($driver)) {
            'mysql' => self::getMysqlRetryableErrors(),
            'pgsql' => self::getPostgresqlRetryableErrors(),
            'sqlite' => self::getSqliteRetryableErrors(),
            default => [],
        };
    }

    /**
     * Check if an error code is retryable for a specific driver
     * 
     * @param int|string $errorCode
     * @param string $driver
     * @return bool
     */
    public static function isRetryable(int|string $errorCode, string $driver): bool
    {
        return in_array($errorCode, self::getRetryableErrors($driver), true);
    }

    /**
     * Get human-readable error description
     * 
     * @param int|string $errorCode
     * @param string $driver
     * @return string
     */
    public static function getDescription(int|string $errorCode, string $driver): string
    {
        $descriptions = match (strtolower($driver)) {
            'mysql' => self::getMysqlDescriptions(),
            'pgsql' => self::getPostgresqlDescriptions(),
            'sqlite' => self::getSqliteDescriptions(),
            default => [],
        };

        return $descriptions[$errorCode] ?? 'Unknown error';
    }

    /**
     * Get MySQL error descriptions
     * 
     * @return array<int, string>
     */
    private static function getMysqlDescriptions(): array
    {
        return [
            self::MYSQL_CONNECTION_LOST => 'MySQL server has gone away',
            self::MYSQL_CANNOT_CONNECT => 'Can\'t connect to MySQL server',
            self::MYSQL_CONNECTION_KILLED => 'Connection was killed',
            self::MYSQL_LOCK_WAIT_TIMEOUT => 'Lock wait timeout exceeded',
            self::MYSQL_DEADLOCK => 'Deadlock found when trying to get lock',
            self::MYSQL_DUPLICATE_KEY => 'Duplicate entry',
            self::MYSQL_TABLE_EXISTS => 'Table already exists',
            self::MYSQL_TABLE_NOT_EXISTS => 'Table doesn\'t exist',
            self::MYSQL_COLUMN_NOT_EXISTS => 'Unknown column',
            self::MYSQL_SYNTAX_ERROR => 'Syntax error',
            self::MYSQL_ACCESS_DENIED => 'Access denied',
            self::MYSQL_DATABASE_NOT_EXISTS => 'Unknown database',
            self::MYSQL_TOO_MANY_CONNECTIONS => 'Too many connections',
            self::MYSQL_QUERY_INTERRUPTED => 'Query execution was interrupted',
        ];
    }

    /**
     * Get PostgreSQL error descriptions
     * 
     * @return array<string, string>
     */
    private static function getPostgresqlDescriptions(): array
    {
        $descriptions = [];
        $descriptions[self::POSTGRESQL_CONNECTION_FAILURE] = 'Connection failure';
        $descriptions[self::POSTGRESQL_CONNECTION_DOES_NOT_EXIST] = 'Connection does not exist';
        $descriptions[self::POSTGRESQL_CONNECTION_FAILURE_SQLSERVER] = 'SQL server connection failure';
        $descriptions[self::POSTGRESQL_CONNECTION_FAILURE_AUTH] = 'Authentication failed';
        $descriptions[self::POSTGRESQL_CONNECTION_FAILURE_DB] = 'Database does not exist';
        $descriptions[self::POSTGRESQL_DEADLOCK_DETECTED] = 'Deadlock detected';
        $descriptions[self::POSTGRESQL_LOCK_NOT_AVAILABLE] = 'Lock not available';
        $descriptions[self::POSTGRESQL_UNIQUE_VIOLATION] = 'Unique constraint violation';
        $descriptions[self::POSTGRESQL_FOREIGN_KEY_VIOLATION] = 'Foreign key violation';
        $descriptions[self::POSTGRESQL_NOT_NULL_VIOLATION] = 'Not null violation';
        $descriptions[self::POSTGRESQL_CHECK_VIOLATION] = 'Check constraint violation';
        $descriptions[self::POSTGRESQL_UNDEFINED_TABLE] = 'Undefined table';
        $descriptions[self::POSTGRESQL_UNDEFINED_COLUMN] = 'Undefined column';
        $descriptions[self::POSTGRESQL_SYNTAX_ERROR] = 'Syntax error';
        $descriptions[self::POSTGRESQL_INSUFFICIENT_PRIVILEGE] = 'Insufficient privilege';
        $descriptions[self::POSTGRESQL_TOO_MANY_CONNECTIONS] = 'Too many connections';
        $descriptions[self::POSTGRESQL_QUERY_CANCELED] = 'Query canceled';
        
        // @phpstan-ignore-next-line
        return $descriptions;
    }

    /**
     * Get SQLite error descriptions
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
