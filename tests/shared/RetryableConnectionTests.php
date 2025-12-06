<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
use tommyknocker\pdodb\connection\Connection;
use tommyknocker\pdodb\connection\ExponentialBackoffRetryStrategy;
use tommyknocker\pdodb\connection\RetryableConnection;
use tommyknocker\pdodb\dialects\sqlite\SqliteDialect;

/**
 * Tests for RetryableConnection class.
 */
final class RetryableConnectionTests extends BaseSharedTestCase
{
    protected function createRetryableConnection(array $retryConfig = []): RetryableConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $dialect = new SqliteDialect();
        return new RetryableConnection($pdo, $dialect, null, $retryConfig);
    }

    public function testGetRetryConfig(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 5,
            'delay_ms' => 2000,
            'backoff_multiplier' => 3.0,
            'max_delay_ms' => 20000,
            'retryable_errors' => [2002, 2006],
            'driver' => 'sqlite',
        ];

        $connection = $this->createRetryableConnection($config);
        $retrievedConfig = $connection->getRetryConfig();

        $this->assertEquals($config['enabled'], $retrievedConfig['enabled']);
        $this->assertEquals($config['max_attempts'], $retrievedConfig['max_attempts']);
        $this->assertEquals($config['delay_ms'], $retrievedConfig['delay_ms']);
        $this->assertEquals($config['backoff_multiplier'], $retrievedConfig['backoff_multiplier']);
        $this->assertEquals($config['max_delay_ms'], $retrievedConfig['max_delay_ms']);
        $this->assertEquals($config['retryable_errors'], $retrievedConfig['retryable_errors']);
    }

    public function testGetRetryConfigWithDefaults(): void
    {
        $connection = $this->createRetryableConnection();
        $config = $connection->getRetryConfig();

        $this->assertFalse($config['enabled']);
        $this->assertEquals(3, $config['max_attempts']);
        $this->assertEquals(1000, $config['delay_ms']);
        $this->assertEquals(2.0, $config['backoff_multiplier']);
        $this->assertEquals(10000, $config['max_delay_ms']);
        $this->assertEquals([], $config['retryable_errors']);
        $this->assertEquals('sqlite', $config['driver']);
    }

    public function testGetCurrentAttempt(): void
    {
        $connection = $this->createRetryableConnection();
        $attempt = $connection->getCurrentAttempt();

        $this->assertEquals(0, $attempt);
    }

    public function testExecuteWithRetryDisabled(): void
    {
        $connection = $this->createRetryableConnection(['enabled' => false]);
        $connection->prepare('SELECT 1');

        $result = $connection->execute();
        $this->assertInstanceOf(\PDOStatement::class, $result);
    }

    public function testQueryWithRetryDisabled(): void
    {
        $connection = $this->createRetryableConnection(['enabled' => false]);
        $result = $connection->query('SELECT 1');

        $this->assertInstanceOf(\PDOStatement::class, $result);
    }

    public function testPrepareWithRetryDisabled(): void
    {
        $connection = $this->createRetryableConnection(['enabled' => false]);
        $result = $connection->prepare('SELECT :value');

        $this->assertSame($connection, $result);
    }

    public function testTransactionWithRetryDisabled(): void
    {
        $connection = $this->createRetryableConnection(['enabled' => false]);
        $result = $connection->transaction();

        $this->assertTrue($result);
    }

    public function testInvalidRetryConfigThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createRetryableConnection([
            'enabled' => 'not a boolean',
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ]);
    }

    public function testRetryConfigDefaultsDriver(): void
    {
        $connection = $this->createRetryableConnection([
            'enabled' => false,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
        ]);

        $config = $connection->getRetryConfig();
        $this->assertEquals('sqlite', $config['driver']);
    }

    public function testSuccessfulOperationDoesNotRetry(): void
    {
        $connection = $this->createRetryableConnection([
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ]);

        $initialAttempt = $connection->getCurrentAttempt();
        $this->assertEquals(0, $initialAttempt);

        $connection->prepare('SELECT 1');
        $result = $connection->execute();

        $this->assertInstanceOf(\PDOStatement::class, $result);
        // After successful operation, attempt reflects the last attempt (1 for first try)
        $this->assertEquals(1, $connection->getCurrentAttempt());
    }

    public function testRetryableConnectionInheritsFromConnection(): void
    {
        $connection = $this->createRetryableConnection();
        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testRetryableConnectionCanExecuteQueries(): void
    {
        $connection = $this->createRetryableConnection();
        $connection->query('CREATE TABLE test_retry (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->query("INSERT INTO test_retry (name) VALUES ('test')");

        $stmt = $connection->query('SELECT * FROM test_retry');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('test', $result[0]['name']);
    }

    public function testRetryableConnectionCanPrepareAndExecute(): void
    {
        $connection = $this->createRetryableConnection();
        $connection->query('CREATE TABLE test_retry (id INTEGER PRIMARY KEY, name TEXT)');

        $connection->prepare('INSERT INTO test_retry (name) VALUES (:name)');
        $connection->execute(['name' => 'test']);

        $connection->prepare('SELECT * FROM test_retry');
        $stmt = $connection->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('test', $result[0]['name']);
    }

    public function testWaitBeforeRetry(): void
    {
        $connection = $this->createRetryableConnection([
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 10, // Small delay for testing
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 100,
        ]);

        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('waitBeforeRetry');
        $method->setAccessible(true);

        // Use reflection to set currentAttempt
        $attemptProperty = $reflection->getProperty('currentAttempt');
        $attemptProperty->setAccessible(true);
        $attemptProperty->setValue($connection, 1);

        $start = microtime(true);
        $method->invoke($connection);
        $end = microtime(true);

        // Should have waited at least 10ms (with some tolerance)
        $elapsed = ($end - $start) * 1000; // Convert to milliseconds
        $this->assertGreaterThanOrEqual(9, $elapsed); // Allow some tolerance
    }

    public function testRetryConfigValidation(): void
    {
        $reflection = new \ReflectionClass(RetryableConnection::class);
        $method = $reflection->getMethod('validateRetryConfig');
        $method->setAccessible(true);

        $pdo = new PDO('sqlite::memory:');
        $dialect = new SqliteDialect();
        $connection = new RetryableConnection($pdo, $dialect, null, [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ]);

        // Should not throw exception for valid config
        $method->invoke($connection);
        $this->assertTrue(true);
    }

    public function testRetryOperationWithDifferentMethods(): void
    {
        $connection = $this->createRetryableConnection([
            'enabled' => false, // Disable retry for this test
        ]);

        // Test that all methods can be called
        $connection->prepare('SELECT 1');
        $stmt = $connection->execute();
        $this->assertInstanceOf(\PDOStatement::class, $stmt);

        $stmt = $connection->query('SELECT 1');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);

        $result = $connection->transaction();
        $this->assertTrue($result);
    }

    public function testRetryConfigMergesWithDefaults(): void
    {
        $connection = $this->createRetryableConnection([
            'enabled' => true,
            'max_attempts' => 5,
            // Other values should use defaults
        ]);

        $config = $connection->getRetryConfig();
        $this->assertTrue($config['enabled']);
        $this->assertEquals(5, $config['max_attempts']);
        $this->assertEquals(1000, $config['delay_ms']); // Default
        $this->assertEquals(2.0, $config['backoff_multiplier']); // Default
        $this->assertEquals(10000, $config['max_delay_ms']); // Default
    }

    public function testExponentialBackoffDelayCalculation(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 5,
            'delay_ms' => 100,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 1000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // Attempt 1: delay = 100 * 2^0 = 100ms
        $delay1 = $strategy->calculateDelay(1, $config);
        $this->assertEquals(100, $delay1);

        // Attempt 2: delay = 100 * 2^1 = 200ms
        $delay2 = $strategy->calculateDelay(2, $config);
        $this->assertEquals(200, $delay2);

        // Attempt 3: delay = 100 * 2^2 = 400ms
        $delay3 = $strategy->calculateDelay(3, $config);
        $this->assertEquals(400, $delay3);

        // Attempt 4: delay = 100 * 2^3 = 800ms
        $delay4 = $strategy->calculateDelay(4, $config);
        $this->assertEquals(800, $delay4);

        // Attempt 5: delay = 100 * 2^4 = 1600ms, but capped at max_delay_ms = 1000ms
        $delay5 = $strategy->calculateDelay(5, $config);
        $this->assertEquals(1000, $delay5);
    }

    public function testExponentialBackoffMaxDelayEnforcement(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 10,
            'delay_ms' => 1000,
            'backoff_multiplier' => 3.0,
            'max_delay_ms' => 5000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // Attempt 1: 1000ms
        $delay1 = $strategy->calculateDelay(1, $config);
        $this->assertEquals(1000, $delay1);

        // Attempt 2: 3000ms
        $delay2 = $strategy->calculateDelay(2, $config);
        $this->assertEquals(3000, $delay2);

        // Attempt 3: 9000ms, but capped at 5000ms
        $delay3 = $strategy->calculateDelay(3, $config);
        $this->assertEquals(5000, $delay3);

        // Attempt 4: would be 27000ms, but capped at 5000ms
        $delay4 = $strategy->calculateDelay(4, $config);
        $this->assertEquals(5000, $delay4);
    }

    public function testExponentialBackoffWithDifferentMultipliers(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();

        // Test with multiplier 1.5
        $config1 = [
            'enabled' => true,
            'max_attempts' => 5,
            'delay_ms' => 100,
            'backoff_multiplier' => 1.5,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        $delay1 = $strategy->calculateDelay(1, $config1);
        $this->assertEquals(100, $delay1);

        $delay2 = $strategy->calculateDelay(2, $config1);
        $this->assertEquals(150, $delay2); // 100 * 1.5

        $delay3 = $strategy->calculateDelay(3, $config1);
        $this->assertEquals(225, $delay3); // 100 * 1.5^2

        // Test with multiplier 3.0
        $config2 = [
            'enabled' => true,
            'max_attempts' => 5,
            'delay_ms' => 50,
            'backoff_multiplier' => 3.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        $delay1b = $strategy->calculateDelay(1, $config2);
        $this->assertEquals(50, $delay1b);

        $delay2b = $strategy->calculateDelay(2, $config2);
        $this->assertEquals(150, $delay2b); // 50 * 3

        $delay3b = $strategy->calculateDelay(3, $config2);
        $this->assertEquals(450, $delay3b); // 50 * 3^2
    }

    public function testShouldRetryWithRetryableError(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002, 2006], // MySQL connection errors
            'driver' => 'mysql',
        ];

        // Create a PDOException with retryable error code
        $exception = new \PDOException('Connection lost', 2002);

        // Should retry for attempt 1
        $this->assertTrue($strategy->shouldRetry($exception, 1, $config));

        // Should retry for attempt 2
        $this->assertTrue($strategy->shouldRetry($exception, 2, $config));

        // Should not retry for attempt 3 (max attempts reached)
        $this->assertFalse($strategy->shouldRetry($exception, 3, $config));
    }

    public function testShouldRetryWithNonRetryableError(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002, 2006],
            'driver' => 'mysql',
        ];

        // Create a PDOException with non-retryable error code
        $exception = new \PDOException('Syntax error', 1064);

        // Should not retry even for attempt 1
        $this->assertFalse($strategy->shouldRetry($exception, 1, $config));
    }

    public function testShouldRetryWithDisabledRetry(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => false,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002, 2006],
            'driver' => 'mysql',
        ];

        $exception = new \PDOException('Connection lost', 2002);

        // Should not retry when disabled
        $this->assertFalse($strategy->shouldRetry($exception, 1, $config));
    }

    public function testShouldRetryWithEmptyRetryableErrorsUsesDbError(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [], // Empty, should use DbError::isRetryable
            'driver' => 'mysql',
        ];

        // MySQL error 2002 (CR_CONNECTION_ERROR) is retryable
        $retryableException = new \PDOException('Connection lost', 2002);
        $this->assertTrue($strategy->shouldRetry($retryableException, 1, $config));

        // MySQL error 1064 (ER_PARSE_ERROR) is not retryable
        $nonRetryableException = new \PDOException('Syntax error', 1064);
        $this->assertFalse($strategy->shouldRetry($nonRetryableException, 1, $config));
    }

    public function testWaitBeforeRetryWithExponentialBackoff(): void
    {
        $connection = $this->createRetryableConnection([
            'enabled' => true,
            'max_attempts' => 5,
            'delay_ms' => 10,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 100,
        ]);

        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('waitBeforeRetry');
        $method->setAccessible(true);
        $attemptProperty = $reflection->getProperty('currentAttempt');
        $attemptProperty->setAccessible(true);

        // Attempt 1: delay = 10ms
        $attemptProperty->setValue($connection, 1);
        $start1 = microtime(true);
        $method->invoke($connection);
        $end1 = microtime(true);
        $elapsed1 = ($end1 - $start1) * 1000;
        $this->assertGreaterThanOrEqual(9, $elapsed1);
        $this->assertLessThan(50, $elapsed1); // Should be around 10ms

        // Attempt 2: delay = 20ms
        $attemptProperty->setValue($connection, 2);
        $start2 = microtime(true);
        $method->invoke($connection);
        $end2 = microtime(true);
        $elapsed2 = ($end2 - $start2) * 1000;
        $this->assertGreaterThanOrEqual(19, $elapsed2);
        $this->assertLessThan(50, $elapsed2); // Should be around 20ms
    }

    public function testWaitBeforeRetryWithMaxDelayCap(): void
    {
        $connection = $this->createRetryableConnection([
            'enabled' => true,
            'max_attempts' => 5,
            'delay_ms' => 50,
            'backoff_multiplier' => 3.0,
            'max_delay_ms' => 100,
        ]);

        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('waitBeforeRetry');
        $method->setAccessible(true);
        $attemptProperty = $reflection->getProperty('currentAttempt');
        $attemptProperty->setAccessible(true);

        // Attempt 3: delay = 50 * 3^2 = 450ms, but capped at 100ms
        $attemptProperty->setValue($connection, 3);
        $start = microtime(true);
        $method->invoke($connection);
        $end = microtime(true);
        $elapsed = ($end - $start) * 1000;
        $this->assertGreaterThanOrEqual(99, $elapsed);
        $this->assertLessThan(200, $elapsed); // Should be around 100ms (capped)
    }

    public function testRetryConfigWithMaxAttemptsExhaustion(): void
    {
        $connection = $this->createRetryableConnection([
            'enabled' => true,
            'max_attempts' => 2,
            'delay_ms' => 10,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 100,
            'retryable_errors' => [2002], // Add 2002 to retryable errors for this test
            'driver' => 'sqlite',
        ]);

        $config = $connection->getRetryConfig();
        $this->assertEquals(2, $config['max_attempts']);

        // Verify that after max attempts, shouldRetry returns false
        $strategy = new ExponentialBackoffRetryStrategy();
        $exception = new \PDOException('Connection lost', 2002);

        // Attempt 1: should retry (1 < 2)
        $this->assertTrue($strategy->shouldRetry($exception, 1, $config));

        // Attempt 2: should not retry (2 >= 2, max attempts reached)
        $this->assertFalse($strategy->shouldRetry($exception, 2, $config));

        // Attempt 3: should not retry (exceeded max attempts)
        $this->assertFalse($strategy->shouldRetry($exception, 3, $config));
    }

    public function testRetryConfigWithCustomRetryableErrors(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [9999, 8888], // Custom error codes
            'driver' => 'mysql',
        ];

        // Error code in custom list should retry
        $exception1 = new \PDOException('Custom error', 9999);
        $this->assertTrue($strategy->shouldRetry($exception1, 1, $config));

        // Error code not in custom list should not retry
        $exception2 = new \PDOException('Other error', 2002);
        $this->assertFalse($strategy->shouldRetry($exception2, 1, $config));
    }

    public function testRetryConfigWithZeroDelay(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 0,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // With zero delay, all attempts should have 0 delay
        $delay1 = $strategy->calculateDelay(1, $config);
        $this->assertEquals(0, $delay1);

        $delay2 = $strategy->calculateDelay(2, $config);
        $this->assertEquals(0, $delay2);
    }

    public function testRetryConfigWithOneMaxAttempt(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 1,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002],
            'driver' => 'mysql',
        ];

        $exception = new \PDOException('Connection lost', 2002);

        // With max_attempts = 1, attempt 1 should not retry because 1 >= 1
        // The logic is: if attempt >= max_attempts, don't retry
        // So for max_attempts = 1, only attempt 0 would retry (but attempts start at 1)
        // This means with max_attempts = 1, no retries are allowed
        $this->assertFalse($strategy->shouldRetry($exception, 1, $config));

        // Attempt 2: should not retry (exceeded max attempts)
        $this->assertFalse($strategy->shouldRetry($exception, 2, $config));
    }

    public function testRetryConfigWithVeryLargeMaxDelay(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 10,
            'delay_ms' => 100,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 1000000, // Very large max delay
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // Delay should not be capped until it exceeds max_delay_ms
        $delay1 = $strategy->calculateDelay(1, $config);
        $this->assertEquals(100, $delay1);

        $delay5 = $strategy->calculateDelay(5, $config);
        $this->assertEquals(1600, $delay5); // 100 * 2^4 = 1600

        $delay10 = $strategy->calculateDelay(10, $config);
        $this->assertEquals(51200, $delay10); // 100 * 2^9 = 51200, still less than max
    }

    public function testRetryConfigWithFractionalBackoffMultiplier(): void
    {
        $strategy = new ExponentialBackoffRetryStrategy();
        $config = [
            'enabled' => true,
            'max_attempts' => 5,
            'delay_ms' => 100,
            'backoff_multiplier' => 1.5,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // Test fractional multiplier
        $delay1 = $strategy->calculateDelay(1, $config);
        $this->assertEquals(100, $delay1);

        $delay2 = $strategy->calculateDelay(2, $config);
        $this->assertEquals(150, $delay2); // 100 * 1.5

        $delay3 = $strategy->calculateDelay(3, $config);
        $this->assertEquals(225, $delay3); // 100 * 1.5^2 = 225
    }
}
