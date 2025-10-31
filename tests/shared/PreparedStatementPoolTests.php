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
 * PreparedStatementPoolTests tests for shared.
 */
final class PreparedStatementPoolTests extends BaseSharedTestCase
{
    public function testPreparedStatementPoolBasic(): void
    {
    $pool = new PreparedStatementPool(10, true);
    $this->assertTrue($pool->isEnabled());
    $this->assertEquals(10, $pool->capacity());
    $this->assertEquals(0, $pool->size());
    $this->assertEquals(0, $pool->getHits());
    $this->assertEquals(0, $pool->getMisses());
    $this->assertEquals(0.0, $pool->getHitRate());
    }

    public function testPreparedStatementPoolDisabled(): void
    {
    $pool = new PreparedStatementPool(10, false);
    $this->assertFalse($pool->isEnabled());
    $this->assertNull($pool->get('key1'));
    $this->assertEquals(0, $pool->getHits());
    $this->assertEquals(1, $pool->getMisses());
    
    $conn = self::$db->connection;
    $conn->prepare('SELECT 1');
    // Use reflection to access protected stmt property for testing
    $reflection = new ReflectionClass($conn);
    $stmtProperty = $reflection->getProperty('stmt');
    $stmtProperty->setAccessible(true);
    $stmt = $stmtProperty->getValue($conn);
    if ($stmt !== null) {
    $pool->put('key1', $stmt);
    $this->assertEquals(0, $pool->size());
    }
    }

    public function testPreparedStatementPoolGetPut(): void
    {
    $pool = new PreparedStatementPool(10, true);
    $conn = self::$db->connection;
    $conn->prepare('SELECT 1');
    $reflection = new ReflectionClass($conn);
    $stmtProperty = $reflection->getProperty('stmt');
    $stmtProperty->setAccessible(true);
    $stmt1 = $stmtProperty->getValue($conn);
    
    $conn->prepare('SELECT 2');
    $stmt2 = $stmtProperty->getValue($conn);
    
    // Put statements
    $pool->put('key1', $stmt1);
    $pool->put('key2', $stmt2);
    
    $this->assertEquals(2, $pool->size());
    $this->assertEquals(0, $pool->getHits());
    $this->assertEquals(0, $pool->getMisses());
    
    // Get existing
    $retrieved = $pool->get('key1');
    $this->assertSame($stmt1, $retrieved);
    $this->assertEquals(1, $pool->getHits());
    $this->assertEquals(0, $pool->getMisses());
    
    // Get non-existing
    $retrieved = $pool->get('key3');
    $this->assertNull($retrieved);
    $this->assertEquals(1, $pool->getHits());
    $this->assertEquals(1, $pool->getMisses());
    
    // Hit rate
    $this->assertEquals(0.5, $pool->getHitRate());
    }

    public function testPreparedStatementPoolLRUEviction(): void
    {
    $pool = new PreparedStatementPool(3, true);
    $conn = self::$db->connection;
    $reflection = new ReflectionClass($conn);
    $stmtProperty = $reflection->getProperty('stmt');
    $stmtProperty->setAccessible(true);
    
    $conn->prepare('SELECT 1');
    $stmt1 = $stmtProperty->getValue($conn);
    $conn->prepare('SELECT 2');
    $stmt2 = $stmtProperty->getValue($conn);
    $conn->prepare('SELECT 3');
    $stmt3 = $stmtProperty->getValue($conn);
    $conn->prepare('SELECT 4');
    $stmt4 = $stmtProperty->getValue($conn);
    
    // Fill to capacity
    $pool->put('key1', $stmt1);
    $pool->put('key2', $stmt2);
    $pool->put('key3', $stmt3);
    $this->assertEquals(3, $pool->size());
    
    // Add one more - should evict LRU (key1)
    $pool->put('key4', $stmt4);
    $this->assertEquals(3, $pool->size());
    $this->assertNull($pool->get('key1')); // LRU evicted
    $this->assertNotNull($pool->get('key2')); // Still cached
    $this->assertNotNull($pool->get('key3')); // Still cached
    $this->assertNotNull($pool->get('key4')); // Most recent
    }

    public function testPreparedStatementPoolMRUOrder(): void
    {
    $pool = new PreparedStatementPool(3, true);
    $conn = self::$db->connection;
    $reflection = new ReflectionClass($conn);
    $stmtProperty = $reflection->getProperty('stmt');
    $stmtProperty->setAccessible(true);
    
    $conn->prepare('SELECT 1');
    $stmt1 = $stmtProperty->getValue($conn);
    $conn->prepare('SELECT 2');
    $stmt2 = $stmtProperty->getValue($conn);
    $conn->prepare('SELECT 3');
    $stmt3 = $stmtProperty->getValue($conn);
    $conn->prepare('SELECT 4');
    $stmt4 = $stmtProperty->getValue($conn);
    
    $pool->put('key1', $stmt1);
    $pool->put('key2', $stmt2);
    $pool->put('key3', $stmt3);
    
    // Access key1 - moves to MRU
    $pool->get('key1');
    
    // Add key4 - should evict key2 (least recently used, not key1)
    $pool->put('key4', $stmt4);
    $this->assertNotNull($pool->get('key1')); // Still cached (MRU)
    $this->assertNull($pool->get('key2')); // Evicted
    }

    public function testPreparedStatementPoolClear(): void
    {
    $pool = new PreparedStatementPool(10, true);
    $conn = self::$db->connection;
    $reflection = new ReflectionClass($conn);
    $stmtProperty = $reflection->getProperty('stmt');
    $stmtProperty->setAccessible(true);
    
    $conn->prepare('SELECT 1');
    $stmt1 = $stmtProperty->getValue($conn);
    $conn->prepare('SELECT 2');
    $stmt2 = $stmtProperty->getValue($conn);
    
    $pool->put('key1', $stmt1);
    $pool->put('key2', $stmt2);
    $pool->get('key1');
    $this->assertEquals(2, $pool->size());
    $this->assertEquals(1, $pool->getHits());
    
    $pool->clear();
    $this->assertEquals(0, $pool->size());
    $this->assertEquals(0, $pool->getHits());
    $this->assertEquals(0, $pool->getMisses());
    }

    public function testPreparedStatementPoolClearStats(): void
    {
    $pool = new PreparedStatementPool(10, true);
    $conn = self::$db->connection;
    $reflection = new ReflectionClass($conn);
    $stmtProperty = $reflection->getProperty('stmt');
    $stmtProperty->setAccessible(true);
    
    $conn->prepare('SELECT 1');
    $stmt1 = $stmtProperty->getValue($conn);
    
    $pool->put('key1', $stmt1);
    $pool->get('key1');
    $pool->get('key2'); // miss
    $this->assertEquals(1, $pool->getHits());
    $this->assertEquals(1, $pool->getMisses());
    
    $pool->clearStats();
    $this->assertEquals(0, $pool->getHits());
    $this->assertEquals(0, $pool->getMisses());
    $this->assertEquals(1, $pool->size()); // Items remain
    }

    public function testPreparedStatementPoolInvalidate(): void
    {
    $pool = new PreparedStatementPool(10, true);
    $conn = self::$db->connection;
    $reflection = new ReflectionClass($conn);
    $stmtProperty = $reflection->getProperty('stmt');
    $stmtProperty->setAccessible(true);
    
    $conn->prepare('SELECT 1');
    $stmt1 = $stmtProperty->getValue($conn);
    $conn->prepare('SELECT 2');
    $stmt2 = $stmtProperty->getValue($conn);
    
    $pool->put('key1', $stmt1);
    $pool->put('key2', $stmt2);
    $this->assertEquals(2, $pool->size());
    
    $pool->invalidate('key1');
    $this->assertEquals(1, $pool->size());
    $this->assertNull($pool->get('key1'));
    $this->assertNotNull($pool->get('key2'));
    
    // Invalidate non-existing key - no error
    $pool->invalidate('key999');
    $this->assertEquals(1, $pool->size());
    }

    public function testPreparedStatementPoolCapacityChange(): void
    {
    $pool = new PreparedStatementPool(5, true);
    $conn = self::$db->connection;
    $reflection = new ReflectionClass($conn);
    $stmtProperty = $reflection->getProperty('stmt');
    $stmtProperty->setAccessible(true);
    
    $stmts = [];
    for ($i = 1; $i <= 5; $i++) {
    $conn->prepare("SELECT $i");
    $stmts[$i] = $stmtProperty->getValue($conn);
    $pool->put("key$i", $stmts[$i]);
    }
    $this->assertEquals(5, $pool->size());
    
    // Reduce capacity - should evict LRU
    $pool->setCapacity(3);
    $this->assertEquals(3, $pool->size());
    // key1 and key2 should be evicted (LRU)
    $this->assertNull($pool->get('key1'));
    $this->assertNull($pool->get('key2'));
    $this->assertNotNull($pool->get('key3'));
    }

    public function testPreparedStatementPoolIntegration(): void
    {
    $pool = new PreparedStatementPool(10, true);
    $conn = self::$db->connection;
    $conn->setStatementPool($pool);
    
    $sql = 'SELECT :val AS result';
    $key = sha1('sqlite|' . $sql);
    
    // First prepare - cache miss
    $conn->prepare($sql);
    $this->assertEquals(0, $pool->getHits());
    $this->assertEquals(1, $pool->getMisses());
    $this->assertEquals(1, $pool->size());
    
    // Second prepare - cache hit
    $conn->prepare($sql);
    $this->assertEquals(1, $pool->getHits());
    $this->assertEquals(1, $pool->getMisses());
    $this->assertEquals(1, $pool->size());
    
    // Different SQL - cache miss
    $conn->prepare('SELECT 1');
    $this->assertEquals(1, $pool->getHits());
    $this->assertEquals(2, $pool->getMisses());
    $this->assertEquals(2, $pool->size());
    }

    public function testPreparedStatementPoolViaConfig(): void
    {
    $db = new PdoDb('sqlite', [
    'path' => ':memory:',
    'stmt_pool' => [
    'enabled' => true,
    'capacity' => 100,
    ],
    ]);
    
    $pool = $db->connection->getStatementPool();
    $this->assertNotNull($pool);
    $this->assertTrue($pool->isEnabled());
    $this->assertEquals(100, $pool->capacity());
    
    // Test that pool works
    $db->rawQuery('CREATE TABLE test_pool (id INTEGER PRIMARY KEY, name TEXT)');
    $db->find()->table('test_pool')->insert(['name' => 'Test']);
    
    // Clear pool stats to start fresh
    $initialSize = $pool->size();
    $pool->clearStats();
    
    $sql = 'SELECT * FROM test_pool WHERE id = :id';
    $db->connection->prepare($sql);
    $this->assertEquals(0, $pool->getHits());
    $this->assertEquals(1, $pool->getMisses());
    $this->assertEquals($initialSize + 1, $pool->size());
    
    $db->connection->prepare($sql);
    $this->assertEquals(1, $pool->getHits());
    $this->assertEquals(1, $pool->getMisses());
    }

    public function testPreparedStatementPoolDisabledViaConfig(): void
    {
    $db = new PdoDb('sqlite', [
    'path' => ':memory:',
    'stmt_pool' => [
    'enabled' => false,
    ],
    ]);
    
    $pool = $db->connection->getStatementPool();
    $this->assertNull($pool);
    }

    public function testPreparedStatementPoolDefaultCapacity(): void
    {
    $db = new PdoDb('sqlite', [
    'path' => ':memory:',
    'stmt_pool' => [
    'enabled' => true,
    // capacity not specified, should default to 256
    ],
    ]);
    
    $pool = $db->connection->getStatementPool();
    $this->assertNotNull($pool);
    $this->assertEquals(256, $pool->capacity());
    }
}
