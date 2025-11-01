<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\AuthenticationException;
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\exceptions\strategies\AuthenticationStrategy;
use tommyknocker\pdodb\exceptions\strategies\ConnectionStrategy;
use tommyknocker\pdodb\exceptions\strategies\ConstraintViolationStrategy;
use tommyknocker\pdodb\exceptions\strategies\QueryStrategy;
use tommyknocker\pdodb\exceptions\strategies\ResourceStrategy;
use tommyknocker\pdodb\exceptions\strategies\TimeoutStrategy;
use tommyknocker\pdodb\exceptions\strategies\TransactionStrategy;
use tommyknocker\pdodb\exceptions\TimeoutException;
use tommyknocker\pdodb\exceptions\TransactionException;
use tommyknocker\pdodb\helpers\DbError;

/**
 * Tests for error detection strategies.
 */
final class ErrorDetectionStrategyTests extends BaseSharedTestCase
{
    public function testConstraintViolationStrategyMatchesErrorCode(): void
    {
        $strategy = new ConstraintViolationStrategy();

        // MySQL duplicate key
        $this->assertTrue($strategy->isMatch((string) DbError::MYSQL_DUPLICATE_KEY, ''));
        // PostgreSQL unique violation
        $this->assertTrue($strategy->isMatch(DbError::POSTGRESQL_UNIQUE_VIOLATION, ''));
        // SQLite constraint
        $this->assertTrue($strategy->isMatch((string) DbError::SQLITE_CONSTRAINT, ''));
    }

    public function testConstraintViolationStrategyMatchesMessage(): void
    {
        $strategy = new ConstraintViolationStrategy();

        $this->assertTrue($strategy->isMatch('', 'duplicate entry'));
        $this->assertTrue($strategy->isMatch('', 'foreign key constraint'));
        $this->assertTrue($strategy->isMatch('', 'unique constraint'));
        $this->assertTrue($strategy->isMatch('', 'check constraint'));
        $this->assertTrue($strategy->isMatch('', 'not null constraint'));
        $this->assertTrue($strategy->isMatch('', 'constraint violation'));
        $this->assertTrue($strategy->isMatch('', 'duplicate key'));
        $this->assertTrue($strategy->isMatch('', 'integrity constraint'));
    }

    public function testConstraintViolationStrategyExceptionClass(): void
    {
        $strategy = new ConstraintViolationStrategy();

        $this->assertEquals(ConstraintViolationException::class, $strategy->getExceptionClass());
    }

    public function testConstraintViolationStrategyPriority(): void
    {
        $strategy = new ConstraintViolationStrategy();

        $this->assertEquals(10, $strategy->getPriority());
    }

    public function testAuthenticationStrategyMatchesErrorCode(): void
    {
        $strategy = new AuthenticationStrategy();

        // MySQL access denied
        $this->assertTrue($strategy->isMatch((string) DbError::MYSQL_ACCESS_DENIED, ''));
        // PostgreSQL connection failure auth
        $this->assertTrue($strategy->isMatch(DbError::POSTGRESQL_CONNECTION_FAILURE_AUTH, ''));
        // SQLite auth
        $this->assertTrue($strategy->isMatch((string) DbError::SQLITE_AUTH, ''));
    }

    public function testAuthenticationStrategyMatchesMessage(): void
    {
        $strategy = new AuthenticationStrategy();

        $this->assertTrue($strategy->isMatch('', 'access denied'));
        $this->assertTrue($strategy->isMatch('', 'authentication failed'));
        $this->assertTrue($strategy->isMatch('', 'invalid password'));
        $this->assertTrue($strategy->isMatch('', 'unknown user'));
        $this->assertTrue($strategy->isMatch('', 'permission denied'));
        $this->assertTrue($strategy->isMatch('', 'insufficient privilege'));
    }

    public function testAuthenticationStrategyExceptionClass(): void
    {
        $strategy = new AuthenticationStrategy();

        $this->assertEquals(AuthenticationException::class, $strategy->getExceptionClass());
    }

    public function testAuthenticationStrategyPriority(): void
    {
        $strategy = new AuthenticationStrategy();

        $this->assertEquals(20, $strategy->getPriority());
    }

    public function testTimeoutStrategyMatchesErrorCode(): void
    {
        $strategy = new TimeoutStrategy();

        // MySQL query interrupted
        $this->assertTrue($strategy->isMatch((string) DbError::MYSQL_QUERY_INTERRUPTED, ''));
        // PostgreSQL query canceled
        $this->assertTrue($strategy->isMatch(DbError::POSTGRESQL_QUERY_CANCELED, ''));
        // SQLite interrupt
        $this->assertTrue($strategy->isMatch((string) DbError::SQLITE_INTERRUPT, ''));
    }

    public function testTimeoutStrategyMatchesMessage(): void
    {
        $strategy = new TimeoutStrategy();

        $this->assertTrue($strategy->isMatch('', 'timeout'));
        $this->assertTrue($strategy->isMatch('', 'timed out'));
        $this->assertTrue($strategy->isMatch('', 'query timeout'));
        $this->assertTrue($strategy->isMatch('', 'connection timeout'));
        $this->assertTrue($strategy->isMatch('', 'lock timeout'));
    }

    public function testTimeoutStrategyExceptionClass(): void
    {
        $strategy = new TimeoutStrategy();

        $this->assertEquals(TimeoutException::class, $strategy->getExceptionClass());
    }

    public function testTimeoutStrategyPriority(): void
    {
        $strategy = new TimeoutStrategy();

        $this->assertEquals(30, $strategy->getPriority());
    }

    public function testTransactionStrategyMatchesErrorCode(): void
    {
        $strategy = new TransactionStrategy();

        // MySQL deadlock
        $this->assertTrue($strategy->isMatch((string) DbError::MYSQL_DEADLOCK, ''));
        // PostgreSQL deadlock detected
        $this->assertTrue($strategy->isMatch(DbError::POSTGRESQL_DEADLOCK_DETECTED, ''));
        // SQLite busy
        $this->assertTrue($strategy->isMatch((string) DbError::SQLITE_BUSY, ''));
    }

    public function testTransactionStrategyMatchesMessage(): void
    {
        $strategy = new TransactionStrategy();

        $this->assertTrue($strategy->isMatch('', 'deadlock'));
        $this->assertTrue($strategy->isMatch('', 'lock timeout'));
        $this->assertTrue($strategy->isMatch('', 'serialization failure'));
        $this->assertTrue($strategy->isMatch('', 'could not serialize'));
        $this->assertTrue($strategy->isMatch('', 'transaction'));
        $this->assertTrue($strategy->isMatch('', 'lock'));
        $this->assertTrue($strategy->isMatch('', 'concurrent update'));
    }

    public function testTransactionStrategyExceptionClass(): void
    {
        $strategy = new TransactionStrategy();

        $this->assertEquals(TransactionException::class, $strategy->getExceptionClass());
    }

    public function testTransactionStrategyPriority(): void
    {
        $strategy = new TransactionStrategy();

        $this->assertEquals(50, $strategy->getPriority());
    }

    public function testConnectionStrategyMatchesErrorCode(): void
    {
        $strategy = new ConnectionStrategy();

        // MySQL cannot connect
        $this->assertTrue($strategy->isMatch((string) DbError::MYSQL_CANNOT_CONNECT, ''));
        // PostgreSQL connection failure
        $this->assertTrue($strategy->isMatch(DbError::POSTGRESQL_CONNECTION_FAILURE, ''));
        // SQLite cannot open
        $this->assertTrue($strategy->isMatch((string) DbError::SQLITE_CANTOPEN, ''));
    }

    public function testConnectionStrategyMatchesMessage(): void
    {
        $strategy = new ConnectionStrategy();

        $this->assertTrue($strategy->isMatch('', 'connection'));
        $this->assertTrue($strategy->isMatch('', 'server has gone away'));
        $this->assertTrue($strategy->isMatch('', 'lost connection'));
        $this->assertTrue($strategy->isMatch('', "can't connect"));
        $this->assertTrue($strategy->isMatch('', 'connection refused'));
        $this->assertTrue($strategy->isMatch('', 'connection timeout'));
        $this->assertTrue($strategy->isMatch('', 'connection reset'));
        $this->assertTrue($strategy->isMatch('', 'broken pipe'));
    }

    public function testConnectionStrategyExceptionClass(): void
    {
        $strategy = new ConnectionStrategy();

        $this->assertEquals(ConnectionException::class, $strategy->getExceptionClass());
    }

    public function testConnectionStrategyPriority(): void
    {
        $strategy = new ConnectionStrategy();

        $this->assertEquals(60, $strategy->getPriority());
    }

    public function testResourceStrategyMatchesErrorCode(): void
    {
        $strategy = new ResourceStrategy();

        // MySQL too many connections
        $this->assertTrue($strategy->isMatch((string) DbError::MYSQL_TOO_MANY_CONNECTIONS, ''));
        // PostgreSQL too many connections
        $this->assertTrue($strategy->isMatch(DbError::POSTGRESQL_TOO_MANY_CONNECTIONS, ''));
        // SQLite no memory
        $this->assertTrue($strategy->isMatch((string) DbError::SQLITE_NOMEM, ''));
    }

    public function testResourceStrategyMatchesMessage(): void
    {
        $strategy = new ResourceStrategy();

        $this->assertTrue($strategy->isMatch('', 'too many connections'));
        $this->assertTrue($strategy->isMatch('', 'out of memory'));
        $this->assertTrue($strategy->isMatch('', 'disk full'));
        $this->assertTrue($strategy->isMatch('', 'no space left'));
        $this->assertTrue($strategy->isMatch('', 'resource limit'));
        $this->assertTrue($strategy->isMatch('', 'memory allocation'));
    }

    public function testResourceStrategyExceptionClass(): void
    {
        $strategy = new ResourceStrategy();

        $this->assertEquals(ResourceException::class, $strategy->getExceptionClass());
    }

    public function testResourceStrategyPriority(): void
    {
        $strategy = new ResourceStrategy();

        $this->assertEquals(70, $strategy->getPriority());
    }

    public function testQueryStrategyNeverMatches(): void
    {
        $strategy = new QueryStrategy();

        // QueryStrategy should never match directly (it's a fallback)
        $this->assertFalse($strategy->isMatch('', ''));
        $this->assertFalse($strategy->isMatch('1062', 'duplicate entry'));
        $this->assertFalse($strategy->isMatch('2002', 'connection refused'));
    }

    public function testQueryStrategyExceptionClass(): void
    {
        $strategy = new QueryStrategy();

        $this->assertEquals(QueryException::class, $strategy->getExceptionClass());
    }

    public function testQueryStrategyPriority(): void
    {
        $strategy = new QueryStrategy();

        // QueryStrategy should have the lowest priority (highest number)
        $this->assertEquals(1000, $strategy->getPriority());
    }

    public function testAbstractStrategyCodeMatchWithNumericString(): void
    {
        $strategy = new ConstraintViolationStrategy();

        // Test with numeric string code
        $this->assertTrue($strategy->isMatch((string) DbError::MYSQL_DUPLICATE_KEY, ''));
    }

    public function testAbstractStrategyCodeMatchWithInteger(): void
    {
        $strategy = new ConstraintViolationStrategy();

        // Test that numeric string matches integer constant
        $this->assertTrue($strategy->isMatch('1062', ''));
    }

    public function testAbstractStrategyCodeMatchWithNonNumericString(): void
    {
        $strategy = new ConstraintViolationStrategy();

        // PostgreSQL codes are strings
        $this->assertTrue($strategy->isMatch(DbError::POSTGRESQL_UNIQUE_VIOLATION, ''));
    }

    public function testAbstractStrategyMessageMatchCaseSensitive(): void
    {
        $strategy = new ConstraintViolationStrategy();

        // str_contains is case-sensitive, patterns are lowercase
        $this->assertTrue($strategy->isMatch('', 'duplicate entry'));
        $this->assertFalse($strategy->isMatch('', 'Duplicate Entry')); // Case-sensitive, uppercase doesn't match
        $this->assertTrue($strategy->isMatch('', 'Error: duplicate entry for key'));
    }

    public function testAbstractStrategyMessageMatchPartial(): void
    {
        $strategy = new ConnectionStrategy();

        // Should match if pattern is contained in message (case-sensitive, patterns are lowercase)
        $this->assertTrue($strategy->isMatch('', 'lost connection to MySQL server'));
        $this->assertTrue($strategy->isMatch('', 'connection timeout occurred'));
    }

    public function testStrategyPriorityOrdering(): void
    {
        $constraint = new ConstraintViolationStrategy();
        $auth = new AuthenticationStrategy();
        $timeout = new TimeoutStrategy();
        $transaction = new TransactionStrategy();
        $connection = new ConnectionStrategy();
        $resource = new ResourceStrategy();
        $query = new QueryStrategy();

        // Higher priority strategies should have lower numbers
        $this->assertLessThan($auth->getPriority(), $constraint->getPriority());
        $this->assertLessThan($timeout->getPriority(), $auth->getPriority());
        $this->assertLessThan($transaction->getPriority(), $timeout->getPriority());
        $this->assertLessThan($connection->getPriority(), $transaction->getPriority());
        $this->assertLessThan($resource->getPriority(), $connection->getPriority());
        $this->assertLessThan($query->getPriority(), $resource->getPriority());
    }

    public function testStrategyDoesNotMatchWhenNoCodeOrMessageMatch(): void
    {
        $strategy = new ConstraintViolationStrategy();

        // Should not match if neither code nor message match
        $this->assertFalse($strategy->isMatch('99999', 'Some random error message'));
    }

    public function testStrategyMatchesWithEitherCodeOrMessage(): void
    {
        $strategy = new ConstraintViolationStrategy();

        // Should match if code matches, even if message doesn't
        $this->assertTrue($strategy->isMatch((string) DbError::MYSQL_DUPLICATE_KEY, 'random message'));

        // Should match if message matches, even if code doesn't
        $this->assertTrue($strategy->isMatch('99999', 'duplicate entry for key'));
    }

    public function testAllStrategiesHaveValidExceptionClasses(): void
    {
        $strategies = [
            new ConstraintViolationStrategy(),
            new AuthenticationStrategy(),
            new TimeoutStrategy(),
            new TransactionStrategy(),
            new ConnectionStrategy(),
            new ResourceStrategy(),
            new QueryStrategy(),
        ];

        foreach ($strategies as $strategy) {
            $exceptionClass = $strategy->getExceptionClass();
            $this->assertTrue(class_exists($exceptionClass), "Exception class {$exceptionClass} does not exist");
            $this->assertTrue(method_exists($exceptionClass, '__construct'), "Exception class {$exceptionClass} is not instantiable");
        }
    }
}
