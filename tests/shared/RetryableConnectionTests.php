<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
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
        $this->assertInstanceOf(\tommyknocker\pdodb\connection\Connection::class, $connection);
    }

    public function testRetryableConnectionCanExecuteQueries(): void
    {
        $connection = $this->createRetryableConnection();
        $connection->query('CREATE TABLE test_retry (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->query("INSERT INTO test_retry (name) VALUES ('test')");

        $stmt = $connection->query('SELECT * FROM test_retry');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
}
