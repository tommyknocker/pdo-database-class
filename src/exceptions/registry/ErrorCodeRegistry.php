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

    /**
     * @return list<int|string>
     */
    public static function getMergedErrorCodes(string $errorType): array
    {
        $method = 'get' . ucfirst($errorType) . 'Errors';

        if (!method_exists(self::class, $method)) {
            return [];
        }

        $merged = [];
        foreach (self::$method() as $codes) {
            foreach ($codes as $code) {
                $merged[] = $code;
            }
        }

        return $merged;
    }

    /**
     * Native error numbers of the drivers whose SQLSTATE is too coarse to classify (SQL Server reports every
     * integrity violation as 23000, Oracle everything as HY000), keyed by driver and error type.
     *
     * They are kept apart from the merged lists above on purpose: the same number means different things in
     * different databases (1 is a generic error in SQLite and a unique violation in Oracle), so a number is only
     * compared with the codes of the driver that raised it.
     *
     * @return array<string, array<string, list<int>>>
     */
    public static function getNativeErrorCodes(): array
    {
        return [
            'sqlsrv' => [
                'constraint' => [2601, 2627, 515, 547],
                'transaction' => [1205, 1222],
                'authentication' => [18456, 18451, 18452, 18470, 18486, 4060],
                'connection' => [53, 233, 64, 6005, 10053, 10054, 10060, 11001],
                'resource' => [701, 1101, 1105, 8645, 9002],
            ],
            'oci' => [
                'constraint' => [1, 1400, 1407, 2290, 2291, 2292],
                'transaction' => [60, 54],
                'authentication' => [1017, 1045, 28000, 28001],
                'connection' => [3113, 3114, 3135, 12154, 12170, 12514, 12537, 12541, 12543],
                'resource' => [1536, 1653, 1654, 1688, 4031],
            ],
        ];
    }

    /**
     * Whether a driver's native error number belongs to an error type.
     */
    public static function isNativeCode(string $driver, string $errorType, string $code): bool
    {
        if ($code === '' || !is_numeric($code)) {
            return false;
        }

        return in_array((int)$code, self::getNativeErrorCodes()[strtolower($driver)][$errorType] ?? [], true);
    }
}
