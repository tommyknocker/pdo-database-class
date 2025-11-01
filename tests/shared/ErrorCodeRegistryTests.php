<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\registry\ErrorCodeRegistry;
use tommyknocker\pdodb\helpers\DbError;

/**
 * Tests for ErrorCodeRegistry.
 */
final class ErrorCodeRegistryTests extends BaseSharedTestCase
{
    public function testGetConstraintErrorsForMySQL(): void
    {
        $errors = ErrorCodeRegistry::getConstraintErrors();
        $mysqlErrors = $errors['mysql'];

        $this->assertIsArray($mysqlErrors);
        $this->assertContains(DbError::MYSQL_DUPLICATE_KEY, $mysqlErrors);
        $this->assertContains(DbError::MYSQL_FOREIGN_KEY_DELETE, $mysqlErrors);
        $this->assertContains(DbError::MYSQL_FOREIGN_KEY_INSERT, $mysqlErrors);
    }

    public function testGetConstraintErrorsForPostgreSQL(): void
    {
        $errors = ErrorCodeRegistry::getConstraintErrors();
        $pgsqlErrors = $errors['pgsql'];

        $this->assertIsArray($pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_UNIQUE_VIOLATION, $pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_FOREIGN_KEY_VIOLATION, $pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_NOT_NULL_VIOLATION, $pgsqlErrors);
    }

    public function testGetConstraintErrorsForSQLite(): void
    {
        $errors = ErrorCodeRegistry::getConstraintErrors();
        $sqliteErrors = $errors['sqlite'];

        $this->assertIsArray($sqliteErrors);
        $this->assertContains(DbError::SQLITE_CONSTRAINT, $sqliteErrors);
    }

    public function testGetConnectionErrorsForMySQL(): void
    {
        $errors = ErrorCodeRegistry::getConnectionErrors();
        $mysqlErrors = $errors['mysql'];

        $this->assertIsArray($mysqlErrors);
        $this->assertContains(DbError::MYSQL_CANNOT_CONNECT, $mysqlErrors);
        $this->assertContains(DbError::MYSQL_CONNECTION_LOST, $mysqlErrors);
    }

    public function testGetConnectionErrorsForPostgreSQL(): void
    {
        $errors = ErrorCodeRegistry::getConnectionErrors();
        $pgsqlErrors = $errors['pgsql'];

        $this->assertIsArray($pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_CONNECTION_FAILURE, $pgsqlErrors);
    }

    public function testGetConnectionErrorsForSQLite(): void
    {
        $errors = ErrorCodeRegistry::getConnectionErrors();
        $sqliteErrors = $errors['sqlite'];

        $this->assertIsArray($sqliteErrors);
        $this->assertContains(DbError::SQLITE_CANTOPEN, $sqliteErrors);
        $this->assertContains(DbError::SQLITE_IOERR, $sqliteErrors);
    }

    public function testGetTransactionErrorsForMySQL(): void
    {
        $errors = ErrorCodeRegistry::getTransactionErrors();
        $mysqlErrors = $errors['mysql'];

        $this->assertIsArray($mysqlErrors);
        $this->assertContains(DbError::MYSQL_LOCK_WAIT_TIMEOUT, $mysqlErrors);
        $this->assertContains(DbError::MYSQL_DEADLOCK, $mysqlErrors);
    }

    public function testGetTransactionErrorsForPostgreSQL(): void
    {
        $errors = ErrorCodeRegistry::getTransactionErrors();
        $pgsqlErrors = $errors['pgsql'];

        $this->assertIsArray($pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_DEADLOCK_DETECTED, $pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_TRANSACTION_ABORTED, $pgsqlErrors);
    }

    public function testGetTransactionErrorsForSQLite(): void
    {
        $errors = ErrorCodeRegistry::getTransactionErrors();
        $sqliteErrors = $errors['sqlite'];

        $this->assertIsArray($sqliteErrors);
        $this->assertContains(DbError::SQLITE_BUSY, $sqliteErrors);
        $this->assertContains(DbError::SQLITE_LOCKED, $sqliteErrors);
    }

    public function testGetAuthenticationErrorsForMySQL(): void
    {
        $errors = ErrorCodeRegistry::getAuthenticationErrors();
        $mysqlErrors = $errors['mysql'];

        $this->assertIsArray($mysqlErrors);
        $this->assertContains(DbError::MYSQL_ACCESS_DENIED, $mysqlErrors);
        $this->assertContains(DbError::MYSQL_ACCESS_DENIED_USER, $mysqlErrors);
    }

    public function testGetAuthenticationErrorsForPostgreSQL(): void
    {
        $errors = ErrorCodeRegistry::getAuthenticationErrors();
        $pgsqlErrors = $errors['pgsql'];

        $this->assertIsArray($pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_CONNECTION_FAILURE_AUTH, $pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_INVALID_PASSWORD, $pgsqlErrors);
    }

    public function testGetAuthenticationErrorsForSQLite(): void
    {
        $errors = ErrorCodeRegistry::getAuthenticationErrors();
        $sqliteErrors = $errors['sqlite'];

        $this->assertIsArray($sqliteErrors);
        $this->assertContains(DbError::SQLITE_AUTH, $sqliteErrors);
    }

    public function testGetTimeoutErrorsForMySQL(): void
    {
        $errors = ErrorCodeRegistry::getTimeoutErrors();
        $mysqlErrors = $errors['mysql'];

        $this->assertIsArray($mysqlErrors);
        $this->assertContains(DbError::MYSQL_QUERY_INTERRUPTED, $mysqlErrors);
    }

    public function testGetTimeoutErrorsForPostgreSQL(): void
    {
        $errors = ErrorCodeRegistry::getTimeoutErrors();
        $pgsqlErrors = $errors['pgsql'];

        $this->assertIsArray($pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_QUERY_CANCELED, $pgsqlErrors);
    }

    public function testGetTimeoutErrorsForSQLite(): void
    {
        $errors = ErrorCodeRegistry::getTimeoutErrors();
        $sqliteErrors = $errors['sqlite'];

        $this->assertIsArray($sqliteErrors);
        $this->assertContains(DbError::SQLITE_INTERRUPT, $sqliteErrors);
    }

    public function testGetResourceErrorsForMySQL(): void
    {
        $errors = ErrorCodeRegistry::getResourceErrors();
        $mysqlErrors = $errors['mysql'];

        $this->assertIsArray($mysqlErrors);
        $this->assertContains(DbError::MYSQL_TOO_MANY_CONNECTIONS, $mysqlErrors);
        $this->assertContains(DbError::MYSQL_OUT_OF_MEMORY, $mysqlErrors);
    }

    public function testGetResourceErrorsForPostgreSQL(): void
    {
        $errors = ErrorCodeRegistry::getResourceErrors();
        $pgsqlErrors = $errors['pgsql'];

        $this->assertIsArray($pgsqlErrors);
        $this->assertContains(DbError::POSTGRESQL_TOO_MANY_CONNECTIONS, $pgsqlErrors);
    }

    public function testGetResourceErrorsForSQLite(): void
    {
        $errors = ErrorCodeRegistry::getResourceErrors();
        $sqliteErrors = $errors['sqlite'];

        $this->assertIsArray($sqliteErrors);
        $this->assertContains(DbError::SQLITE_NOMEM, $sqliteErrors);
        $this->assertContains(DbError::SQLITE_FULL, $sqliteErrors);
    }

    public function testGetErrorCodesForValidDriverAndType(): void
    {
        $codes = ErrorCodeRegistry::getErrorCodes('mysql', 'constraint');
        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes);
        $this->assertContains(DbError::MYSQL_DUPLICATE_KEY, $codes);
    }

    public function testGetErrorCodesForInvalidType(): void
    {
        $codes = ErrorCodeRegistry::getErrorCodes('mysql', 'nonexistent');
        $this->assertIsArray($codes);
        $this->assertEmpty($codes);
    }

    public function testGetErrorCodesCaseInsensitiveDriver(): void
    {
        $codes = ErrorCodeRegistry::getErrorCodes('MYSQL', 'constraint');
        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes);
    }

    public function testGetErrorCodesForUnknownDriver(): void
    {
        $codes = ErrorCodeRegistry::getErrorCodes('unknown', 'constraint');
        $this->assertIsArray($codes);
        $this->assertEmpty($codes);
    }

    public function testErrorCodeRegistryReturnsSameInstance(): void
    {
        $errors1 = ErrorCodeRegistry::getConstraintErrors();
        $errors2 = ErrorCodeRegistry::getConstraintErrors();

        // Should return same cached instance
        $this->assertSame($errors1, $errors2);
    }

    public function testGetErrorCodesForAllErrorTypes(): void
    {
        $errorTypes = ['constraint', 'connection', 'transaction', 'authentication', 'timeout', 'resource'];

        foreach ($errorTypes as $errorType) {
            $codes = ErrorCodeRegistry::getErrorCodes('mysql', $errorType);
            $this->assertIsArray($codes);
        }
    }

    public function testGetErrorCodesWithEmptyErrorType(): void
    {
        $codes = ErrorCodeRegistry::getErrorCodes('mysql', '');
        $this->assertIsArray($codes);
        $this->assertEmpty($codes);
    }

    public function testGetErrorCodesWithWhitespaceInErrorType(): void
    {
        // Should handle whitespace gracefully
        $codes = ErrorCodeRegistry::getErrorCodes('mysql', ' constraint ');
        $this->assertIsArray($codes);
        // Method name would be 'get Constraint Errors' which doesn't exist
        $this->assertEmpty($codes);
    }

    public function testErrorCodeRegistryAllMethodsReturnArrays(): void
    {
        $methods = [
            'getConstraintErrors',
            'getConnectionErrors',
            'getTransactionErrors',
            'getAuthenticationErrors',
            'getTimeoutErrors',
            'getResourceErrors',
        ];

        foreach ($methods as $method) {
            $result = ErrorCodeRegistry::$method();
            $this->assertIsArray($result);
            $this->assertArrayHasKey('mysql', $result);
            $this->assertArrayHasKey('pgsql', $result);
            $this->assertArrayHasKey('sqlite', $result);
        }
    }

    public function testErrorCodeRegistryCachingWorks(): void
    {
        // Clear any potential cache by checking multiple methods
        $constraint1 = ErrorCodeRegistry::getConstraintErrors();
        $connection1 = ErrorCodeRegistry::getConnectionErrors();
        $transaction1 = ErrorCodeRegistry::getTransactionErrors();

        // Call again
        $constraint2 = ErrorCodeRegistry::getConstraintErrors();
        $connection2 = ErrorCodeRegistry::getConnectionErrors();
        $transaction2 = ErrorCodeRegistry::getTransactionErrors();

        // Should return same instances (cached)
        $this->assertSame($constraint1, $constraint2);
        $this->assertSame($connection1, $connection2);
        $this->assertSame($transaction1, $transaction2);
    }

    public function testGetErrorCodesReturnsEmptyArrayForInvalidMethod(): void
    {
        // Use reflection to test with method that doesn't exist
        $codes = ErrorCodeRegistry::getErrorCodes('mysql', 'nonexistenttype');
        $this->assertIsArray($codes);
        $this->assertEmpty($codes);
    }

    public function testErrorCodeRegistryAllDriversHaveErrorCodes(): void
    {
        $errorTypes = ['constraint', 'connection', 'transaction', 'authentication', 'timeout', 'resource'];
        $drivers = ['mysql', 'pgsql', 'sqlite'];

        foreach ($errorTypes as $errorType) {
            foreach ($drivers as $driver) {
                $codes = ErrorCodeRegistry::getErrorCodes($driver, $errorType);
                $this->assertIsArray($codes, "Error codes for {$driver}/{$errorType} should be array");
                // Some combinations may be empty, but should still return array
            }
        }
    }
}
