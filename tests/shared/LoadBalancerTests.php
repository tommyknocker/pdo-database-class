<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;


use Exception;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use RuntimeException;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\connection\Connection;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\ConnectionType;
use tommyknocker\pdodb\connection\DialectRegistry;
use tommyknocker\pdodb\connection\loadbalancer\RandomLoadBalancer;
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;
use tommyknocker\pdodb\connection\loadbalancer\WeightedLoadBalancer;
use tommyknocker\pdodb\connection\PreparedStatementPool;
use tommyknocker\pdodb\connection\RetryableConnection;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\events\ConnectionOpenedEvent;
use tommyknocker\pdodb\events\QueryErrorEvent;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\events\TransactionCommittedEvent;
use tommyknocker\pdodb\events\TransactionRolledBackEvent;
use tommyknocker\pdodb\events\TransactionStartedEvent;
use tommyknocker\pdodb\exceptions\AuthenticationException;
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\exceptions\TimeoutException;
use tommyknocker\pdodb\exceptions\TransactionException;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\helpers\DbError;
use tommyknocker\pdodb\helpers\values\FilterValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\WindowFunctionValue;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\pagination\Cursor;
use tommyknocker\pdodb\query\pagination\CursorPaginationResult;
use tommyknocker\pdodb\query\pagination\PaginationResult;
use tommyknocker\pdodb\query\pagination\SimplePaginationResult;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

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
    public function setEventDispatcher(?\Psr\EventDispatcher\EventDispatcherInterface $dispatcher): void
    {
    }
    public function getEventDispatcher(): ?\Psr\EventDispatcher\EventDispatcherInterface
    {
    return null;
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
