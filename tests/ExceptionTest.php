<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use PDOException;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\exceptions\AuthenticationException;
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\exceptions\TimeoutException;
use tommyknocker\pdodb\exceptions\TransactionException;

/**
 * Tests for the exception hierarchy and factory.
 */
class ExceptionTest extends TestCase
{
    /**
     * Test basic DatabaseException functionality.
     */
    public function testDatabaseException(): void
    {
        $pdoException = new PDOException('Test error', 12345);
        $exception = new QueryException(
            'Test error',
            12345,
            $pdoException,
            'mysql',
            'SELECT * FROM users',
            ['test' => 'context']
        );

        $this->assertEquals('Test error', $exception->getMessage());
        $this->assertEquals(12345, $exception->getCode());
        $this->assertEquals('mysql', $exception->getDriver());
        $this->assertEquals('SELECT * FROM users', $exception->getQuery());
        $this->assertEquals(['test' => 'context'], $exception->getContext());
        $this->assertEquals('query', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());

        // Test context manipulation
        $exception->addContext('new_key', 'new_value');
        $this->assertEquals('new_value', $exception->getContext()['new_key']);

        // Test array conversion
        $array = $exception->toArray();
        $this->assertArrayHasKey('exception', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('driver', $array);
        $this->assertArrayHasKey('query', $array);
        $this->assertArrayHasKey('context', $array);
        $this->assertArrayHasKey('category', $array);
        $this->assertArrayHasKey('retryable', $array);
    }

    /**
     * Test ConnectionException.
     */
    public function testConnectionException(): void
    {
        $exception = new ConnectionException(
            'Connection failed',
            2006,
            null,
            'mysql',
            'SELECT 1',
            []
        );

        $this->assertEquals('connection', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertStringContainsString('Query: SELECT 1', $exception->getDescription());
    }

    /**
     * Test ConstraintViolationException.
     */
    public function testConstraintViolationException(): void
    {
        $exception = new ConstraintViolationException(
            'Duplicate entry',
            1062,
            null,
            'mysql',
            'INSERT INTO users (email) VALUES (?)',
            [],
            'unique_email',
            'users',
            'email'
        );

        $this->assertEquals('constraint', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());
        $this->assertEquals('unique_email', $exception->getConstraintName());
        $this->assertEquals('users', $exception->getTableName());
        $this->assertEquals('email', $exception->getColumnName());
        $this->assertStringContainsString('Constraint: unique_email', $exception->getDescription());
        $this->assertStringContainsString('Table: users', $exception->getDescription());
        $this->assertStringContainsString('Column: email', $exception->getDescription());

        $array = $exception->toArray();
        $this->assertEquals('unique_email', $array['constraint_name']);
        $this->assertEquals('users', $array['table_name']);
        $this->assertEquals('email', $array['column_name']);
    }

    /**
     * Test TransactionException.
     */
    public function testTransactionException(): void
    {
        $exception = new TransactionException(
            'Deadlock detected',
            40001,
            null,
            'pgsql',
            'UPDATE users SET balance = balance - 100',
            []
        );

        $this->assertEquals('transaction', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test AuthenticationException.
     */
    public function testAuthenticationException(): void
    {
        $exception = new AuthenticationException(
            'Access denied',
            1045,
            null,
            'mysql',
            null, // Don't include query for security
            []
        );

        $this->assertEquals('authentication', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());
        $this->assertEquals('Access denied', $exception->getDescription());
    }

    /**
     * Test TimeoutException.
     */
    public function testTimeoutException(): void
    {
        $exception = new TimeoutException(
            'Query timeout',
            57014,
            null,
            'pgsql',
            'SELECT * FROM large_table',
            [],
            30.0
        );

        $this->assertEquals('timeout', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertEquals(30.0, $exception->getTimeoutSeconds());
        $this->assertStringContainsString('Timeout: 30s', $exception->getDescription());

        $array = $exception->toArray();
        $this->assertEquals(30.0, $array['timeout_seconds']);
    }

    /**
     * Test ResourceException.
     */
    public function testResourceException(): void
    {
        $exception = new ResourceException(
            'Too many connections',
            1040,
            null,
            'mysql',
            'SELECT 1',
            [],
            'connections'
        );

        $this->assertEquals('resource', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertEquals('connections', $exception->getResourceType());
        $this->assertStringContainsString('Resource: connections', $exception->getDescription());

        $array = $exception->toArray();
        $this->assertEquals('connections', $array['resource_type']);
    }

    /**
     * Test ExceptionFactory with connection errors.
     */
    public function testExceptionFactoryConnectionErrors(): void
    {
        // MySQL connection errors
        $mysqlConnectionError = new PDOException('MySQL server has gone away', 2006);
        $exception = ExceptionFactory::createFromPdoException($mysqlConnectionError, 'mysql');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // PostgreSQL connection errors
        $pgsqlConnectionError = new PDOException('Connection refused', 0);
        $pgsqlConnectionError->errorInfo = ['08006', '08006', 'Connection refused'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlConnectionError, 'pgsql');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // SQLite connection errors
        $sqliteConnectionError = new PDOException('Unable to open database file', 14);
        $exception = ExceptionFactory::createFromPdoException($sqliteConnectionError, 'sqlite');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with constraint violations.
     */
    public function testExceptionFactoryConstraintViolations(): void
    {
        // MySQL duplicate key
        $mysqlDuplicate = new PDOException('Duplicate entry \'test@example.com\' for key \'unique_email\'', 1062);
        $exception = ExceptionFactory::createFromPdoException($mysqlDuplicate, 'mysql');
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // PostgreSQL unique violation
        $pgsqlUnique = new PDOException('duplicate key value violates unique constraint "users_email_key"', 0);
        $pgsqlUnique->errorInfo = ['23505', '23505', 'duplicate key value violates unique constraint "users_email_key"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlUnique, 'pgsql');
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // SQLite constraint
        $sqliteConstraint = new PDOException('UNIQUE constraint failed: users.email', 19);
        $exception = ExceptionFactory::createFromPdoException($sqliteConstraint, 'sqlite');
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertFalse($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with transaction errors.
     */
    public function testExceptionFactoryTransactionErrors(): void
    {
        // PostgreSQL deadlock
        $pgsqlDeadlock = new PDOException('deadlock detected', 0);
        $pgsqlDeadlock->errorInfo = ['40001', '40001', 'deadlock detected'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlDeadlock, 'pgsql');
        $this->assertInstanceOf(TransactionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // SQLite busy
        $sqliteBusy = new PDOException('database is locked', 5);
        $exception = ExceptionFactory::createFromPdoException($sqliteBusy, 'sqlite');
        $this->assertInstanceOf(TransactionException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with authentication errors.
     */
    public function testExceptionFactoryAuthenticationErrors(): void
    {
        // MySQL access denied
        $mysqlAuth = new PDOException('Access denied for user \'testuser\'@\'localhost\'', 1045);
        $exception = ExceptionFactory::createFromPdoException($mysqlAuth, 'mysql');
        $this->assertInstanceOf(AuthenticationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // PostgreSQL authentication failed
        $pgsqlAuth = new PDOException('password authentication failed for user "testuser"', 0);
        $pgsqlAuth->errorInfo = ['28P01', '28P01', 'password authentication failed for user "testuser"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlAuth, 'pgsql');
        $this->assertInstanceOf(AuthenticationException::class, $exception);
        $this->assertFalse($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with timeout errors.
     */
    public function testExceptionFactoryTimeoutErrors(): void
    {
        // PostgreSQL timeout
        $pgsqlTimeout = new PDOException('canceling statement due to statement timeout', 0);
        $pgsqlTimeout->errorInfo = ['57014', '57014', 'canceling statement due to statement timeout'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlTimeout, 'pgsql');
        $this->assertInstanceOf(TimeoutException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // MySQL timeout (this is actually a connection error)
        $mysqlTimeout = new PDOException('Lost connection to MySQL server during query', 2006);
        $exception = ExceptionFactory::createFromPdoException($mysqlTimeout, 'mysql');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with resource errors.
     */
    public function testExceptionFactoryResourceErrors(): void
    {
        // MySQL too many connections
        $mysqlResource = new PDOException('Too many connections', 1040);
        $exception = ExceptionFactory::createFromPdoException($mysqlResource, 'mysql');
        $this->assertInstanceOf(ResourceException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // PostgreSQL too many connections
        $pgsqlResource = new PDOException('too many connections for role "testuser"', 0);
        $pgsqlResource->errorInfo = ['53300', '53300', 'too many connections for role "testuser"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlResource, 'pgsql');
        $this->assertInstanceOf(ResourceException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with query errors (default case).
     */
    public function testExceptionFactoryQueryErrors(): void
    {
        // Unknown column error
        $unknownColumn = new PDOException('Unknown column \'invalid_column\' in \'field list\'', 0);
        $unknownColumn->errorInfo = ['42S22', '42S22', 'Unknown column \'invalid_column\' in \'field list\''];
        $exception = ExceptionFactory::createFromPdoException($unknownColumn, 'mysql');
        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // Table doesn't exist
        $tableNotFound = new PDOException('Table \'testdb.nonexistent_table\' doesn\'t exist', 0);
        $tableNotFound->errorInfo = ['42S02', '42S02', 'Table \'testdb.nonexistent_table\' doesn\'t exist'];
        $exception = ExceptionFactory::createFromPdoException($tableNotFound, 'mysql');
        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertFalse($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with context and query.
     */
    public function testExceptionFactoryWithContext(): void
    {
        $pdoException = new PDOException('Test error', 12345);
        $exception = ExceptionFactory::createFromPdoException(
            $pdoException,
            'mysql',
            'SELECT * FROM users WHERE id = ?',
            ['operation' => 'test', 'user_id' => 123]
        );

        $this->assertEquals('mysql', $exception->getDriver());
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $exception->getQuery());
        $this->assertEquals(['operation' => 'test', 'user_id' => 123], $exception->getContext());
    }

    /**
     * Test constraint violation parsing.
     */
    public function testConstraintViolationParsing(): void
    {
        // Test MySQL constraint parsing
        $mysqlError = new PDOException('Duplicate entry \'test@example.com\' for key \'unique_email\' in table \'users\'', 1062);
        $exception = ExceptionFactory::createFromPdoException($mysqlError, 'mysql');
        
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertEquals('unique_email', $exception->getConstraintName());
        $this->assertEquals('users', $exception->getTableName());

        // Test PostgreSQL constraint parsing
        $pgsqlError = new PDOException('duplicate key value violates unique constraint "users_email_key"', 0);
        $pgsqlError->errorInfo = ['23505', '23505', 'duplicate key value violates unique constraint "users_email_key"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlError, 'pgsql');
        
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertEquals('users_email_key', $exception->getConstraintName());
    }
}
