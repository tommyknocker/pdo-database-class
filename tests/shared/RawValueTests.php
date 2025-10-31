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
 * RawValueTests tests for shared.
 */
final class RawValueTests extends BaseSharedTestCase
{
    public function testCaseStatementWithRawSql(): void
    {
    self::$db->rawQuery('INSERT INTO test_coverage (value) VALUES (5), (15), (25)');
    
    $results = self::$db->find()
    ->from('test_coverage')
    ->select([
    'value',
    'category' => Db::raw("CASE WHEN value < 10 THEN 'low' WHEN value < 20 THEN 'medium' ELSE 'high' END"),
    ])
    ->orderBy('value')
    ->get();
    
    $this->assertCount(3, $results);
    $this->assertEquals('low', $results[0]['category']);
    $this->assertEquals('medium', $results[1]['category']);
    $this->assertEquals('high', $results[2]['category']);
    }

    public function testWhereJsonPathWithRawValue(): void
    {
    // Create table with JSON column
    self::$db->rawQuery('
    CREATE TABLE IF NOT EXISTS test_json_raw (
    id INTEGER PRIMARY KEY,
    data TEXT
    )
    ');
    
    self::$db->find()->table('test_json_raw')->insert([
    'data' => json_encode(['count' => 5]),
    ]);
    
    // Test whereJsonPath with RawValue
    // This tests the code path where value is RawValue instance
    $results = self::$db->find()
    ->table('test_json_raw')
    ->whereJsonPath('data', 'count', '>', Db::raw('3'))
    ->get();
    
    // Should find the row since json count (5) > 3
    $this->assertCount(1, $results);
    
    self::$db->rawQuery('DROP TABLE test_json_raw');
    }

    public function testUpdateWithUnknownOperationAndRawValue(): void
    {
    $id = self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 10]);
    
    // Test unknown operation with RawValue
    self::$db->find()
    ->table('test_coverage')
    ->where('id', $id)
    ->update(['value' => ['__op' => 'multiply', 'val' => Db::raw('value * 2')]]);
    
    $row = self::$db->find()->table('test_coverage')->where('id', $id)->getOne();
    $this->assertEquals(20, $row['value']);
    }

    public function testCteWithRawSql(): void
    {
    $db = self::$db;
    $db->rawQuery('CREATE TABLE test_cte_raw (id INTEGER PRIMARY KEY, num INTEGER)');
    $db->find()->table('test_cte_raw')->insertMulti([
    ['id' => 1, 'num' => 10],
    ['id' => 2, 'num' => 20],
    ]);
    
    $results = $db->find()
    ->with('doubled', Db::raw('SELECT id, num * 2 as num FROM test_cte_raw'))
    ->from('doubled')
    ->get();
    
    $this->assertCount(2, $results);
    $this->assertEquals(20, $results[0]['num']);
    $this->assertEquals(40, $results[1]['num']);
    }
}
