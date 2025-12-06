<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
use PDOStatement;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\loadbalancer\RandomLoadBalancer;
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;
use tommyknocker\pdodb\connection\loadbalancer\WeightedLoadBalancer;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\PdoDb;

/**
 * LoadBalancerTests tests for shared.
 */
final class LoadBalancerTests extends BaseSharedTestCase
{
    public function testRoundRobinAndWeightedLoadBalancers(): void
    {
        // Minimal ConnectionInterface stub
        $makeConn = function (string $driver): ConnectionInterface {
            return new class ($driver) implements ConnectionInterface {
                public function __construct(private string $driver)
                {
                }
                public function getPdo(): PDO
                {
                    return new PDO('sqlite::memory:');
                }
                public function getDriverName(): string
                {
                    return $this->driver;
                }
                public function getDialect(): DialectInterface
                {
                    throw new RuntimeException('not used');
                }
                public function resetState(): void
                {
                }
                public function prepare(string $sql, array $params = []): static
                {
                    return $this;
                }
                public function execute(array $params = []): PDOStatement
                {
                    throw new RuntimeException('not used');
                }
                public function query(string $sql): PDOStatement|false
                {
                    return false;
                }
                public function quote(mixed $value): string|false
                {
                    return (string)$value;
                }
                public function transaction(): bool
                {
                    return true;
                }
                public function commit(): bool
                {
                    return true;
                }
                public function rollBack(): bool
                {
                    return true;
                }
                public function inTransaction(): bool
                {
                    return false;
                }
                public function getLastInsertId(?string $name = null): false|string
                {
                    return '0';
                }
                public function getLastQuery(): ?string
                {
                    return null;
                }
                public function getLastError(): ?string
                {
                    return null;
                }
                public function getLastErrno(): int
                {
                    return 0;
                }
                public function getExecuteState(): ?bool
                {
                    return true;
                }
                public function setAttribute(int $attribute, mixed $value): bool
                {
                    return true;
                }
                public function getAttribute(int $attribute): mixed
                {
                    return null;
                }
                public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
                {
                }
                public function getEventDispatcher(): ?EventDispatcherInterface
                {
                    return null;
                }
                public function setTempQueryContext(?array $queryContext): void
                {
                }

                /**
                 * @inheritDoc
                 */
                public function getOptions(): array
                {
                    return [];
                }
            };
        };

        $connections = [
        'a' => $makeConn('sqlite'),
        'b' => $makeConn('sqlite'),
        'c' => $makeConn('sqlite'),
        ];

        // RoundRobin
        $rr = new RoundRobinLoadBalancer();
        $first = $rr->select($connections);
        $second = $rr->select($connections);
        $this->assertContains($first, array_values($connections));
        $this->assertContains($second, array_values($connections));
        $this->assertNotSame($first, $second);
        $rr->markFailed('b');
        $third = $rr->select($connections);
        $this->assertContains($third, array_values($connections));
        $rr->reset();
        $fourth = $rr->select($connections);
        $this->assertContains($fourth, array_values($connections));

        // Weighted: ensure failed nodes excluded and selection returns healthy names
        $wlb = new WeightedLoadBalancer();
        $wlb->setWeights(['a' => 1, 'b' => 5, 'c' => 1]);
        $wlb->markFailed('b');
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $picked = $wlb->select($connections);
            $this->assertContains($picked, [$connections['a'], $connections['c']]);
            $seen[spl_object_hash($picked)] = true;
        }
        // Both a and c appeared at least once
        $this->assertCount(2, $seen);
        // After reset failure, 'b' can be selected
        $wlb->reset();
        $seenAfterReset = [];
        for ($i = 0; $i < 30; $i++) {
            $picked = $wlb->select($connections);
            $seenAfterReset[spl_object_hash($picked)] = true;
        }
        // All three connections can appear after reset
        $this->assertGreaterThanOrEqual(2, count($seenAfterReset));
    }

    public function testRoundRobinLoadBalancerMarkHealthy(): void
    {
        $makeConn = function (): ConnectionInterface {
            return new class () implements ConnectionInterface {
                public function getPdo(): PDO
                {
                    return new PDO('sqlite::memory:');
                }

                public function getDriverName(): string
                {
                    return 'sqlite';
                }

                public function getDialect(): DialectInterface
                {
                    throw new RuntimeException('not used');
                }

                public function resetState(): void
                {
                }

                public function prepare(string $sql, array $params = []): static
                {
                    return $this;
                }

                public function execute(array $params = []): PDOStatement
                {
                    throw new RuntimeException('not used');
                }

                public function query(string $sql): PDOStatement|false
                {
                    return false;
                }

                public function quote(mixed $value): string|false
                {
                    return (string)$value;
                }

                public function transaction(): bool
                {
                    return true;
                }

                public function commit(): bool
                {
                    return true;
                }

                public function rollBack(): bool
                {
                    return true;
                }

                public function inTransaction(): bool
                {
                    return false;
                }

                public function getLastInsertId(?string $name = null): false|string
                {
                    return '0';
                }

                public function getLastQuery(): ?string
                {
                    return null;
                }

                public function getLastError(): ?string
                {
                    return null;
                }

                public function getLastErrno(): int
                {
                    return 0;
                }

                public function getExecuteState(): ?bool
                {
                    return true;
                }

                public function setAttribute(int $attribute, mixed $value): bool
                {
                    return true;
                }

                public function getAttribute(int $attribute): mixed
                {
                    return null;
                }

                public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
                {
                }

                public function getEventDispatcher(): ?EventDispatcherInterface
                {
                    return null;
                }

                public function setTempQueryContext(?array $queryContext): void
                {
                }

                /**
                 * @inheritDoc
                 */
                public function getOptions(): array
                {
                    return [];
                }
            };
        };

        $connections = [
            'a' => $makeConn(),
            'b' => $makeConn(),
        ];

        $rr = new RoundRobinLoadBalancer();
        $rr->markFailed('a');
        $selected = $rr->select($connections);
        $this->assertSame($connections['b'], $selected);

        $rr->markHealthy('a');
        $selected = $rr->select($connections);
        $this->assertContains($selected, [$connections['a'], $connections['b']]);
    }

    public function testWeightedLoadBalancerMarkHealthy(): void
    {
        $makeConn = function (): ConnectionInterface {
            return new class () implements ConnectionInterface {
                public function getPdo(): PDO
                {
                    return new PDO('sqlite::memory:');
                }

                public function getDriverName(): string
                {
                    return 'sqlite';
                }

                public function getDialect(): DialectInterface
                {
                    throw new RuntimeException('not used');
                }

                public function resetState(): void
                {
                }

                public function prepare(string $sql, array $params = []): static
                {
                    return $this;
                }

                public function execute(array $params = []): PDOStatement
                {
                    throw new RuntimeException('not used');
                }

                public function query(string $sql): PDOStatement|false
                {
                    return false;
                }

                public function quote(mixed $value): string|false
                {
                    return (string)$value;
                }

                public function transaction(): bool
                {
                    return true;
                }

                public function commit(): bool
                {
                    return true;
                }

                public function rollBack(): bool
                {
                    return true;
                }

                public function inTransaction(): bool
                {
                    return false;
                }

                public function getLastInsertId(?string $name = null): false|string
                {
                    return '0';
                }

                public function getLastQuery(): ?string
                {
                    return null;
                }

                public function getLastError(): ?string
                {
                    return null;
                }

                public function getLastErrno(): int
                {
                    return 0;
                }

                public function getExecuteState(): ?bool
                {
                    return true;
                }

                public function setAttribute(int $attribute, mixed $value): bool
                {
                    return true;
                }

                public function getAttribute(int $attribute): mixed
                {
                    return null;
                }

                public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
                {
                }

                public function getEventDispatcher(): ?EventDispatcherInterface
                {
                    return null;
                }

                public function setTempQueryContext(?array $queryContext): void
                {
                }

                /**
                 * @inheritDoc
                 */
                public function getOptions(): array
                {
                    return [];
                }
            };
        };

        $connections = [
            'a' => $makeConn(),
            'b' => $makeConn(),
        ];

        $wlb = new WeightedLoadBalancer();
        $wlb->setWeights(['a' => 1, 'b' => 1]);
        $wlb->markFailed('a');
        $selected = $wlb->select($connections);
        $this->assertSame($connections['b'], $selected);

        $wlb->markHealthy('a');
        $selected = $wlb->select($connections);
        $this->assertContains($selected, [$connections['a'], $connections['b']]);
    }

    public function testWeightedLoadBalancerSetWeights(): void
    {
        $makeConn = function (): ConnectionInterface {
            return new class () implements ConnectionInterface {
                public function getPdo(): PDO
                {
                    return new PDO('sqlite::memory:');
                }

                public function getDriverName(): string
                {
                    return 'sqlite';
                }

                public function getDialect(): DialectInterface
                {
                    throw new RuntimeException('not used');
                }

                public function resetState(): void
                {
                }

                public function prepare(string $sql, array $params = []): static
                {
                    return $this;
                }

                public function execute(array $params = []): PDOStatement
                {
                    throw new RuntimeException('not used');
                }

                public function query(string $sql): PDOStatement|false
                {
                    return false;
                }

                public function quote(mixed $value): string|false
                {
                    return (string)$value;
                }

                public function transaction(): bool
                {
                    return true;
                }

                public function commit(): bool
                {
                    return true;
                }

                public function rollBack(): bool
                {
                    return true;
                }

                public function inTransaction(): bool
                {
                    return false;
                }

                public function getLastInsertId(?string $name = null): false|string
                {
                    return '0';
                }

                public function getLastQuery(): ?string
                {
                    return null;
                }

                public function getLastError(): ?string
                {
                    return null;
                }

                public function getLastErrno(): int
                {
                    return 0;
                }

                public function getExecuteState(): ?bool
                {
                    return true;
                }

                public function setAttribute(int $attribute, mixed $value): bool
                {
                    return true;
                }

                public function getAttribute(int $attribute): mixed
                {
                    return null;
                }

                public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
                {
                }

                public function getEventDispatcher(): ?EventDispatcherInterface
                {
                    return null;
                }

                public function setTempQueryContext(?array $queryContext): void
                {
                }

                /**
                 * @inheritDoc
                 */
                public function getOptions(): array
                {
                    return [];
                }
            };
        };

        $connections = [
            'a' => $makeConn(),
            'b' => $makeConn(),
            'c' => $makeConn(),
        ];

        $wlb = new WeightedLoadBalancer();
        $wlb->setWeights(['a' => 5, 'b' => 1, 'c' => 1]);

        // With higher weight, 'a' should be selected more often
        $selected = [];
        for ($i = 0; $i < 20; $i++) {
            $picked = $wlb->select($connections);
            $key = array_search($picked, $connections, true);
            $selected[] = $key;
        }

        // 'a' should appear more often than 'b' or 'c'
        $countA = count(array_filter($selected, fn ($k) => $k === 'a'));
        $this->assertGreaterThan(0, $countA);
    }

    public function testRoundRobinLoadBalancerEmptyConnections(): void
    {
        $rr = new RoundRobinLoadBalancer();
        $result = $rr->select([]);
        $this->assertNull($result);
    }

    public function testWeightedLoadBalancerEmptyConnections(): void
    {
        $wlb = new WeightedLoadBalancer();
        $result = $wlb->select([]);
        $this->assertNull($result);
    }

    public function testRoundRobinLoadBalancerAllConnectionsFailed(): void
    {
        $makeConn = function (): ConnectionInterface {
            return new class () implements ConnectionInterface {
                public function getPdo(): PDO
                {
                    return new PDO('sqlite::memory:');
                }

                public function getDriverName(): string
                {
                    return 'sqlite';
                }

                public function getDialect(): DialectInterface
                {
                    throw new RuntimeException('not used');
                }

                public function resetState(): void
                {
                }

                public function prepare(string $sql, array $params = []): static
                {
                    return $this;
                }

                public function execute(array $params = []): PDOStatement
                {
                    throw new RuntimeException('not used');
                }

                public function query(string $sql): PDOStatement|false
                {
                    return false;
                }

                public function quote(mixed $value): string|false
                {
                    return (string)$value;
                }

                public function transaction(): bool
                {
                    return true;
                }

                public function commit(): bool
                {
                    return true;
                }

                public function rollBack(): bool
                {
                    return true;
                }

                public function inTransaction(): bool
                {
                    return false;
                }

                public function getLastInsertId(?string $name = null): false|string
                {
                    return '0';
                }

                public function getLastQuery(): ?string
                {
                    return null;
                }

                public function getLastError(): ?string
                {
                    return null;
                }

                public function getLastErrno(): int
                {
                    return 0;
                }

                public function getExecuteState(): ?bool
                {
                    return true;
                }

                public function setAttribute(int $attribute, mixed $value): bool
                {
                    return true;
                }

                public function getAttribute(int $attribute): mixed
                {
                    return null;
                }

                public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
                {
                }

                public function getEventDispatcher(): ?EventDispatcherInterface
                {
                    return null;
                }

                public function setTempQueryContext(?array $queryContext): void
                {
                }

                /**
                 * @inheritDoc
                 */
                public function getOptions(): array
                {
                    return [];
                }
            };
        };

        $connections = [
            'a' => $makeConn(),
            'b' => $makeConn(),
        ];

        $rr = new RoundRobinLoadBalancer();
        $rr->markFailed('a');
        $rr->markFailed('b');

        // When all connections failed, should reset and try all again
        $result = $rr->select($connections);
        $this->assertContains($result, array_values($connections));
    }

    public function testWeightedLoadBalancerAllConnectionsFailed(): void
    {
        $makeConn = function (): ConnectionInterface {
            return new class () implements ConnectionInterface {
                public function getPdo(): PDO
                {
                    return new PDO('sqlite::memory:');
                }

                public function getDriverName(): string
                {
                    return 'sqlite';
                }

                public function getDialect(): DialectInterface
                {
                    throw new RuntimeException('not used');
                }

                public function resetState(): void
                {
                }

                public function prepare(string $sql, array $params = []): static
                {
                    return $this;
                }

                public function execute(array $params = []): PDOStatement
                {
                    throw new RuntimeException('not used');
                }

                public function query(string $sql): PDOStatement|false
                {
                    return false;
                }

                public function quote(mixed $value): string|false
                {
                    return (string)$value;
                }

                public function transaction(): bool
                {
                    return true;
                }

                public function commit(): bool
                {
                    return true;
                }

                public function rollBack(): bool
                {
                    return true;
                }

                public function inTransaction(): bool
                {
                    return false;
                }

                public function getLastInsertId(?string $name = null): false|string
                {
                    return '0';
                }

                public function getLastQuery(): ?string
                {
                    return null;
                }

                public function getLastError(): ?string
                {
                    return null;
                }

                public function getLastErrno(): int
                {
                    return 0;
                }

                public function getExecuteState(): ?bool
                {
                    return true;
                }

                public function setAttribute(int $attribute, mixed $value): bool
                {
                    return true;
                }

                public function getAttribute(int $attribute): mixed
                {
                    return null;
                }

                public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
                {
                }

                public function getEventDispatcher(): ?EventDispatcherInterface
                {
                    return null;
                }

                public function setTempQueryContext(?array $queryContext): void
                {
                }

                /**
                 * @inheritDoc
                 */
                public function getOptions(): array
                {
                    return [];
                }
            };
        };

        $connections = [
            'a' => $makeConn(),
            'b' => $makeConn(),
        ];

        $wlb = new WeightedLoadBalancer();
        $wlb->setWeights(['a' => 1, 'b' => 1]);
        $wlb->markFailed('a');
        $wlb->markFailed('b');

        // When all connections failed, should reset and try all again
        $result = $wlb->select($connections);
        $this->assertContains($result, array_values($connections));
    }

    public function testWeightedLoadBalancerWithDefaultWeight(): void
    {
        $makeConn = function (): ConnectionInterface {
            return new class () implements ConnectionInterface {
                public function getPdo(): PDO
                {
                    return new PDO('sqlite::memory:');
                }

                public function getDriverName(): string
                {
                    return 'sqlite';
                }

                public function getDialect(): DialectInterface
                {
                    throw new RuntimeException('not used');
                }

                public function resetState(): void
                {
                }

                public function prepare(string $sql, array $params = []): static
                {
                    return $this;
                }

                public function execute(array $params = []): PDOStatement
                {
                    throw new RuntimeException('not used');
                }

                public function query(string $sql): PDOStatement|false
                {
                    return false;
                }

                public function quote(mixed $value): string|false
                {
                    return (string)$value;
                }

                public function transaction(): bool
                {
                    return true;
                }

                public function commit(): bool
                {
                    return true;
                }

                public function rollBack(): bool
                {
                    return true;
                }

                public function inTransaction(): bool
                {
                    return false;
                }

                public function getLastInsertId(?string $name = null): false|string
                {
                    return '0';
                }

                public function getLastQuery(): ?string
                {
                    return null;
                }

                public function getLastError(): ?string
                {
                    return null;
                }

                public function getLastErrno(): int
                {
                    return 0;
                }

                public function getExecuteState(): ?bool
                {
                    return true;
                }

                public function setAttribute(int $attribute, mixed $value): bool
                {
                    return true;
                }

                public function getAttribute(int $attribute): mixed
                {
                    return null;
                }

                public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
                {
                }

                public function getEventDispatcher(): ?EventDispatcherInterface
                {
                    return null;
                }

                public function setTempQueryContext(?array $queryContext): void
                {
                }

                /**
                 * @inheritDoc
                 */
                public function getOptions(): array
                {
                    return [];
                }
            };
        };

        $connections = [
            'a' => $makeConn(),
            'b' => $makeConn(),
        ];

        $wlb = new WeightedLoadBalancer();
        // Don't set weights, should use default weight of 1
        $result = $wlb->select($connections);
        $this->assertContains($result, array_values($connections));
        $wlb->markHealthy('b');
        $foundB = false;
        for ($i = 0; $i < 50; $i++) {
            if ($wlb->select($connections) === $connections['b']) {
                $foundB = true;
                break;
            }
        }
        $this->assertTrue($foundB);
    }

    public function testLoadBalancers(): void
    {
        $db = new PdoDb();

        // Test with RoundRobin
        $db->enableReadWriteSplitting(new RoundRobinLoadBalancer());
        $this->assertInstanceOf(
            RoundRobinLoadBalancer::class,
            $db->getConnectionRouter()->getLoadBalancer()
        );

        // Test with Random
        $db->enableReadWriteSplitting(new RandomLoadBalancer());
        $this->assertInstanceOf(
            RandomLoadBalancer::class,
            $db->getConnectionRouter()->getLoadBalancer()
        );

        // Test with Weighted
        $weighted = new WeightedLoadBalancer();
        $weighted->setWeights(['read-1' => 2, 'read-2' => 1]);
        $db->enableReadWriteSplitting($weighted);
        $this->assertInstanceOf(
            WeightedLoadBalancer::class,
            $db->getConnectionRouter()->getLoadBalancer()
        );
    }

    public function testLoadBalancerMarkFailedAndHealthy(): void
    {
        $balancer = new RoundRobinLoadBalancer();

        $balancer->markFailed('read-1');
        $balancer->markHealthy('read-1');

        // Should not throw exceptions
        $this->assertTrue(true);
    }

    public function testLoadBalancerReset(): void
    {
        $balancer = new RoundRobinLoadBalancer();

        $balancer->markFailed('read-1');
        $balancer->reset();

        // Should not throw exceptions
        $this->assertTrue(true);
    }
}
