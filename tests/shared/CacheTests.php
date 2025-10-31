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
use tommyknocker\pdodb\query\cache\QueryCompilationCache;

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

    public function testQueryCompilationCache(): void
    {
    $cache = new ArrayCache();
    $compilationCache = new QueryCompilationCache($cache);
    
    // Test enabled/disabled
    $this->assertTrue($compilationCache->isEnabled());
    $compilationCache->setEnabled(false);
    $this->assertFalse($compilationCache->isEnabled());
    $compilationCache->setEnabled(true);
    $this->assertTrue($compilationCache->isEnabled());
    
    // Test TTL
    $this->assertEquals(86400, $compilationCache->getDefaultTtl());
    $compilationCache->setDefaultTtl(3600);
    $this->assertEquals(3600, $compilationCache->getDefaultTtl());
    
    // Test prefix
    $compilationCache->setPrefix('test_');
    $this->assertNotNull($compilationCache->getCache());
    }
    
    public function testQueryCompilationCacheHashing(): void
    {
    $cache = new ArrayCache();
    $compilationCache = new QueryCompilationCache($cache);
    
    $structure1 = [
        'table' => 'users',
        'select' => ['id', 'name'],
        'distinct' => false,
        'distinct_on' => [],
        'joins' => [],
        'where' => [['type' => 'where', 'column' => 'active', 'operator' => '=', 'has_value' => true]],
        'group_by' => null,
        'having' => [],
        'order_by' => ['id'],
        'limit' => 10,
        'offset' => 0,
        'options' => [],
        'unions' => [],
        'cte' => null,
    ];
    
    $structure2 = [
        'table' => 'users',
        'select' => ['id', 'name'],
        'distinct' => false,
        'distinct_on' => [],
        'joins' => [],
        'where' => [['type' => 'where', 'column' => 'active', 'operator' => '=', 'has_value' => true]],
        'group_by' => null,
        'having' => [],
        'order_by' => ['id'],
        'limit' => 10,
        'offset' => 0,
        'options' => [],
        'unions' => [],
        'cte' => null,
    ];
    
    // Use reflection to access protected method
    $reflection = new \ReflectionClass($compilationCache);
    $method = $reflection->getMethod('hashQueryStructure');
    $method->setAccessible(true);
    
    $hash1 = $method->invoke($compilationCache, $structure1, 'sqlite');
    $hash2 = $method->invoke($compilationCache, $structure2, 'sqlite');
    
    // Same structure should produce same hash
    $this->assertEquals($hash1, $hash2);
    $this->assertEquals(64, strlen($hash1)); // SHA-256 produces 64 char hex string
    
    // Different driver should produce different hash
    $hash3 = $method->invoke($compilationCache, $structure1, 'mysql');
    $this->assertNotEquals($hash1, $hash3);
    
    // Different structure should produce different hash
    $structure3 = $structure1;
    $structure3['select'] = ['id', 'email']; // Different select
    $hash4 = $method->invoke($compilationCache, $structure3, 'sqlite');
    $this->assertNotEquals($hash1, $hash4);
    }
    
    public function testQueryCompilationCacheGetOrCompile(): void
    {
    $cache = new ArrayCache();
    $compilationCache = new QueryCompilationCache($cache);
    $compilationCache->setPrefix('compiled_');
    
    $structure = [
        'table' => 'users',
        'select' => ['id', 'name'],
        'distinct' => false,
        'distinct_on' => [],
        'joins' => [],
        'where' => [],
        'group_by' => null,
        'having' => [],
        'order_by' => [],
        'limit' => null,
        'offset' => null,
        'options' => [],
        'unions' => [],
        'cte' => null,
    ];
    
    $callCount = 0;
    $compiler = function () use (&$callCount): string {
        $callCount++;
        return 'SELECT id, name FROM users';
    };
    
    // First call - should compile
    $result1 = $compilationCache->getOrCompile($compiler, $structure, 'sqlite');
    $this->assertEquals('SELECT id, name FROM users', $result1);
    $this->assertEquals(1, $callCount);
    
    // Second call - should use cache
    $result2 = $compilationCache->getOrCompile($compiler, $structure, 'sqlite');
    $this->assertEquals('SELECT id, name FROM users', $result2);
    $this->assertEquals(1, $callCount); // Compiler should not be called again
    
    // Different structure - should compile again
    $structure2 = $structure;
    $structure2['select'] = ['id'];
    $result3 = $compilationCache->getOrCompile($compiler, $structure2, 'sqlite');
    $this->assertEquals('SELECT id, name FROM users', $result3);
    $this->assertEquals(2, $callCount); // Compiler called again for different structure
    }
    
    public function testQueryCompilationCacheDisabled(): void
    {
    $cache = new ArrayCache();
    $compilationCache = new QueryCompilationCache($cache);
    $compilationCache->setEnabled(false);
    
    $structure = [
        'table' => 'users',
        'select' => ['id'],
        'distinct' => false,
        'distinct_on' => [],
        'joins' => [],
        'where' => [],
        'group_by' => null,
        'having' => [],
        'order_by' => [],
        'limit' => null,
        'offset' => null,
        'options' => [],
        'unions' => [],
        'cte' => null,
    ];
    
    $callCount = 0;
    $compiler = function () use (&$callCount): string {
        $callCount++;
        return 'SELECT id FROM users';
    };
    
    // Both calls should compile (cache disabled)
    $result1 = $compilationCache->getOrCompile($compiler, $structure, 'sqlite');
    $result2 = $compilationCache->getOrCompile($compiler, $structure, 'sqlite');
    
    $this->assertEquals('SELECT id FROM users', $result1);
    $this->assertEquals('SELECT id FROM users', $result2);
    $this->assertEquals(2, $callCount); // Compiler called twice
    }
    
    public function testQueryCompilationCacheWithActualQueries(): void
    {
    $cache = new ArrayCache();
    $compilationCache = new QueryCompilationCache($cache);
    $compilationCache->setEnabled(true);
    
    $db = self::$db;
    
    // Clear test data
    $db->find()->table('test_coverage')->delete();
    
    // Insert test data
    $db->find()->table('test_coverage')->insert([
        'name' => 'Test 1',
        'value' => 10
    ]);
    $db->find()->table('test_coverage')->insert([
        'name' => 'Test 2',
        'value' => 20
    ]);
    
    // Create a new PdoDb instance with compilation cache
    // Use sqlite with same configuration
    $newDb = new PdoDb('sqlite', ['path' => ':memory:'], [], null, $cache);
    
    // Create the same test table
    $newDb->rawQuery('
        CREATE TABLE test_coverage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            value INTEGER,
            meta TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    
    // Insert same test data
    $newDb->find()->table('test_coverage')->insert([
        'name' => 'Test 1',
        'value' => 10
    ]);
    $newDb->find()->table('test_coverage')->insert([
        'name' => 'Test 2',
        'value' => 20
    ]);
    
    // Enable compilation cache
    $newDb->setCompilationCache($compilationCache);
    
    // Execute same query structure multiple times
    $query1 = $newDb->find()
        ->from('test_coverage')
        ->where('value', 10)
        ->orderBy('id')
        ->toSQL();
    
    $query2 = $newDb->find()
        ->from('test_coverage')
        ->where('value', 20) // Different parameter, same structure
        ->orderBy('id')
        ->toSQL();
    
    // Both should have same SQL structure (parameters are separate)
    $this->assertStringContainsString('SELECT', $query1['sql']);
    $this->assertStringContainsString('FROM', $query1['sql']);
    $this->assertStringContainsString('WHERE', $query1['sql']);
    
    // Verify queries work
    $result1 = $newDb->find()
        ->from('test_coverage')
        ->where('value', 10)
        ->orderBy('id')
        ->get();
    
    $result2 = $newDb->find()
        ->from('test_coverage')
        ->where('value', 20)
        ->orderBy('id')
        ->get();
    
    $this->assertCount(1, $result1);
    $this->assertCount(1, $result2);
    $this->assertEquals('Test 1', $result1[0]['name']);
    $this->assertEquals('Test 2', $result2[0]['name']);
    }
}
