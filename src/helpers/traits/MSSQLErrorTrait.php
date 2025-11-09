<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

/**
 * Trait for MSSQL error codes and related functionality.
 */
trait MSSQLErrorTrait
{
    // MSSQL Error Codes (SQLSTATE)
    public const MSSQL_CONNECTION_FAILURE = '08001';
    public const MSSQL_CONNECTION_DOES_NOT_EXIST = '08003';
    public const MSSQL_CONNECTION_EXCEPTION = '08000';
    public const MSSQL_CONNECTION_FAILURE_AUTH = '28000';
    public const MSSQL_INVALID_PASSWORD = '28001';
    public const MSSQL_INVALID_AUTHORIZATION_SPEC = '28002';
    public const MSSQL_CONNECTION_FAILURE_DB = '3D000';
    public const MSSQL_DEADLOCK_DETECTED = '40001';
    public const MSSQL_LOCK_TIMEOUT = '1222';
    public const MSSQL_UNIQUE_VIOLATION = '23000';
    public const MSSQL_FOREIGN_KEY_VIOLATION = '23000';
    public const MSSQL_NOT_NULL_VIOLATION = '23000';
    public const MSSQL_CHECK_VIOLATION = '23000';
    public const MSSQL_TRANSACTION_ABORTED = '25P02';
    public const MSSQL_UNDEFINED_TABLE = '42S02';
    public const MSSQL_UNDEFINED_COLUMN = '42S22';
    public const MSSQL_SYNTAX_ERROR = '42000';
    public const MSSQL_INSUFFICIENT_PRIVILEGE = '42000';
    public const MSSQL_TOO_MANY_CONNECTIONS = '08004';
    public const MSSQL_QUERY_CANCELED = '57014';
    public const MSSQL_QUERY_TIMEOUT = 'HYT00';

    /**
     * Get retryable error codes for MSSQL.
     *
     * @return array<string>
     */
    public static function getMssqlRetryableErrors(): array
    {
        return [
            self::MSSQL_CONNECTION_FAILURE,
            self::MSSQL_CONNECTION_DOES_NOT_EXIST,
            self::MSSQL_CONNECTION_EXCEPTION,
            self::MSSQL_DEADLOCK_DETECTED,
            self::MSSQL_LOCK_TIMEOUT,
            self::MSSQL_TOO_MANY_CONNECTIONS,
            self::MSSQL_QUERY_CANCELED,
            self::MSSQL_QUERY_TIMEOUT,
        ];
    }

    /**
     * Get MSSQL error descriptions.
     *
     * @return array<string, string>
     */
    protected static function getMssqlDescriptions(): array
    {
        /** @var array<string, string> $descriptions */
        $descriptions = [];
        $descriptions[self::MSSQL_CONNECTION_FAILURE] = 'Connection failure';
        $descriptions[self::MSSQL_CONNECTION_DOES_NOT_EXIST] = 'Connection does not exist';
        $descriptions[self::MSSQL_CONNECTION_EXCEPTION] = 'Connection exception';
        $descriptions[self::MSSQL_CONNECTION_FAILURE_AUTH] = 'Authentication failed';
        $descriptions[self::MSSQL_INVALID_PASSWORD] = 'Invalid password';
        $descriptions[self::MSSQL_INVALID_AUTHORIZATION_SPEC] = 'Invalid authorization specification';
        $descriptions[self::MSSQL_CONNECTION_FAILURE_DB] = 'Database does not exist';
        $descriptions[self::MSSQL_DEADLOCK_DETECTED] = 'Deadlock detected';
        $descriptions[self::MSSQL_LOCK_TIMEOUT] = 'Lock request time out period exceeded';
        $descriptions[self::MSSQL_UNIQUE_VIOLATION] = 'Unique constraint violation';
        $descriptions[self::MSSQL_FOREIGN_KEY_VIOLATION] = 'Foreign key violation';
        $descriptions[self::MSSQL_NOT_NULL_VIOLATION] = 'Not null violation';
        $descriptions[self::MSSQL_CHECK_VIOLATION] = 'Check constraint violation';
        $descriptions[self::MSSQL_TRANSACTION_ABORTED] = 'Transaction is aborted';
        $descriptions[self::MSSQL_UNDEFINED_TABLE] = 'Table does not exist';
        $descriptions[self::MSSQL_UNDEFINED_COLUMN] = 'Column does not exist';
        $descriptions[self::MSSQL_SYNTAX_ERROR] = 'Syntax error';
        $descriptions[self::MSSQL_INSUFFICIENT_PRIVILEGE] = 'Insufficient privilege';
        $descriptions[self::MSSQL_TOO_MANY_CONNECTIONS] = 'Too many connections';
        $descriptions[self::MSSQL_QUERY_CANCELED] = 'Query canceled';
        $descriptions[self::MSSQL_QUERY_TIMEOUT] = 'Query timeout';
        return $descriptions;
    }
}
