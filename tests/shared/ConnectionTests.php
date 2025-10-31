<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Exception;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\connection\Connection;
use tommyknocker\pdodb\connection\ConnectionType;
use tommyknocker\pdodb\connection\RetryableConnection;
use tommyknocker\pdodb\events\ConnectionOpenedEvent;
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\helpers\DbError;
use tommyknocker\pdodb\PdoDb;

/**
 * ConnectionTests tests for shared.
 */
final class ConnectionTests extends BaseSharedTestCase
{
    public function testUninitializedConnection(): void
    {
        $db = new PdoDb();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection not initialized');
        $db->find();
    }

    public function testConnectionNotFound(): void
    {
        $db = new PdoDb();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection nonexistent not found');
        $db->connection('nonexistent');
    }

    public function testConnectionPooling(): void
    {
        $db = new PdoDb();

        $db->addConnection('primary', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->addConnection('secondary', ['driver' => 'sqlite', 'path' => ':memory:']);

        // Switch to primary
        $result = $db->connection('primary');
        $this->assertInstanceOf(PdoDb::class, $result);

        // Create table in primary
        $db->rawQuery('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        $db->rawQuery('INSERT INTO test (id) VALUES (1)');

        $count1 = $db->rawQueryValue('SELECT COUNT(*) FROM test');
        $this->assertEquals(1, $count1);

        // Switch to secondary
        $db->connection('secondary');
        $db->rawQuery('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        $count2 = $db->rawQueryValue('SELECT COUNT(*) FROM test');
        $this->assertEquals(0, $count2); // Different database

        // Switch back to primary
        $db->connection('primary');
        $count3 = $db->rawQueryValue('SELECT COUNT(*) FROM test');
        $this->assertEquals(1, $count3);
    }

    public function testPing(): void
    {
        $result = self::$db->ping();
        $this->assertTrue($result);
    }

    public function testHasConnection(): void
    {
        $db = new PdoDb();

        // Initially no connections
        $this->assertFalse($db->hasConnection('test'));

        // Add connection
        $db->addConnection('test', ['driver' => 'sqlite', 'path' => ':memory:']);
        $this->assertTrue($db->hasConnection('test'));

        // Non-existent connection
        $this->assertFalse($db->hasConnection('nonexistent'));
    }

    public function testDisconnectSpecificConnection(): void
    {
        $db = new PdoDb();

        // Add multiple connections
        $db->addConnection('conn1', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->addConnection('conn2', ['driver' => 'sqlite', 'path' => ':memory:']);

        $this->assertTrue($db->hasConnection('conn1'));
        $this->assertTrue($db->hasConnection('conn2'));

        // Disconnect specific connection
        $db->disconnect('conn1');
        $this->assertFalse($db->hasConnection('conn1'));
        $this->assertTrue($db->hasConnection('conn2'));
    }

    public function testDisconnectAllConnections(): void
    {
        $db = new PdoDb();

        // Add connections
        $db->addConnection('conn1', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->addConnection('conn2', ['driver' => 'sqlite', 'path' => ':memory:']);

        $this->assertTrue($db->hasConnection('conn1'));
        $this->assertTrue($db->hasConnection('conn2'));

        // Disconnect all
        $db->disconnect();
        $this->assertFalse($db->hasConnection('conn1'));
        $this->assertFalse($db->hasConnection('conn2'));
    }

    public function testDisconnectCurrentConnection(): void
    {
        $db = new PdoDb();

        // Add and select connection
        $db->addConnection('test', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->connection('test');

        $this->assertTrue($db->hasConnection('test'));

        // Disconnect current connection
        $db->disconnect('test');
        $this->assertFalse($db->hasConnection('test'));

        // Should throw exception when trying to use disconnected connection
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection not initialized');
        $db->find();
    }

    public function testRetryableConnectionCreation(): void
    {
        $config = [
        'path' => ':memory:',
        'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 100,
        'retryable_errors' => [
        DbError::MYSQL_CANNOT_CONNECT,
        DbError::MYSQL_CONNECTION_KILLED,
        DbError::MYSQL_CONNECTION_LOST,
        ],
        ],
        ];

        $db = new PdoDb('sqlite', $config);

        // Verify we get a RetryableConnection
        $connection = $db->connection;
        $this->assertInstanceOf(RetryableConnection::class, $connection);

        // Test basic functionality still works
        $db->rawQuery('CREATE TABLE retry_test (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery('INSERT INTO retry_test (name) VALUES (?)', ['test']);

        $result = $db->rawQueryValue('SELECT name FROM retry_test WHERE id = 1');
        $this->assertEquals('test', $result);
    }

    public function testRetryableConnectionConfig(): void
    {
        $config = [
        'path' => ':memory:',
        'retry' => [
        'enabled' => true,
        'max_attempts' => 5,
        'delay_ms' => 200,
        'backoff_multiplier' => 3,
        'max_delay_ms' => 5000,
        'retryable_errors' => [
        DbError::MYSQL_CANNOT_CONNECT,
        DbError::MYSQL_CONNECTION_KILLED,
        DbError::MYSQL_CONNECTION_LOST,
        DbError::MYSQL_CONNECTION_KILLED,
        ],
        ],
        ];

        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;

        $this->assertInstanceOf(RetryableConnection::class, $connection);

        if ($connection instanceof RetryableConnection) {
            $retryConfig = $connection->getRetryConfig();
            $this->assertTrue($retryConfig['enabled']);
            $this->assertEquals(5, $retryConfig['max_attempts']);
            $this->assertEquals(200, $retryConfig['delay_ms']);
            $this->assertEquals(3, $retryConfig['backoff_multiplier']);
            $this->assertEquals(5000, $retryConfig['max_delay_ms']);
            $this->assertEquals([
            DbError::MYSQL_CANNOT_CONNECT,
            DbError::MYSQL_CONNECTION_KILLED,
            DbError::MYSQL_CONNECTION_LOST,
            DbError::MYSQL_CONNECTION_KILLED,
            ], $retryConfig['retryable_errors']);
        }
    }

    public function testRetryableConnectionDisabled(): void
    {
        $config = [
        'path' => ':memory:',
        'retry' => [
        'enabled' => false,
        'max_attempts' => 3,
        'retryable_errors' => [
        DbError::MYSQL_CANNOT_CONNECT,
        DbError::MYSQL_CONNECTION_KILLED,
        DbError::MYSQL_CONNECTION_LOST,
        ],
        ],
        ];

        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;

        // Should get regular Connection when retry is disabled
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertNotInstanceOf(RetryableConnection::class, $connection);
    }

    public function testRetryableConnectionWithoutConfig(): void
    {
        $config = [
        'path' => ':memory:',
        // No retry config
        ];

        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;

        // Should get regular Connection when no retry config
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertNotInstanceOf(RetryableConnection::class, $connection);
    }

    public function testRetryableConnectionCurrentAttempt(): void
    {
        $config = [
        'path' => ':memory:',
        'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 10, // Very short delay for testing
        'retryable_errors' => [
        DbError::MYSQL_CANNOT_CONNECT,
        DbError::MYSQL_CONNECTION_KILLED,
        DbError::MYSQL_CONNECTION_LOST,
        ],
        ],
        ];

        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;

        $this->assertInstanceOf(RetryableConnection::class, $connection);

        // Initially should be 0
        if ($connection instanceof RetryableConnection) {
            $this->assertEquals(0, $connection->getCurrentAttempt());
        }

        // Test that normal operations work
        $db->rawQuery('CREATE TABLE retry_attempt_test (id INTEGER PRIMARY KEY)');
        $db->rawQuery('INSERT INTO retry_attempt_test (id) VALUES (1)');

        // Should be 1 after successful operation (attempt counter starts at 1)
        if ($connection instanceof RetryableConnection) {
            $this->assertEquals(1, $connection->getCurrentAttempt());
        }
    }

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

    public function testTimeoutWithConnectionPooling(): void
    {
        $db = new PdoDb();

        // Add multiple connections
        $db->addConnection('conn1', [
        'driver' => 'sqlite',
        'path' => ':memory:',
        ]);

        $db->addConnection('conn2', [
        'driver' => 'sqlite',
        'path' => ':memory:',
        ]);

        // Test timeout on first connection
        $db->connection('conn1');
        $db->setTimeout(30);
        $timeout1 = $db->getTimeout();
        $this->assertIsInt($timeout1);
        $this->assertGreaterThanOrEqual(0, $timeout1);

        // Test timeout on second connection
        $db->connection('conn2');
        $db->setTimeout(60);
        $timeout2 = $db->getTimeout();
        $this->assertIsInt($timeout2);
        $this->assertGreaterThanOrEqual(0, $timeout2);

        // Verify first connection timeout is unchanged
        $db->connection('conn1');
        $this->assertEquals($timeout1, $db->getTimeout());
    }

    public function testConnectionRouterAddConnections(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();

        $db->addConnection('master', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'write']);
        $db->addConnection('slave1', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'read']);
        $db->addConnection('slave2', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'read']);

        $router = $db->getConnectionRouter();
        $this->assertCount(1, $router->getWriteConnections());
        $this->assertCount(2, $router->getReadConnections());
    }

    public function testConnectionRouterDefaultTypeIsWrite(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();

        // Without 'type' parameter, should default to write
        $db->addConnection('default', ['driver' => 'sqlite', 'path' => ':memory:']);

        $router = $db->getConnectionRouter();
        $this->assertCount(1, $router->getWriteConnections());
        $this->assertCount(0, $router->getReadConnections());
    }

    public function testConnectionRouterHealthCheck(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();
        $db->addConnection('test', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'write']);
        $db->connection('test');

        $router = $db->getConnectionRouter();
        $this->assertNotNull($router);
        $this->assertTrue($router->healthCheck($db->connection));
    }

    public function testConnectionTypeEnum(): void
    {
        $readType = ConnectionType::READ;
        $writeType = ConnectionType::WRITE;

        $this->assertEquals('read', $readType->value);
        $this->assertEquals('write', $writeType->value);
    }

    public function testConnectionOpenedEvent(): void
    {
        $events = [];
        $dispatcher = $this->createMockDispatcher($events);

        // Set dispatcher before creating connection to catch ConnectionOpenedEvent
        $db = new PdoDb();
        $db->setEventDispatcher($dispatcher);

        // Add connection - this should trigger ConnectionOpenedEvent
        $db->addConnection('default', [
        'driver' => 'sqlite',
        'path' => ':memory:',
        ]);

        // Trigger connection usage
        $db->connection('default')->rawQuery('SELECT 1');

        $connectionEvents = array_filter($events, static fn ($e) => $e instanceof ConnectionOpenedEvent);
        $this->assertGreaterThan(0, count($connectionEvents), 'ConnectionOpenedEvent should be dispatched');

        /** @var ConnectionOpenedEvent $event */
        $event = array_values($connectionEvents)[0];
        $this->assertEquals('sqlite', $event->getDriver());
        $this->assertNotEmpty($event->getDsn());
    }
}
