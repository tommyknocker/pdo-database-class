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
 * CacheTests tests for shared.
 */
final class CacheTests extends BaseSharedTestCase
{
    public function testDialectRegistryAndCacheManagerCoverage(): void
    {
    // DialectRegistry
    $drivers = DialectRegistry::getSupportedDrivers();
    $this->assertNotEmpty($drivers);
    $this->assertTrue(DialectRegistry::isSupported('sqlite'));
    $dialect = DialectRegistry::resolve('sqlite');
    $this->assertEquals('sqlite', $dialect->getDriverName());
    
    // CacheManager basic ops
    $cache = new ArrayCache();
    $cm = new CacheManager($cache, ['enabled' => true, 'default_ttl' => 60, 'prefix' => 'p']);
    $key = $cm->generateKey('SELECT 1', ['a' => 1], 'sqlite');
    $this->assertIsString($key);
    $this->assertFalse($cm->has($key));
    $this->assertTrue($cm->set($key, 'val'));
    $this->assertTrue($cm->has($key));
    $this->assertEquals('val', $cm->get($key));
    $this->assertTrue($cm->delete($key));
    $this->assertTrue($cm->clear());
    }

    public function testNoCacheManager(): void
    {
    // Use the existing database without cache
    $result = self::$db->find()
    ->table('test_coverage')
    ->insert(['name' => 'Test', 'value' => 100]);
    
    $this->assertGreaterThan(0, $result);
    
    // Try to use cache methods (should be no-op)
    $data = self::$db->find()
    ->from('test_coverage')
    ->where('value', 100)
    ->cache(3600) // This should be ignored since no cache manager
    ->get();
    
    $this->assertCount(1, $data);
    $this->assertEquals('Test', $data[0]['name']);
    }
}
