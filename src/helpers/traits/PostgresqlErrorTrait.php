<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

/**
 * Trait for PostgreSQL error codes and related functionality.
 */
trait PostgresqlErrorTrait
{
    // PostgreSQL Error Codes (SQLSTATE)
    public const POSTGRESQL_CONNECTION_FAILURE = '08006';
    public const POSTGRESQL_CONNECTION_DOES_NOT_EXIST = '08003';
    public const POSTGRESQL_CONNECTION_FAILURE_SQLSERVER = '08001';
    public const POSTGRESQL_CONNECTION_EXCEPTION = '08000';
    public const POSTGRESQL_TRANSACTION_RESOLUTION_UNKNOWN = '08007';
    public const POSTGRESQL_CONNECTION_FAILURE_AUTH = '28P01';
    public const POSTGRESQL_INVALID_PASSWORD = '28P02';
    public const POSTGRESQL_INVALID_AUTHORIZATION_SPEC = '28P03';
    public const POSTGRESQL_CONNECTION_FAILURE_DB = '3D000';
    public const POSTGRESQL_DEADLOCK_DETECTED = '40P01';
    public const POSTGRESQL_LOCK_NOT_AVAILABLE = '55P03';
    public const POSTGRESQL_UNIQUE_VIOLATION = '23505';
    public const POSTGRESQL_FOREIGN_KEY_VIOLATION = '23503';
    public const POSTGRESQL_NOT_NULL_VIOLATION = '23502';
    public const POSTGRESQL_CHECK_VIOLATION = '23514';
    public const POSTGRESQL_EXCLUSION_VIOLATION = '23506';
    public const POSTGRESQL_TRANSACTION_ABORTED = '25P02';
    public const POSTGRESQL_TRANSACTION_NOT_IN_PROGRESS = '25P03';
    public const POSTGRESQL_UNDEFINED_TABLE = '42P01';
    public const POSTGRESQL_UNDEFINED_COLUMN = '42703';
    public const POSTGRESQL_SYNTAX_ERROR = '42601';
    public const POSTGRESQL_INSUFFICIENT_PRIVILEGE = '42501';
    public const POSTGRESQL_TOO_MANY_CONNECTIONS = '53300';
    public const POSTGRESQL_CONFIGURATION_FILE_ERROR = '53400';
    public const POSTGRESQL_QUERY_CANCELED = '57014';

    /**
     * Get retryable error codes for PostgreSQL.
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
     * Get PostgreSQL error descriptions.
     *
     * @return array<string, string>
     */
    private static function getPostgresqlDescriptions(): array
    {
        $descriptions = [];
        $descriptions[self::POSTGRESQL_CONNECTION_FAILURE] = 'Connection failure';
        $descriptions[self::POSTGRESQL_CONNECTION_DOES_NOT_EXIST] = 'Connection does not exist';
        $descriptions[self::POSTGRESQL_CONNECTION_FAILURE_SQLSERVER] = 'SQL server connection failure';
        $descriptions[self::POSTGRESQL_CONNECTION_EXCEPTION] = 'Connection exception';
        $descriptions[self::POSTGRESQL_TRANSACTION_RESOLUTION_UNKNOWN] = 'Transaction resolution unknown';
        $descriptions[self::POSTGRESQL_CONNECTION_FAILURE_AUTH] = 'Authentication failed';
        $descriptions[self::POSTGRESQL_INVALID_PASSWORD] = 'Invalid password';
        $descriptions[self::POSTGRESQL_INVALID_AUTHORIZATION_SPEC] = 'Invalid authorization specification';
        $descriptions[self::POSTGRESQL_CONNECTION_FAILURE_DB] = 'Database does not exist';
        $descriptions[self::POSTGRESQL_DEADLOCK_DETECTED] = 'Deadlock detected';
        $descriptions[self::POSTGRESQL_LOCK_NOT_AVAILABLE] = 'Lock not available';
        $descriptions[self::POSTGRESQL_UNIQUE_VIOLATION] = 'Unique constraint violation';
        $descriptions[self::POSTGRESQL_FOREIGN_KEY_VIOLATION] = 'Foreign key violation';
        $descriptions[self::POSTGRESQL_NOT_NULL_VIOLATION] = 'Not null violation';
        $descriptions[self::POSTGRESQL_CHECK_VIOLATION] = 'Check constraint violation';
        $descriptions[self::POSTGRESQL_EXCLUSION_VIOLATION] = 'Exclusion violation';
        $descriptions[self::POSTGRESQL_TRANSACTION_ABORTED] = 'Transaction is aborted';
        $descriptions[self::POSTGRESQL_TRANSACTION_NOT_IN_PROGRESS] = 'Transaction is not in progress';
        $descriptions[self::POSTGRESQL_UNDEFINED_TABLE] = 'Undefined table';
        $descriptions[self::POSTGRESQL_UNDEFINED_COLUMN] = 'Undefined column';
        $descriptions[self::POSTGRESQL_SYNTAX_ERROR] = 'Syntax error';
        $descriptions[self::POSTGRESQL_INSUFFICIENT_PRIVILEGE] = 'Insufficient privilege';
        $descriptions[self::POSTGRESQL_TOO_MANY_CONNECTIONS] = 'Too many connections';
        $descriptions[self::POSTGRESQL_CONFIGURATION_FILE_ERROR] = 'Configuration file error';
        $descriptions[self::POSTGRESQL_QUERY_CANCELED] = 'Query canceled';

        // @phpstan-ignore-next-line
        return $descriptions;
    }
}
