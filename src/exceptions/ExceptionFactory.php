<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use PDOException;
use tommyknocker\pdodb\helpers\DbError;

/**
 * Factory for creating specialized database exceptions from PDOException.
 *
 * Analyzes PDOException error codes and messages to determine
 * the appropriate specialized exception type.
 */
class ExceptionFactory
{
    /**
     * Create a specialized exception from PDOException.
     *
     * @param array<string, mixed> $context
     */
    public static function createFromPdoException(
        PDOException $e,
        string $driver,
        ?string $query = null,
        array $context = []
    ): DatabaseException {
        $code = $e->getCode();
        $message = $e->getMessage();

        // For PostgreSQL, get the SQLSTATE from errorInfo if available
        if ($driver === 'pgsql' && isset($e->errorInfo[0])) {
            $code = $e->errorInfo[0];
        }

        // Determine exception type based on error code and message
        $codeStr = (string) $code;
        $messageLower = strtolower($message);

        // Check in order of specificity (most specific first)
        if (self::isConstraintError($codeStr, $messageLower)) {
            return self::createConstraintException($e, $driver, $query, $context, $code, $message);
        }

        if (self::isAuthenticationError($codeStr, $messageLower)) {
            return new AuthenticationException($message, $code, $e, $driver, $query, $context);
        }

        if (self::isTimeoutError($codeStr, $messageLower)) {
            return new TimeoutException($message, $code, $e, $driver, $query, $context);
        }

        if (self::isResourceError($codeStr, $messageLower)) {
            return new ResourceException($message, $code, $e, $driver, $query, $context);
        }

        if (self::isTransactionError($codeStr, $messageLower)) {
            return new TransactionException($message, $code, $e, $driver, $query, $context);
        }

        if (self::isConnectionError($codeStr, $messageLower)) {
            return new ConnectionException($message, $code, $e, $driver, $query, $context);
        }

        // Default to query error
        return new QueryException($message, $code, $e, $driver, $query, $context);
    }

    /**
     * Check if error is a connection error.
     */
    protected static function isConnectionError(
        string $code,
        string $message
    ): bool {
        $connectionErrors = [
            // MySQL connection errors
            (string) DbError::MYSQL_CANNOT_CONNECT,
            (string) DbError::MYSQL_CONNECTION_LOST,
            (string) DbError::MYSQL_CONNECTION_KILLED,
            (string) DbError::MYSQL_CONNECTION_REFUSED,
            (string) DbError::MYSQL_COMMANDS_OUT_OF_SYNC,
            // PostgreSQL connection errors
            DbError::POSTGRESQL_CONNECTION_FAILURE,
            DbError::POSTGRESQL_CONNECTION_DOES_NOT_EXIST,
            DbError::POSTGRESQL_CONNECTION_FAILURE_SQLSERVER,
            DbError::POSTGRESQL_CONNECTION_EXCEPTION,
            DbError::POSTGRESQL_TRANSACTION_RESOLUTION_UNKNOWN,
            // SQLite connection errors
            (string) DbError::SQLITE_CANTOPEN,
            (string) DbError::SQLITE_IOERR,
            (string) DbError::SQLITE_CORRUPT,
            (string) DbError::SQLITE_PROTOCOL,
        ];

        if (in_array($code, $connectionErrors)) {
            return true;
        }

        // Check message patterns
        $connectionPatterns = [
            'connection',
            'server has gone away',
            'lost connection',
            'can\'t connect',
            'connection refused',
            'connection timeout',
            'connection reset',
            'broken pipe',
        ];

        foreach ($connectionPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if error is a constraint violation.
     */
    protected static function isConstraintError(
        string $code,
        string $message
    ): bool {
        $constraintErrors = [
            // MySQL constraint errors
            (string) DbError::MYSQL_DUPLICATE_KEY,
            (string) DbError::MYSQL_FOREIGN_KEY_DELETE,
            (string) DbError::MYSQL_FOREIGN_KEY_INSERT,
            (string) DbError::MYSQL_FOREIGN_KEY_INSERT_CHILD,
            (string) DbError::MYSQL_FOREIGN_KEY_DELETE_PARENT,
            // PostgreSQL constraint errors
            DbError::POSTGRESQL_UNIQUE_VIOLATION,
            DbError::POSTGRESQL_FOREIGN_KEY_VIOLATION,
            DbError::POSTGRESQL_NOT_NULL_VIOLATION,
            DbError::POSTGRESQL_CHECK_VIOLATION,
            DbError::POSTGRESQL_EXCLUSION_VIOLATION,
            // SQLite constraint errors
            (string) DbError::SQLITE_CONSTRAINT,
        ];

        if (in_array($code, $constraintErrors)) {
            return true;
        }

        // Check message patterns
        $constraintPatterns = [
            'duplicate entry',
            'foreign key constraint',
            'unique constraint',
            'check constraint',
            'not null constraint',
            'constraint violation',
            'duplicate key',
            'integrity constraint',
        ];

        foreach ($constraintPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if error is a transaction error.
     */
    protected static function isTransactionError(
        string $code,
        string $message
    ): bool {
        $transactionErrors = [
            // MySQL transaction errors
            (string) DbError::MYSQL_LOCK_WAIT_TIMEOUT,
            (string) DbError::MYSQL_DEADLOCK,
            // PostgreSQL transaction errors
            DbError::POSTGRESQL_DEADLOCK_DETECTED,
            DbError::POSTGRESQL_TRANSACTION_ABORTED,
            DbError::POSTGRESQL_TRANSACTION_NOT_IN_PROGRESS,
            // SQLite transaction errors
            (string) DbError::SQLITE_BUSY,
            (string) DbError::SQLITE_LOCKED,
        ];

        if (in_array($code, $transactionErrors)) {
            return true;
        }

        // Check message patterns
        $transactionPatterns = [
            'deadlock',
            'lock timeout',
            'serialization failure',
            'could not serialize',
            'transaction',
            'lock',
            'concurrent update',
        ];

        foreach ($transactionPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if error is an authentication error.
     */
    protected static function isAuthenticationError(
        string $code,
        string $message
    ): bool {
        $authErrors = [
            // MySQL auth errors
            (string) DbError::MYSQL_ACCESS_DENIED,
            (string) DbError::MYSQL_ACCESS_DENIED_USER,
            (string) DbError::MYSQL_DATABASE_NOT_EXISTS,
            // PostgreSQL auth errors
            DbError::POSTGRESQL_CONNECTION_FAILURE_AUTH,
            DbError::POSTGRESQL_INVALID_PASSWORD,
            DbError::POSTGRESQL_INVALID_AUTHORIZATION_SPEC,
            // SQLite auth errors
            (string) DbError::SQLITE_AUTH,
            (string) DbError::SQLITE_PERM,
        ];

        if (in_array($code, $authErrors)) {
            return true;
        }

        // Check message patterns
        $authPatterns = [
            'access denied',
            'authentication failed',
            'invalid password',
            'unknown user',
            'permission denied',
            'insufficient privilege',
        ];

        foreach ($authPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if error is a timeout error.
     */
    protected static function isTimeoutError(
        string $code,
        string $message
    ): bool {
        $timeoutErrors = [
            // MySQL timeout errors
            (string) DbError::MYSQL_QUERY_INTERRUPTED,
            // PostgreSQL timeout errors
            DbError::POSTGRESQL_QUERY_CANCELED,
            // SQLite timeout errors
            (string) DbError::SQLITE_INTERRUPT,
        ];

        if (in_array($code, $timeoutErrors)) {
            return true;
        }

        // Check message patterns
        $timeoutPatterns = [
            'timeout',
            'timed out',
            'query timeout',
            'connection timeout',
            'lock timeout',
        ];

        foreach ($timeoutPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if error is a resource error.
     */
    protected static function isResourceError(
        string $code,
        string $message
    ): bool {
        $resourceErrors = [
            // MySQL resource errors
            (string) DbError::MYSQL_TOO_MANY_CONNECTIONS,
            (string) DbError::MYSQL_OUT_OF_MEMORY,
            // PostgreSQL resource errors
            DbError::POSTGRESQL_TOO_MANY_CONNECTIONS,
            DbError::POSTGRESQL_CONFIGURATION_FILE_ERROR,
            // SQLite resource errors
            (string) DbError::SQLITE_NOMEM,
            (string) DbError::SQLITE_FULL,
        ];

        if (in_array($code, $resourceErrors)) {
            return true;
        }

        // Check message patterns
        $resourcePatterns = [
            'too many connections',
            'out of memory',
            'disk full',
            'no space left',
            'resource limit',
            'memory allocation',
        ];

        foreach ($resourcePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a constraint violation exception with parsed details.
     *
     * @param array<string, mixed> $context
     */
    protected static function createConstraintException(
        PDOException $e,
        string $driver,
        ?string $query,
        array $context,
        int|string $code,
        string $message
    ): ConstraintViolationException {
        $constraintName = null;
        $tableName = null;
        $columnName = null;

        // Parse constraint details from message
        if (preg_match('/for key \'([^\']+)\'/i', $message, $matches)) {
            $constraintName = $matches[1];
        } elseif (preg_match('/constraint "([^"]+)"/i', $message, $matches)) {
            $constraintName = $matches[1];
        } elseif (preg_match('/constraint `?([^`\s]+)`?/i', $message, $matches)) {
            $constraintName = $matches[1];
        }

        if (preg_match('/in table \'([^\']+)\'/i', $message, $matches)) {
            $tableName = $matches[1];
        } elseif (preg_match('/table `?([^`\s]+)`?/i', $message, $matches)) {
            $tableName = $matches[1];
        }

        if (preg_match('/column `?([^`\s]+)`?/i', $message, $matches)) {
            $columnName = $matches[1];
        }

        return new ConstraintViolationException(
            $message,
            $code,
            $e,
            $driver,
            $query,
            $context,
            $constraintName,
            $tableName,
            $columnName
        );
    }
}
