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
 * QueryBuilderTests tests for shared.
 */
final class QueryBuilderTests extends BaseSharedTestCase
{
    public function testExplainQuery(): void
    {
    $result = self::$db->explain('SELECT * FROM test_coverage WHERE id = 1');
    $this->assertIsArray($result);
    }

    public function testQueryNonexistentTable(): void
    {
    $this->expectException(PDOException::class);
    self::$db->rawQuery('SELECT * FROM absolutely_nonexistent_table_xyz_123');
    }

    public function testQueryBuilderGetters(): void
    {
    $qb = self::$db->find()->table('test_coverage');
    
    // Test getConnection
    $this->assertInstanceOf(ConnectionInterface::class, $qb->getConnection());
    
    // Test getDialect
    $this->assertInstanceOf(DialectInterface::class, $qb->getDialect());
    
    // Test getPrefix (returns empty string when no prefix set)
    $prefix = $qb->getPrefix();
    $this->assertTrue($prefix === null || $prefix === '');
    }

    public function testGetColumnWithMultipleSelects(): void
    {
    self::$db->find()->table('test_coverage')->insert(['name' => 'test1', 'value' => 1]);
    self::$db->find()->table('test_coverage')->insert(['name' => 'test2', 'value' => 2]);
    
    // getColumn() should return empty array when select has multiple columns
    $result = self::$db->find()
    ->table('test_coverage')
    ->select(['name', 'value'])
    ->getColumn();
    
    $this->assertIsArray($result);
    $this->assertEmpty($result);
    }

    public function testQueryException(): void
    {
    $connection = self::$db->connection;
    
    $this->expectException(PDOException::class);
    
    try {
    $connection->query('SELECT * FROM nonexistent_table_xyz');
    } catch (PDOException $e) {
    // Verify lastError and lastErrno are set
    $this->assertNotNull($connection->getLastError());
    $this->assertStringContainsString('nonexistent_table_xyz', $connection->getLastError());
    
    throw $e;
    }
    }

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

    public function testQueryCaching(): void
    {
    $cache = new ArrayCache();
    
    // Create a new database instance with cache
    $db = new PdoDb(
    'sqlite',
    ['path' => ':memory:'],
    [],
    null,
    $cache
    );
    
    // Create test table
    $db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');
    
    // Insert test data
    $db->find()->table('users')->insertMulti([
    ['name' => 'Alice', 'age' => 30],
    ['name' => 'Bob', 'age' => 25],
    ['name' => 'Charlie', 'age' => 35],
    ]);
    
    // Test 1: Cache miss on first query
    $this->assertEquals(0, $cache->count());
    
    $result1 = $db->find()
    ->from('users')
    ->where('age', 25, '>')
    ->cache(3600)
    ->get();
    
    $this->assertCount(2, $result1);
    $this->assertEquals(1, $cache->count());
    
    // Test 2: Cache hit on second query (same query)
    $result2 = $db->find()
    ->from('users')
    ->where('age', 25, '>')
    ->cache(3600)
    ->get();
    
    $this->assertEquals($result1, $result2);
    $this->assertEquals(1, $cache->count()); // Still only 1 cache entry
    
    // Test 3: Different query creates different cache entry
    $result3 = $db->find()
    ->from('users')
    ->where('age', 30, '>=')
    ->cache(3600)
    ->get();
    
    $this->assertCount(2, $result3);
    $this->assertEquals(2, $cache->count()); // Now 2 cache entries
    
    // Test 4: Custom cache key
    $result4 = $db->find()
    ->from('users')
    ->cache(3600, 'my_custom_key')
    ->get();
    
    $this->assertCount(3, $result4);
    $this->assertEquals(3, $cache->count());
    $this->assertTrue($cache->has('my_custom_key'));
    
    // Test 5: Cache with getOne()
    $result5 = $db->find()
    ->from('users')
    ->where('name', 'Alice')
    ->cache(3600)
    ->getOne();
    
    $this->assertEquals('Alice', $result5['name']);
    $this->assertEquals(4, $cache->count());
    
    // Test 6: Cache with getValue()
    $result6 = $db->find()
    ->from('users')
    ->select('name')
    ->where('name', 'Bob')
    ->cache(3600)
    ->getValue();
    
    $this->assertEquals('Bob', $result6);
    $this->assertEquals(5, $cache->count());
    
    // Test 7: Cache with getColumn()
    $result7 = $db->find()
    ->from('users')
    ->select('name')
    ->orderBy('age', 'ASC')
    ->cache(3600)
    ->getColumn();
    
    $this->assertEquals(['Bob', 'Alice', 'Charlie'], $result7);
    $this->assertEquals(6, $cache->count());
    
    // Test 8: noCache() disables caching
    $countBefore = $cache->count();
    $result8 = $db->find()
    ->from('users')
    ->cache(3600) // Enable cache
    ->noCache()   // But then disable it
    ->get();
    
    $this->assertCount(3, $result8);
    $this->assertEquals($countBefore, $cache->count()); // No new cache entry
    }

    public function testSelectWildcardForms(): void
    {
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    
    // Schema
    $db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    $db->rawQuery('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, amount INTEGER)');
    
    $uid = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('orders')->insert(['user_id' => $uid, 'amount' => 100]);
    
    // select(['*'])
    $rows1 = $db->find()
    ->from('users')
    ->select(['*'])
    ->get();
    $this->assertCount(1, $rows1);
    $this->assertEquals('Alice', $rows1[0]['name']);
    
    // select(['u.*', 'o.*']) with join
    $rows2 = $db->find()
    ->from('users u')
    ->join('orders o', 'o.user_id = u.id')
    ->select(['u.*', 'o.*'])
    ->get();
    $this->assertCount(1, $rows2);
    $this->assertArrayHasKey('name', $rows2[0]);
    $this->assertArrayHasKey('amount', $rows2[0]);
    
    // select('u.*, o.*') should work the same
    $rows3 = $db->find()
    ->from('users u')
    ->join('orders o', 'o.user_id = u.id')
    ->select('u.*, o.*')
    ->get();
    $this->assertCount(1, $rows3);
    $this->assertEquals($rows2[0]['name'], $rows3[0]['name']);
    $this->assertEquals($rows2[0]['amount'], $rows3[0]['amount']);
    }

    public function testQueryBuilderForceWrite(): void
    {
    $db = new PdoDb();
    $db->enableReadWriteSplitting();
    $db->addConnection('write', ['driver' => 'sqlite', 'path' => ':memory:']);
    $db->addConnection('read', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'read']);
    $db->connection('write');
    
    // Create table on write connection
    $db->rawQuery('CREATE TABLE test_rw (id INTEGER PRIMARY KEY, name TEXT)');
    
    $qb = $db->find()->from('test_rw');
    $result = $qb->forceWrite();
    
    $this->assertSame($qb, $result); // Should return self for chaining
    }

    public function testCteWithQueryBuilder(): void
    {
    $db = self::$db;
    $db->rawQuery('CREATE TABLE test_cte_qb (id INTEGER PRIMARY KEY, amount INTEGER)');
    $db->find()->table('test_cte_qb')->insertMulti([
    ['id' => 1, 'amount' => 50],
    ['id' => 2, 'amount' => 150],
    ['id' => 3, 'amount' => 250],
    ]);
    
    $subQuery = $db->find()->from('test_cte_qb')->where('amount', 100, '>');
    
    $results = $db->find()
    ->with('filtered', $subQuery)
    ->from('filtered')
    ->get();
    
    $this->assertCount(2, $results);
    $this->assertEquals(150, $results[0]['amount']);
    $this->assertEquals(250, $results[1]['amount']);
    }

    public function testCteWithJoins(): void
    {
    $db = self::$db;
    $db->rawQuery('CREATE TABLE test_cte_users (id INTEGER PRIMARY KEY, name TEXT)');
    $db->rawQuery('CREATE TABLE test_cte_orders (id INTEGER PRIMARY KEY, user_id INTEGER, amount INTEGER)');
    
    $db->find()->table('test_cte_users')->insertMulti([
    ['id' => 1, 'name' => 'Alice'],
    ['id' => 2, 'name' => 'Bob'],
    ]);
    
    $db->find()->table('test_cte_orders')->insertMulti([
    ['id' => 1, 'user_id' => 1, 'amount' => 100],
    ['id' => 2, 'user_id' => 1, 'amount' => 200],
    ['id' => 3, 'user_id' => 2, 'amount' => 150],
    ]);
    
    $results = $db->find()
    ->with('user_totals', function ($q) {
    $q->from('test_cte_orders')
    ->select([
    'user_id',
    'total' => Db::sum('amount'),
    ])
    ->groupBy('user_id');
    })
    ->from('test_cte_users')
    ->join('user_totals', 'test_cte_users.id = user_totals.user_id')
    ->select(['test_cte_users.name', 'user_totals.total'])
    ->orderBy('name')
    ->get();
    
    $this->assertCount(2, $results);
    $this->assertEquals('Alice', $results[0]['name']);
    $this->assertEquals(300, $results[0]['total']);
    $this->assertEquals('Bob', $results[1]['name']);
    $this->assertEquals(150, $results[1]['total']);
    }

    public function testQueryExecutedEvent(): void
    {
    $events = [];
    $dispatcher = $this->createMockDispatcher($events);
    
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    $db->setEventDispatcher($dispatcher);
    
    $db->rawQuery('CREATE TABLE test_events (id INTEGER PRIMARY KEY, name TEXT)');
    $db->find()->table('test_events')->insert(['name' => 'Test']);
    
    $this->assertGreaterThan(0, count($events));
    $queryEvents = array_filter($events, static fn ($e) => $e instanceof QueryExecutedEvent);
    $this->assertGreaterThan(0, count($queryEvents));
    
    // Find INSERT query event
    $insertEvent = null;
    foreach ($queryEvents as $event) {
    if (str_contains($event->getSql(), 'INSERT')) {
    $insertEvent = $event;
    break;
    }
    }
    
    $this->assertNotNull($insertEvent, 'INSERT query event should be dispatched');
    $this->assertStringContainsString('INSERT', $insertEvent->getSql());
    $this->assertGreaterThan(0, $insertEvent->getExecutionTime());
    $this->assertEquals('sqlite', $insertEvent->getDriver());
    }

    public function testQueryErrorEvent(): void
    {
    $events = [];
    $dispatcher = $this->createMockDispatcher($events);
    
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    $db->setEventDispatcher($dispatcher);
    
    try {
    $db->rawQuery('SELECT * FROM nonexistent_table');
    } catch (Exception $e) {
    // Expected
    }
    
    $errorEvents = array_filter($events, static fn ($e) => $e instanceof QueryErrorEvent);
    $this->assertGreaterThan(0, count($errorEvents));
    
    /** @var QueryErrorEvent $event */
    $event = array_values($errorEvents)[0];
    // SQL might be empty if error occurs during query() execution
    if ($event->getSql() !== '') {
    $this->assertStringContainsString('SELECT', $event->getSql());
    }
    $this->assertEquals('sqlite', $event->getDriver());
    $this->assertNotNull($event->getException());
    }
}
