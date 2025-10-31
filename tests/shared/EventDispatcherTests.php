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
 * EventDispatcherTests tests for shared.
 */
final class EventDispatcherTests extends BaseSharedTestCase
{
    public function testTransactionEvents(): void
    {
    $events = [];
    $dispatcher = $this->createMockDispatcher($events);
    
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    $db->setEventDispatcher($dispatcher);
    
    $db->startTransaction();
    $db->commit();
    
    $startedEvents = array_filter($events, static fn ($e) => $e instanceof TransactionStartedEvent);
    $committedEvents = array_filter($events, static fn ($e) => $e instanceof TransactionCommittedEvent);
    
    $this->assertCount(1, $startedEvents);
    $this->assertCount(1, $committedEvents);
    
    /** @var TransactionStartedEvent $started */
    $started = array_values($startedEvents)[0];
    $this->assertEquals('sqlite', $started->getDriver());
    
    /** @var TransactionCommittedEvent $committed */
    $committed = array_values($committedEvents)[0];
    $this->assertEquals('sqlite', $committed->getDriver());
    $this->assertGreaterThanOrEqual(0, $committed->getDuration());
    }

    public function testTransactionRollbackEvent(): void
    {
    $events = [];
    $dispatcher = $this->createMockDispatcher($events);
    
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    $db->setEventDispatcher($dispatcher);
    
    $db->startTransaction();
    $db->rollback();
    
    $rollbackEvents = array_filter($events, static fn ($e) => $e instanceof TransactionRolledBackEvent);
    
    $this->assertCount(1, $rollbackEvents);
    
    /** @var TransactionRolledBackEvent $event */
    $event = array_values($rollbackEvents)[0];
    $this->assertEquals('sqlite', $event->getDriver());
    $this->assertGreaterThanOrEqual(0, $event->getDuration());
    }

    public function testEventDispatcherGetterSetter(): void
    {
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    $this->assertNull($db->getEventDispatcher());
    
    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $db->setEventDispatcher($dispatcher);
    $this->assertSame($dispatcher, $db->getEventDispatcher());
    
    $db->setEventDispatcher(null);
    $this->assertNull($db->getEventDispatcher());
    }

    public function testNoDispatcherNoEvents(): void
    {
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    // No dispatcher set
    
    // Should not throw or error
    $db->rawQuery('CREATE TABLE test (id INTEGER)');
    $db->find()->table('test')->insert(['id' => 1]);
    
    $this->assertTrue(true); // Test passes if no exceptions
    }
}
