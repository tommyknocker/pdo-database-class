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
 * IntrospectionTests tests for shared.
 */
final class IntrospectionTests extends BaseSharedTestCase
{
    public function testDescribeTable(): void
    {
    $structure = self::$db->describe('test_coverage');
    $this->assertIsArray($structure);
    $this->assertNotEmpty($structure);
    
    // Should have columns
    $this->assertGreaterThan(0, count($structure));
    }

    public function testExplainAnalyze(): void
    {
    $result = self::$db->explainAnalyze('SELECT * FROM test_coverage WHERE id = 1');
    $this->assertIsArray($result);
    }

    public function testExplainDescribeAndIndexes(): void
    {
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    $db->rawQuery('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, age INT)');
    $db->rawQuery('CREATE INDEX idx_t_name ON t(name)');
    
    $report = $db->find()->from('t')->where('name', 'Alice')->explain();
    $this->assertIsArray($report);
    
    $reportAnalyze = $db->find()->from('t')->where('name', 'Alice')->explainAnalyze();
    $this->assertIsArray($reportAnalyze);
    
    $desc = $db->find()->from('t')->describe();
    $this->assertIsArray($desc);
    
    $idx = $db->find()->from('t')->indexes();
    $this->assertIsArray($idx);
    
    $keys = $db->find()->from('t')->keys();
    $this->assertIsArray($keys);
    
    $constraints = $db->find()->from('t')->constraints();
    $this->assertIsArray($constraints);
    }
}
