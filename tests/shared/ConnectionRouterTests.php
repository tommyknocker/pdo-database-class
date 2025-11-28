<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
use PDOException;
use PDOStatement;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\ConnectionRouter;
use tommyknocker\pdodb\connection\loadbalancer\LoadBalancerInterface;
use tommyknocker\pdodb\connection\loadbalancer\WeightedLoadBalancer;
use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * Tests for ConnectionRouter class.
 */
final class ConnectionRouterTests extends BaseSharedTestCase
{
    protected function createMockConnection(): ConnectionInterface
    {
        // Use a real connection wrapper for testing
        return self::$db->connection;
    }

    public function testAddWriteConnection(): void
    {
        $router = new ConnectionRouter();
        $connection = $this->createMockConnection();

        $router->addWriteConnection('master', $connection);
        $writeConnections = $router->getWriteConnections();

        $this->assertArrayHasKey('master', $writeConnections);
        $this->assertSame($connection, $writeConnections['master']);
    }

    public function testAddReadConnection(): void
    {
        $router = new ConnectionRouter();
        $connection = $this->createMockConnection();

        $router->addReadConnection('replica1', $connection);
        $readConnections = $router->getReadConnections();

        $this->assertArrayHasKey('replica1', $readConnections);
        $this->assertSame($connection, $readConnections['replica1']);
    }

    public function testGetWriteConnection(): void
    {
        $router = new ConnectionRouter();
        $connection = $this->createMockConnection();

        $router->addWriteConnection('master', $connection);
        $result = $router->getWriteConnection();

        $this->assertSame($connection, $result);
    }

    public function testGetWriteConnectionThrowsWhenNoConnections(): void
    {
        $router = new ConnectionRouter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No write connection available');
        $router->getWriteConnection();
    }

    public function testGetReadConnectionUsesWriteWhenInTransaction(): void
    {
        $router = new ConnectionRouter();
        $writeConnection = $this->createMockConnection();
        $readConnection = $this->createMockConnection();

        $router->addWriteConnection('master', $writeConnection);
        $router->addReadConnection('replica1', $readConnection);
        $router->setTransactionState(true);

        $result = $router->getReadConnection();
        $this->assertSame($writeConnection, $result);
    }

    public function testGetReadConnectionUsesWriteWhenForceWriteMode(): void
    {
        $router = new ConnectionRouter();
        $writeConnection = $this->createMockConnection();
        $readConnection = $this->createMockConnection();

        $router->addWriteConnection('master', $writeConnection);
        $router->addReadConnection('replica1', $readConnection);
        $router->enableForceWrite();

        $result = $router->getReadConnection();
        $this->assertSame($writeConnection, $result);
    }

    public function testGetReadConnectionUsesReadReplica(): void
    {
        $router = new ConnectionRouter();
        $writeConnection = $this->createMockConnection();
        $readConnection = $this->createMockConnection();

        $router->addWriteConnection('master', $writeConnection);
        $router->addReadConnection('replica1', $readConnection);

        $result = $router->getReadConnection();
        $this->assertSame($readConnection, $result);
    }

    public function testGetReadConnectionFallsBackToWriteWhenNoReplicas(): void
    {
        $router = new ConnectionRouter();
        $writeConnection = $this->createMockConnection();

        $router->addWriteConnection('master', $writeConnection);

        $result = $router->getReadConnection();
        $this->assertSame($writeConnection, $result);
    }

    public function testEnableStickyWrites(): void
    {
        $router = new ConnectionRouter();
        $router->enableStickyWrites(5);

        $this->assertTrue($router->isStickyWritesEnabled());
    }

    public function testDisableStickyWrites(): void
    {
        $router = new ConnectionRouter();
        $router->enableStickyWrites(5);
        $router->disableStickyWrites();

        $this->assertFalse($router->isStickyWritesEnabled());
    }

    public function testEnableForceWrite(): void
    {
        $router = new ConnectionRouter();
        $router->enableForceWrite();

        $this->assertTrue($router->isForceWriteMode());
    }

    public function testDisableForceWrite(): void
    {
        $router = new ConnectionRouter();
        $router->enableForceWrite();
        $router->disableForceWrite();

        $this->assertFalse($router->isForceWriteMode());
    }

    public function testSetTransactionState(): void
    {
        $router = new ConnectionRouter();
        $router->setTransactionState(true);

        $this->assertTrue($router->isInTransaction());

        $router->setTransactionState(false);
        $this->assertFalse($router->isInTransaction());
    }

    public function testGetReadConnectionUsesWriteWithStickyWrites(): void
    {
        $router = new ConnectionRouter();
        $writeConnection = $this->createMockConnection();
        $readConnection = $this->createMockConnection();

        $router->addWriteConnection('master', $writeConnection);
        $router->addReadConnection('replica1', $readConnection);
        $router->enableStickyWrites(5);

        // Perform a write operation
        $router->getWriteConnection();

        // Immediately after write, should use write connection
        $result = $router->getReadConnection();
        $this->assertSame($writeConnection, $result);
    }

    public function testGetLoadBalancer(): void
    {
        $router = new ConnectionRouter();
        $loadBalancer = $router->getLoadBalancer();

        $this->assertInstanceOf(LoadBalancerInterface::class, $loadBalancer);
    }

    public function testSetLoadBalancer(): void
    {
        $router = new ConnectionRouter();
        $newLoadBalancer = new WeightedLoadBalancer();
        $router->setLoadBalancer($newLoadBalancer);

        $this->assertSame($newLoadBalancer, $router->getLoadBalancer());
    }

    public function testHealthCheckReturnsTrueForHealthyConnection(): void
    {
        $router = new ConnectionRouter();
        $connection = self::$db->connection;

        $result = $router->healthCheck($connection);
        $this->assertTrue($result);
    }

    public function testHealthCheckReturnsFalseForUnhealthyConnection(): void
    {
        $router = new ConnectionRouter();

        // Create a connection that throws exception on query
        $unhealthyConnection = new class (self::$db->connection) implements ConnectionInterface {
            public function __construct(protected ConnectionInterface $connection)
            {
            }

            public function getPdo(): PDO
            {
                return $this->connection->getPdo();
            }

            public function getDialect(): DialectInterface
            {
                return $this->connection->getDialect();
            }

            public function getDriverName(): string
            {
                return $this->connection->getDriverName();
            }

            public function resetState(): void
            {
                $this->connection->resetState();
            }

            public function prepare(string $sql, array $params = []): static
            {
                return $this->connection->prepare($sql, $params);
            }

            public function execute(array $params = []): PDOStatement
            {
                return $this->connection->execute($params);
            }

            public function query(string $sql): PDOStatement|false
            {
                throw new PDOException('Connection failed', 2002);
            }

            public function quote(mixed $value): string|false
            {
                return $this->connection->quote($value);
            }

            public function transaction(): bool
            {
                return $this->connection->transaction();
            }

            public function commit(): bool
            {
                return $this->connection->commit();
            }

            public function rollBack(): bool
            {
                return $this->connection->rollBack();
            }

            public function inTransaction(): bool
            {
                return $this->connection->inTransaction();
            }

            public function getLastInsertId(?string $name = null): false|string
            {
                return $this->connection->getLastInsertId($name);
            }

            public function getLastQuery(): ?string
            {
                return $this->connection->getLastQuery();
            }

            public function getLastError(): ?string
            {
                return $this->connection->getLastError();
            }

            public function getLastErrno(): int
            {
                return $this->connection->getLastErrno();
            }

            public function getExecuteState(): ?bool
            {
                return $this->connection->getExecuteState();
            }

            public function setAttribute(int $attribute, mixed $value): bool
            {
                return $this->connection->setAttribute($attribute, $value);
            }

            public function getAttribute(int $attribute): mixed
            {
                return $this->connection->getAttribute($attribute);
            }

            public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
            {
                $this->connection->setEventDispatcher($dispatcher);
            }

            public function getEventDispatcher(): ?EventDispatcherInterface
            {
                return $this->connection->getEventDispatcher();
            }

            public function setTempQueryContext(?array $queryContext): void
            {
                $this->connection->setTempQueryContext($queryContext);
            }

            /**
             * @inheritDoc
             */
            public function getOptions(): array
            {
                return [];
            }
        };

        $result = $router->healthCheck($unhealthyConnection);
        $this->assertFalse($result);
    }

    public function testGetWriteConnections(): void
    {
        $router = new ConnectionRouter();
        $connection1 = $this->createMockConnection();
        $connection2 = $this->createMockConnection();

        $router->addWriteConnection('master1', $connection1);
        $router->addWriteConnection('master2', $connection2);

        $connections = $router->getWriteConnections();
        $this->assertCount(2, $connections);
        $this->assertArrayHasKey('master1', $connections);
        $this->assertArrayHasKey('master2', $connections);
    }

    public function testGetReadConnections(): void
    {
        $router = new ConnectionRouter();
        $connection1 = $this->createMockConnection();
        $connection2 = $this->createMockConnection();

        $router->addReadConnection('replica1', $connection1);
        $router->addReadConnection('replica2', $connection2);

        $connections = $router->getReadConnections();
        $this->assertCount(2, $connections);
        $this->assertArrayHasKey('replica1', $connections);
        $this->assertArrayHasKey('replica2', $connections);
    }

    public function testStickyWritesExpiresAfterDuration(): void
    {
        $router = new ConnectionRouter();
        $writeConnection = $this->createMockConnection();
        $readConnection = $this->createMockConnection();

        $router->addWriteConnection('master', $writeConnection);
        $router->addReadConnection('replica1', $readConnection);
        $router->enableStickyWrites(1); // 1 second

        // Perform a write operation
        $router->getWriteConnection();

        // Wait for sticky writes to expire
        usleep(1100000); // 1.1 seconds

        // After expiration, should use read connection
        $result = $router->getReadConnection();
        $this->assertSame($readConnection, $result);
    }
}
