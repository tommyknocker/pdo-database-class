<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDOException;
use tommyknocker\pdodb\exceptions\AuthenticationException;
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\exceptions\TimeoutException;
use tommyknocker\pdodb\exceptions\TransactionException;

/**
 * Tests for exception classes.
 */
final class ExceptionTests extends BaseSharedTestCase
{
    public function testDatabaseExceptionBasicProperties(): void
    {
        $previous = new PDOException('Original error', 123);
        $exception = new QueryException(
            'Test message',
            '42S02',
            $previous,
            'mysql',
            'SELECT * FROM users',
            ['key' => 'value']
        );

        $this->assertEquals('Test message', $exception->getMessage());
        $this->assertEquals('mysql', $exception->getDriver());
        $this->assertEquals('SELECT * FROM users', $exception->getQuery());
        $this->assertEquals(['key' => 'value'], $exception->getContext());
        $this->assertEquals('42S02', $exception->getOriginalCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testDatabaseExceptionStringCodeConversion(): void
    {
        // PostgreSQL uses string codes (SQLSTATE)
        $exception = new QueryException(
            'Test message',
            '42P01', // PostgreSQL error code as string
            null,
            'pgsql'
        );

        $this->assertEquals('42P01', $exception->getOriginalCode());
        $this->assertEquals(0, $exception->getCode()); // PDOException code converted to int
    }

    public function testDatabaseExceptionIntCodePreserved(): void
    {
        $exception = new QueryException(
            'Test message',
            12345, // Integer code
            null,
            'mysql'
        );

        $this->assertEquals(12345, $exception->getOriginalCode());
        $this->assertEquals(12345, $exception->getCode());
    }

    public function testDatabaseExceptionAddContext(): void
    {
        $exception = new QueryException('Test message', 0, null, 'mysql');
        $exception->addContext('retry_count', 3);
        $exception->addContext('timestamp', '2024-01-01');

        $context = $exception->getContext();
        $this->assertEquals(3, $context['retry_count']);
        $this->assertEquals('2024-01-01', $context['timestamp']);
    }

    public function testDatabaseExceptionToArray(): void
    {
        $previous = new PDOException('Original error', 123);
        $exception = new QueryException(
            'Test message',
            '42S02',
            $previous,
            'mysql',
            'SELECT * FROM users',
            ['key' => 'value']
        );

        $array = $exception->toArray();

        $this->assertEquals(QueryException::class, $array['exception']);
        $this->assertEquals('Test message', $array['message']);
        $this->assertEquals(0, $array['code']);
        $this->assertEquals('42S02', $array['original_code']);
        $this->assertEquals('mysql', $array['driver']);
        $this->assertEquals('SELECT * FROM users', $array['query']);
        $this->assertEquals(['key' => 'value'], $array['context']);
        $this->assertEquals('query', $array['category']);
        $this->assertFalse($array['retryable']);
        $this->assertArrayHasKey('file', $array);
        $this->assertArrayHasKey('line', $array);
        $this->assertArrayHasKey('trace', $array);
    }

    public function testQueryExceptionProperties(): void
    {
        $exception = new QueryException(
            'Table does not exist',
            '42S02',
            null,
            'mysql',
            'SELECT * FROM nonexistent'
        );

        $this->assertEquals('query', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());
        $this->assertStringContainsString('SELECT * FROM nonexistent', $exception->getDescription());
    }

    public function testConnectionExceptionProperties(): void
    {
        $exception = new ConnectionException(
            'Connection refused',
            'HY000',
            null,
            'mysql',
            null
        );

        $this->assertEquals('connection', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());

        // Test with query
        $exception2 = new ConnectionException(
            'Connection lost',
            'HY000',
            null,
            'mysql',
            'SELECT 1'
        );
        $this->assertStringContainsString('SELECT 1', $exception2->getDescription());
    }

    public function testConstraintViolationExceptionBasic(): void
    {
        $exception = new ConstraintViolationException(
            'Duplicate entry',
            '23000',
            null,
            'mysql',
            'INSERT INTO users (email) VALUES (?)',
            [],
            'users_email_unique',
            'users',
            'email'
        );

        $this->assertEquals('constraint', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());
        $this->assertEquals('users_email_unique', $exception->getConstraintName());
        $this->assertEquals('users', $exception->getTableName());
        $this->assertEquals('email', $exception->getColumnName());
    }

    public function testConstraintViolationExceptionDescription(): void
    {
        $exception = new ConstraintViolationException(
            'Duplicate entry',
            '23000',
            null,
            'mysql',
            null,
            [],
            'users_email_unique',
            'users',
            'email'
        );

        $description = $exception->getDescription();
        $this->assertStringContainsString('Duplicate entry', $description);
        $this->assertStringContainsString('users_email_unique', $description);
        $this->assertStringContainsString('users', $description);
        $this->assertStringContainsString('email', $description);
    }

    public function testConstraintViolationExceptionDescriptionPartial(): void
    {
        $exception = new ConstraintViolationException(
            'Duplicate entry',
            '23000',
            null,
            'mysql',
            null,
            [],
            'users_email_unique',
            null,
            null
        );

        $description = $exception->getDescription();
        $this->assertStringContainsString('Duplicate entry', $description);
        $this->assertStringContainsString('users_email_unique', $description);
        $this->assertStringNotContainsString('Table:', $description);
        $this->assertStringNotContainsString('Column:', $description);
    }

    public function testConstraintViolationExceptionToArray(): void
    {
        $exception = new ConstraintViolationException(
            'Duplicate entry',
            '23000',
            null,
            'mysql',
            null,
            [],
            'users_email_unique',
            'users',
            'email'
        );

        $array = $exception->toArray();
        $this->assertEquals('users_email_unique', $array['constraint_name']);
        $this->assertEquals('users', $array['table_name']);
        $this->assertEquals('email', $array['column_name']);
    }

    public function testTransactionExceptionProperties(): void
    {
        $exception = new TransactionException(
            'Deadlock detected',
            '40001',
            null,
            'mysql',
            'UPDATE users SET balance = balance - 100'
        );

        $this->assertEquals('transaction', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertStringContainsString('UPDATE users', $exception->getDescription());
    }

    public function testAuthenticationExceptionProperties(): void
    {
        $exception = new AuthenticationException(
            'Access denied',
            '28000',
            null,
            'mysql',
            'SELECT * FROM users'
        );

        $this->assertEquals('authentication', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());
        // Query should not be in description for security
        $description = $exception->getDescription();
        $this->assertStringNotContainsString('SELECT', $description);
    }

    public function testTimeoutExceptionBasic(): void
    {
        $exception = new TimeoutException(
            'Query timeout',
            'HY000',
            null,
            'mysql',
            null,
            [],
            null
        );

        $this->assertEquals('timeout', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertNull($exception->getTimeoutSeconds());
    }

    public function testTimeoutExceptionWithTimeoutValue(): void
    {
        $exception = new TimeoutException(
            'Query timeout',
            'HY000',
            null,
            'mysql',
            null,
            [],
            30.5
        );

        $this->assertEquals(30.5, $exception->getTimeoutSeconds());
        $this->assertStringContainsString('30.5', $exception->getDescription());
    }

    public function testTimeoutExceptionToArray(): void
    {
        $exception = new TimeoutException(
            'Query timeout',
            'HY000',
            null,
            'mysql',
            null,
            [],
            30.5
        );

        $array = $exception->toArray();
        $this->assertEquals(30.5, $array['timeout_seconds']);
    }

    public function testResourceExceptionBasic(): void
    {
        $exception = new ResourceException(
            'Too many connections',
            '08004',
            null,
            'mysql',
            null,
            [],
            null
        );

        $this->assertEquals('resource', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertNull($exception->getResourceType());
    }

    public function testResourceExceptionWithResourceType(): void
    {
        $exception = new ResourceException(
            'Out of memory',
            'HY001',
            null,
            'mysql',
            null,
            [],
            'memory'
        );

        $this->assertEquals('memory', $exception->getResourceType());
        $this->assertStringContainsString('memory', $exception->getDescription());
    }

    public function testResourceExceptionToArray(): void
    {
        $exception = new ResourceException(
            'Too many connections',
            '08004',
            null,
            'mysql',
            null,
            [],
            'connections'
        );

        $array = $exception->toArray();
        $this->assertEquals('connections', $array['resource_type']);
    }

    public function testExceptionFactoryCreatesQueryExceptionByDefault(): void
    {
        $pdoException = new PDOException('Unknown error', 12345);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertEquals('Unknown error', $exception->getMessage());
        $this->assertEquals('mysql', $exception->getDriver());
    }

    public function testExceptionFactoryWithContext(): void
    {
        $pdoException = new PDOException('Test error', 123);
        $context = ['operation' => 'insert', 'table' => 'users'];
        $exception = ExceptionFactory::createFromPdoException(
            $pdoException,
            'mysql',
            'INSERT INTO users VALUES (?)',
            $context
        );

        $this->assertEquals('INSERT INTO users VALUES (?)', $exception->getQuery());
        $this->assertEquals($context, $exception->getContext());
    }

    public function testExceptionFactoryInitializesOnce(): void
    {
        // First call should initialize
        $pdoException1 = new PDOException('Error 1', 1);
        $exception1 = ExceptionFactory::createFromPdoException($pdoException1, 'mysql');

        // Second call should use cached initialization
        $pdoException2 = new PDOException('Error 2', 2);
        $exception2 = ExceptionFactory::createFromPdoException($pdoException2, 'mysql');

        $this->assertInstanceOf(QueryException::class, $exception1);
        $this->assertInstanceOf(QueryException::class, $exception2);
    }

    public function testExceptionFactoryExtractErrorCodeForPostgreSQL(): void
    {
        $pdoException = new PDOException('Test error', 0);
        $pdoException->errorInfo = ['23505', '23505', 'duplicate key'];

        $exception = ExceptionFactory::createFromPdoException($pdoException, 'pgsql');

        $this->assertEquals('23505', $exception->getOriginalCode());
    }

    public function testExceptionFactoryExtractErrorCodeForMySQL(): void
    {
        $pdoException = new PDOException('Test error', 1062);

        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        $this->assertEquals('1062', $exception->getOriginalCode());
    }

    public function testExceptionFactoryCreatesConstraintViolationException(): void
    {
        $pdoException = new PDOException('Duplicate entry for key \'users_email_unique\'', 1062);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertStringContainsString('users_email_unique', $exception->getConstraintName());
    }

    public function testExceptionFactoryCreatesConnectionException(): void
    {
        $pdoException = new PDOException('Connection refused', 2002);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    public function testExceptionFactoryCreatesAuthenticationException(): void
    {
        $pdoException = new PDOException('Access denied for user', 1045);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        $this->assertInstanceOf(AuthenticationException::class, $exception);
        $this->assertFalse($exception->isRetryable());
    }

    public function testExceptionFactoryCreatesTimeoutException(): void
    {
        $pdoException = new PDOException('Query timeout exceeded', 0);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        $this->assertInstanceOf(TimeoutException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    public function testExceptionFactoryCreatesResourceException(): void
    {
        $pdoException = new PDOException('Too many connections', 1040);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        $this->assertInstanceOf(ResourceException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    public function testExceptionFactoryCreatesTransactionException(): void
    {
        $pdoException = new PDOException('Deadlock found when trying to get lock', 1213);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        $this->assertInstanceOf(TransactionException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    public function testExceptionFactoryPriorityOrdering(): void
    {
        // Constraint violation should be detected before generic query error
        $pdoException = new PDOException('Duplicate entry for key \'test\'', 1062);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        // Should be ConstraintViolationException, not QueryException
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertNotInstanceOf(QueryException::class, $exception);
    }

    public function testExceptionFactoryPostgreSQLErrorInfoExtraction(): void
    {
        $pdoException = new PDOException('duplicate key value violates unique constraint', 0);
        $pdoException->errorInfo = ['23505', '23505', 'duplicate key value violates unique constraint "users_email_key"'];

        $exception = ExceptionFactory::createFromPdoException($pdoException, 'pgsql');

        $this->assertEquals('23505', $exception->getOriginalCode());
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
    }

    public function testExceptionFactoryFallbackToQueryException(): void
    {
        $pdoException = new PDOException('Some unknown error', 99999);
        $exception = ExceptionFactory::createFromPdoException($pdoException, 'mysql');

        // Should fallback to QueryException
        $this->assertInstanceOf(QueryException::class, $exception);
    }
}
