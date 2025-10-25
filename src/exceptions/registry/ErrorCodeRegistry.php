<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\registry;

use tommyknocker\pdodb\helpers\DbError;

/**
 * Registry for database error codes organized by error type and driver.
 *
 * Centralizes error code definitions to avoid duplication and improve maintainability.
 */
class ErrorCodeRegistry
{
    /** @var array<string, array<int|string>> */
    protected static array $constraintErrors = [];

    /** @var array<string, array<int|string>> */
    protected static array $connectionErrors = [];

    /** @var array<string, array<int|string>> */
    protected static array $transactionErrors = [];

    /** @var array<string, array<int|string>> */
    protected static array $authenticationErrors = [];

    /** @var array<string, array<int|string>> */
    protected static array $timeoutErrors = [];

    /** @var array<string, array<int|string>> */
    protected static array $resourceErrors = [];

    /**
     * Get constraint violation error codes by driver.
     *
     * @return array<string, array<int|string>>
     */
    public static function getConstraintErrors(): array
    {
        if (empty(self::$constraintErrors)) {
            self::$constraintErrors = [
                'mysql' => [
                    DbError::MYSQL_DUPLICATE_KEY,
                    DbError::MYSQL_FOREIGN_KEY_DELETE,
                    DbError::MYSQL_FOREIGN_KEY_INSERT,
                    DbError::MYSQL_FOREIGN_KEY_INSERT_CHILD,
                    DbError::MYSQL_FOREIGN_KEY_DELETE_PARENT,
                ],
                'pgsql' => [
                    DbError::POSTGRESQL_UNIQUE_VIOLATION,
                    DbError::POSTGRESQL_FOREIGN_KEY_VIOLATION,
                    DbError::POSTGRESQL_NOT_NULL_VIOLATION,
                    DbError::POSTGRESQL_CHECK_VIOLATION,
                    DbError::POSTGRESQL_EXCLUSION_VIOLATION,
                ],
                'sqlite' => [
                    DbError::SQLITE_CONSTRAINT,
                ],
            ];
        }

        return self::$constraintErrors;
    }

    /**
     * Get connection error codes by driver.
     *
     * @return array<string, array<int|string>>
     */
    public static function getConnectionErrors(): array
    {
        if (empty(self::$connectionErrors)) {
            self::$connectionErrors = [
                'mysql' => [
                    DbError::MYSQL_CANNOT_CONNECT,
                    DbError::MYSQL_CONNECTION_LOST,
                    DbError::MYSQL_CONNECTION_KILLED,
                    DbError::MYSQL_CONNECTION_REFUSED,
                    DbError::MYSQL_COMMANDS_OUT_OF_SYNC,
                ],
                'pgsql' => [
                    DbError::POSTGRESQL_CONNECTION_FAILURE,
                    DbError::POSTGRESQL_CONNECTION_DOES_NOT_EXIST,
                    DbError::POSTGRESQL_CONNECTION_FAILURE_SQLSERVER,
                    DbError::POSTGRESQL_CONNECTION_EXCEPTION,
                    DbError::POSTGRESQL_TRANSACTION_RESOLUTION_UNKNOWN,
                ],
                'sqlite' => [
                    DbError::SQLITE_CANTOPEN,
                    DbError::SQLITE_IOERR,
                    DbError::SQLITE_CORRUPT,
                    DbError::SQLITE_PROTOCOL,
                ],
            ];
        }

        return self::$connectionErrors;
    }

    /**
     * Get transaction error codes by driver.
     *
     * @return array<string, array<int|string>>
     */
    public static function getTransactionErrors(): array
    {
        if (empty(self::$transactionErrors)) {
            self::$transactionErrors = [
                'mysql' => [
                    DbError::MYSQL_LOCK_WAIT_TIMEOUT,
                    DbError::MYSQL_DEADLOCK,
                ],
                'pgsql' => [
                    DbError::POSTGRESQL_DEADLOCK_DETECTED,
                    DbError::POSTGRESQL_TRANSACTION_ABORTED,
                    DbError::POSTGRESQL_TRANSACTION_NOT_IN_PROGRESS,
                ],
                'sqlite' => [
                    DbError::SQLITE_BUSY,
                    DbError::SQLITE_LOCKED,
                ],
            ];
        }

        return self::$transactionErrors;
    }

    /**
     * Get authentication error codes by driver.
     *
     * @return array<string, array<int|string>>
     */
    public static function getAuthenticationErrors(): array
    {
        if (empty(self::$authenticationErrors)) {
            self::$authenticationErrors = [
                'mysql' => [
                    DbError::MYSQL_ACCESS_DENIED,
                    DbError::MYSQL_ACCESS_DENIED_USER,
                    DbError::MYSQL_DATABASE_NOT_EXISTS,
                ],
                'pgsql' => [
                    DbError::POSTGRESQL_CONNECTION_FAILURE_AUTH,
                    DbError::POSTGRESQL_INVALID_PASSWORD,
                    DbError::POSTGRESQL_INVALID_AUTHORIZATION_SPEC,
                ],
                'sqlite' => [
                    DbError::SQLITE_AUTH,
                    DbError::SQLITE_PERM,
                ],
            ];
        }

        return self::$authenticationErrors;
    }

    /**
     * Get timeout error codes by driver.
     *
     * @return array<string, array<int|string>>
     */
    public static function getTimeoutErrors(): array
    {
        if (empty(self::$timeoutErrors)) {
            self::$timeoutErrors = [
                'mysql' => [
                    DbError::MYSQL_QUERY_INTERRUPTED,
                ],
                'pgsql' => [
                    DbError::POSTGRESQL_QUERY_CANCELED,
                ],
                'sqlite' => [
                    DbError::SQLITE_INTERRUPT,
                ],
            ];
        }

        return self::$timeoutErrors;
    }

    /**
     * Get resource error codes by driver.
     *
     * @return array<string, array<int|string>>
     */
    public static function getResourceErrors(): array
    {
        if (empty(self::$resourceErrors)) {
            self::$resourceErrors = [
                'mysql' => [
                    DbError::MYSQL_TOO_MANY_CONNECTIONS,
                    DbError::MYSQL_OUT_OF_MEMORY,
                ],
                'pgsql' => [
                    DbError::POSTGRESQL_TOO_MANY_CONNECTIONS,
                    DbError::POSTGRESQL_CONFIGURATION_FILE_ERROR,
                ],
                'sqlite' => [
                    DbError::SQLITE_NOMEM,
                    DbError::SQLITE_FULL,
                ],
            ];
        }

        return self::$resourceErrors;
    }

    /**
     * Get all error codes for a specific driver and error type.
     *
     * @param string $driver The database driver
     * @param string $errorType The error type (constraint, connection, etc.)
     *
     * @return array<int|string> Array of error codes
     */
    public static function getErrorCodes(string $driver, string $errorType): array
    {
        $method = 'get' . ucfirst($errorType) . 'Errors';

        if (!method_exists(self::class, $method)) {
            return [];
        }

        $allErrors = self::$method();
        return $allErrors[strtolower($driver)] ?? [];
    }
}
