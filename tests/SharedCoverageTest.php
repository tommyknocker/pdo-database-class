<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use Exception;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
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

/**
 * Shared coverage tests for dialect-independent code
 * Runs against SQLite for speed and simplicity.
 */
class SharedCoverageTest extends TestCase
{
    private static PdoDb $db;

    public static function setUpBeforeClass(): void
    {
        self::$db = new PdoDb('sqlite', ['path' => ':memory:']);

        // Create test table
        self::$db->rawQuery('
            CREATE TABLE test_coverage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                value INTEGER,
                meta TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    /**
     * Clean up test data before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Clear all data from test table before each test
        self::$db->rawQuery('DELETE FROM test_coverage');
        // Reset auto-increment counter (SQLite specific)
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name='test_coverage'");
    }

    /* ---------------- Connection Pooling Tests ---------------- */

    public function testUninitializedConnection(): void
    {
        $db = new PdoDb();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection not initialized');
        $db->find();
    }

    public function testConnectionNotFound(): void
    {
        $db = new PdoDb();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection nonexistent not found');
        $db->connection('nonexistent');
    }

    public function testConnectionPooling(): void
    {
        $db = new PdoDb();

        $db->addConnection('primary', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->addConnection('secondary', ['driver' => 'sqlite', 'path' => ':memory:']);

        // Switch to primary
        $result = $db->connection('primary');
        $this->assertInstanceOf(PdoDb::class, $result);

        // Create table in primary
        $db->rawQuery('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        $db->rawQuery('INSERT INTO test (id) VALUES (1)');

        $count1 = $db->rawQueryValue('SELECT COUNT(*) FROM test');
        $this->assertEquals(1, $count1);

        // Switch to secondary
        $db->connection('secondary');
        $db->rawQuery('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        $count2 = $db->rawQueryValue('SELECT COUNT(*) FROM test');
        $this->assertEquals(0, $count2); // Different database

        // Switch back to primary
        $db->connection('primary');
        $count3 = $db->rawQueryValue('SELECT COUNT(*) FROM test');
        $this->assertEquals(1, $count3);
    }

    /* ---------------- PdoDb Helper Methods Tests ---------------- */

    public function testSetLockMethod(): void
    {
        $result = self::$db->setLockMethod('READ');
        $this->assertInstanceOf(PdoDb::class, $result);

        $result2 = self::$db->setLockMethod('write'); // lowercase
        $this->assertInstanceOf(PdoDb::class, $result2);

        $result3 = self::$db->setLockMethod('WRITE'); // uppercase
        $this->assertInstanceOf(PdoDb::class, $result3);
    }

    /* ---------------- Introspection Methods Tests ---------------- */

    public function testDescribeTable(): void
    {
        $structure = self::$db->describe('test_coverage');
        $this->assertIsArray($structure);
        $this->assertNotEmpty($structure);

        // Should have columns
        $this->assertGreaterThan(0, count($structure));
    }

    public function testExplainQuery(): void
    {
        $result = self::$db->explain('SELECT * FROM test_coverage WHERE id = 1');
        $this->assertIsArray($result);
    }

    public function testExplainAnalyze(): void
    {
        $result = self::$db->explainAnalyze('SELECT * FROM test_coverage WHERE id = 1');
        $this->assertIsArray($result);
    }

    /* ---------------- Error Scenarios Tests ---------------- */

    public function testInvalidSqlError(): void
    {
        $this->expectException(PDOException::class);
        self::$db->rawQuery('THIS IS NOT VALID SQL SYNTAX');
    }

    public function testQueryNonexistentTable(): void
    {
        $this->expectException(PDOException::class);
        self::$db->rawQuery('SELECT * FROM absolutely_nonexistent_table_xyz_123');
    }

    public function testInsertIntoNonexistentTable(): void
    {
        $this->expectException(PDOException::class);
        self::$db->find()->table('nonexistent_xyz')->insert(['name' => 'test']);
    }

    /* ---------------- Db Helper Edge Cases Tests ---------------- */

    public function testDbHelpersWithNullValues(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (NULL, NULL)');

        // ifNull with NULL
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['name_or_default' => Db::ifNull('name', 'N/A')])
            ->where(Db::isNull('name'))
            ->getValue();

        $this->assertEquals('N/A', $result);
    }

    public function testCoalesceWithAllNulls(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (NULL, NULL)');

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::coalesce('name', 'value', Db::raw("'fallback'"))])
            ->where(Db::isNull('name'))
            ->getValue();

        $this->assertEquals('fallback', $result);
    }

    public function testNullIfEdgeCases(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES ('test', 5)");

        // NullIf when equal
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::nullIf('value', Db::raw('5'))])
            ->where('name', 'test')
            ->getValue();

        $this->assertNull($result);
    }

    public function testGreatestWithMixedTypes(): void
    {
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['max' => Db::greatest(Db::raw('1'), Db::raw('5'), Db::raw('3'))])
            ->getValue();

        $this->assertEquals(5, $result);
    }

    public function testLeastWithMixedTypes(): void
    {
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['min' => Db::least(Db::raw('10'), Db::raw('5'), Db::raw('8'))])
            ->getValue();

        $this->assertEquals(5, $result);
    }

    public function testModWithNegativeNumbers(): void
    {
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::mod(Db::raw('-10'), Db::raw('3'))])
            ->getValue();

        // -10 % 3 = -1
        $this->assertEquals(-1, $result);
    }

    public function testAbsWithNegative(): void
    {
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::abs(Db::raw('-42.5'))])
            ->getValue();

        $this->assertEquals(42.5, $result);
    }

    public function testRoundWithPrecision(): void
    {
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'round_0' => Db::round(Db::raw('3.14159'), 0),
                'round_2' => Db::round(Db::raw('3.14159'), 2),
                'round_4' => Db::round(Db::raw('3.14159'), 4),
            ])
            ->getOne();

        $this->assertEquals(3, $result['round_0']);
        $this->assertEquals(3.14, $result['round_2']);
        $this->assertEquals(3.1416, $result['round_4']);
    }

    public function testStringHelpersWithEmptyStrings(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'len' => Db::length('name'),
                'upper' => Db::upper('name'),
                'lower' => Db::lower('name'),
            ])
            ->where('name', '')
            ->getOne();

        $this->assertEquals(0, $result['len']);
        $this->assertEquals('', $result['upper']);
        $this->assertEquals('', $result['lower']);
    }

    public function testSubstringEdgeCases(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('Hello World')");

        // Substring from start
        $result1 = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::substring('name', 1, 5)])
            ->where('name', 'Hello World')
            ->getValue();
        $this->assertEquals('Hello', $result1);

        // Substring without length (to end)
        $result2 = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::substring('name', 7)])
            ->where('name', 'Hello World')
            ->getValue();
        $this->assertEquals('World', $result2);
    }

    public function testReplaceWithMultipleOccurrences(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('foo bar foo baz foo')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::replace('name', 'foo', 'X')])
            ->where(Db::like('name', '%foo%'))
            ->getValue();

        $this->assertEquals('X bar X baz X', $result);
    }

    public function testConcatWithNullValues(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES ('Test', NULL)");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::concat('name', Db::raw("' - '"), Db::raw("'End'"))])
            ->where('name', 'Test')
            ->getValue();

        $this->assertStringContainsString('Test', $result);
        $this->assertStringContainsString('End', $result);
    }

    /* ---------------- Date/Time Helper Tests ---------------- */

    public function testCurDateCurTime(): void
    {
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'current_date' => Db::curDate(),
                'current_time' => Db::curTime(),
            ])
            ->limit(1)
            ->getOne();

        $this->assertNotEmpty($result['current_date']);
        $this->assertNotEmpty($result['current_time']);
    }

    public function testDateTimeExtraction(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (created_at) VALUES ('2025-10-19 15:30:45')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'year' => Db::year('created_at'),
                'month' => Db::month('created_at'),
                'day' => Db::day('created_at'),
                'hour' => Db::hour('created_at'),
                'minute' => Db::minute('created_at'),
                'second' => Db::second('created_at'),
            ])
            ->where('created_at', '2025-10-19 15:30:45')
            ->getOne();

        $this->assertEquals(2025, $result['year']);
        $this->assertEquals(10, $result['month']);
        $this->assertEquals(19, $result['day']);
        $this->assertEquals(15, $result['hour']);
        $this->assertEquals(30, $result['minute']);
        $this->assertEquals(45, $result['second']);
    }

    public function testDateAndTimeExtraction(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (created_at) VALUES ('2025-10-19 15:30:45')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'just_date' => Db::date('created_at'),
                'just_time' => Db::time('created_at'),
            ])
            ->where('created_at', '2025-10-19 15:30:45')
            ->getOne();

        $this->assertStringContainsString('2025', $result['just_date']);
        $this->assertStringContainsString('15:30', $result['just_time']);
    }

    /* ---------------- Cast and Comparison Tests ---------------- */

    public function testCastDifferentTypes(): void
    {
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'as_int' => Db::cast('123.45', 'INTEGER'),
                'as_text' => Db::cast('123', 'TEXT'),
            ])
            ->limit(1)
            ->getOne();

        $this->assertEquals(123, $result['as_int']);
        $this->assertEquals('123', $result['as_text']);
    }

    public function testCastInWhereClause(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('42')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->where(Db::cast('name', 'INTEGER'), 42)
            ->getOne();

        $this->assertNotNull($result);
        $this->assertEquals('42', $result['name']);
    }

    /* ---------------- Aggregate Functions Tests ---------------- */

    public function testAggregateHelpers(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (value) VALUES (10), (20), (30)');

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'total' => Db::count(),
                'sum' => Db::sum('value'),
                'avg' => Db::avg('value'),
                'min' => Db::min('value'),
                'max' => Db::max('value'),
            ])
            ->getOne();

        $this->assertEquals(3, $result['total']);
        $this->assertEquals(60, $result['sum']);
        $this->assertEquals(20, $result['avg']);
        $this->assertEquals(10, $result['min']);
        $this->assertEquals(30, $result['max']);
    }

    public function testCountDistinct(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (value) VALUES (1), (1), (2)');

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['distinct_count' => Db::count('DISTINCT value')])
            ->getValue();

        $this->assertEquals(2, $result); // Only 1 and 2
    }

    /* ---------------- Comparison Operators Tests ---------------- */

    public function testBetweenOperator(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (value) VALUES (5), (15), (25)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::between('value', 10, 20))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(15, $results[0]['value']);
    }

    public function testNotBetweenOperator(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (value) VALUES (5), (15), (25)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::notBetween('value', 10, 20))
            ->get();

        $this->assertCount(2, $results); // 5 and 25
    }

    public function testInOperator(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (value) VALUES (1), (2), (3), (4), (5)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::in('value', [2, 4]))
            ->get();

        $this->assertCount(2, $results);
    }

    public function testNotInOperator(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (value) VALUES (1), (2), (3)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::notIn('value', [2]))
            ->get();

        $this->assertCount(2, $results); // 1 and 3
    }

    public function testLikeOperator(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('Hello'), ('World'), ('Help')");

        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::like('name', 'Hel%'))
            ->get();

        $this->assertCount(2, $results); // Hello and Help
    }

    public function testNotOperator(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('Test'), ('Other')");

        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::not(Db::like('name', 'Test%')))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Other', $results[0]['name']);
    }

    /* ---------------- CASE Statement Tests ---------------- */

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

    /* ---------------- Boolean Helpers Tests ---------------- */

    public function testBooleanHelpers(): void
    {
        // Test Db::true() and Db::false() return RawValue
        $trueVal = Db::true();
        $falseVal = Db::false();

        $this->assertInstanceOf(RawValue::class, $trueVal);
        $this->assertInstanceOf(RawValue::class, $falseVal);
        $this->assertEquals('TRUE', $trueVal->getValue());
        $this->assertEquals('FALSE', $falseVal->getValue());

        // Test in query
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES ('active', 1), ('inactive', 0)");

        $active = self::$db->find()
            ->from('test_coverage')
            ->where('value', 1)
            ->get();

        $this->assertCount(1, $active);
    }

    /* ---------------- NULL Helpers Tests ---------------- */

    public function testIsNullIsNotNull(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES ('test', NULL), ('test2', 42)");

        $nulls = self::$db->find()
            ->from('test_coverage')
            ->where(Db::isNull('value'))
            ->get();

        $this->assertCount(1, $nulls);

        $notNulls = self::$db->find()
            ->from('test_coverage')
            ->where(Db::isNotNull('value'))
            ->get();

        $this->assertCount(1, $notNulls);
    }

    /* ---------------- Transaction Edge Cases ---------------- */

    public function testTransactionRollbackOnError(): void
    {
        self::$db->startTransaction();

        try {
            self::$db->find()->table('test_coverage')->insert(['name' => 'Before Error']);

            // Intentionally cause error
            throw new Exception('Simulated error');
        } catch (Exception $e) {
            self::$db->rollback();
        }

        // Verify rollback worked
        $count = self::$db->rawQueryValue('SELECT COUNT(*) FROM test_coverage');
        $this->assertEquals(0, $count);
    }

    /* ---------------- Trim Variants Tests ---------------- */

    public function testTrimVariants(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('  test  ')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'trimmed' => Db::trim('name'),
                'ltrimmed' => Db::ltrim('name'),
                'rtrimmed' => Db::rtrim('name'),
            ])
            ->getOne();

        $this->assertEquals('test', $result['trimmed']);
        $this->assertEquals('test  ', $result['ltrimmed']);
        $this->assertEquals('  test', $result['rtrimmed']);
    }

    /* ---------------- Connection Methods Coverage ---------------- */

    public function testGetPdo(): void
    {
        $connection = self::$db->connection;
        $pdo = $connection->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testGetLastError(): void
    {
        $connection = self::$db->connection;

        // Initially null
        $this->assertNull($connection->getLastError());

        // After error, should be set
        try {
            self::$db->rawQuery('SELECT * FROM nonexistent_table');
        } catch (PDOException $e) {
            $lastError = $connection->getLastError();
            $this->assertNotNull($lastError);
            $this->assertStringContainsString('nonexistent_table', $lastError);
        }
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

    public function testInsertMultiWithEmptyRows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insertMulti requires at least one row');

        self::$db->find()->table('test_coverage')->insertMulti([]);
    }

    public function testReplaceMultiWithEmptyRows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('replaceMulti requires at least one row');

        self::$db->find()->table('test_coverage')->replaceMulti([]);
    }

    public function testOrderByWithInvalidDirection(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 2]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 1]);

        // Invalid direction should default to ASC
        $results = self::$db->find()
            ->table('test_coverage')
            ->select(['name'])
            ->orderBy('name', 'INVALID')
            ->get();

        // Check that ordering was applied (default ASC)
        $this->assertCount(2, $results);
        $this->assertEquals('A', $results[0]['name']);
        $this->assertEquals('B', $results[1]['name']);
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

    public function testUpdateWithUnknownOperation(): void
    {
        $id = self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 10]);

        // Test unknown operation with val
        self::$db->find()
            ->table('test_coverage')
            ->where('id', $id)
            ->update(['value' => ['__op' => 'custom', 'val' => 99]]);

        $row = self::$db->find()->table('test_coverage')->where('id', $id)->getOne();
        $this->assertEquals(99, $row['value']);
    }

    public function testUpdateWithUnknownOperationMissingVal(): void
    {
        $id = self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 10]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing 'val' for operation");

        // Test unknown operation without val
        self::$db->find()
            ->table('test_coverage')
            ->where('id', $id)
            ->update(['value' => ['__op' => 'custom']]);
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

    /* ---------------- Connection Exception Handling Tests ---------------- */

    public function testPrepareException(): void
    {
        $connection = self::$db->connection;

        // Test that prepare() exception is caught and re-thrown
        $this->expectException(PDOException::class);

        try {
            $connection->prepare('INVALID SQL SYNTAX HERE @#$%');
        } catch (PDOException $e) {
            // Verify lastError is set
            $this->assertNotNull($connection->getLastError());
            $this->assertGreaterThanOrEqual(0, $connection->getLastErrno());

            throw $e;
        }
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

    public function testExecuteException(): void
    {
        $connection = self::$db->connection;

        $this->expectException(PDOException::class);

        try {
            // Create a constraint violation
            $connection->prepare('INSERT INTO test_coverage (id, name) VALUES (?, ?)')
                ->execute([999, 'test']);
            // Try to insert same id again
            $connection->prepare('INSERT INTO test_coverage (id, name) VALUES (?, ?)')
                ->execute([999, 'test2']);
        } catch (PDOException $e) {
            $this->assertNotNull($connection->getLastError());

            throw $e;
        }
    }

    public function testTransactionBeginException(): void
    {
        // SQLite throws exception on nested transactions
        self::$db->startTransaction();

        $this->expectException(PDOException::class);

        try {
            // Try to start nested transaction - should fail
            self::$db->connection->transaction();
        } finally {
            // Cleanup - rollback the outer transaction
            if (self::$db->connection->inTransaction()) {
                self::$db->rollback();
            }
        }
    }

    public function testCommitException(): void
    {
        $connection = self::$db->connection;

        // Ensure no active transaction
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        // Try to commit without active transaction
        $this->expectException(PDOException::class);

        $connection->commit();
    }

    public function testRollbackException(): void
    {
        $connection = self::$db->connection;

        // Ensure no active transaction
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        // Try to rollback without active transaction
        $this->expectException(PDOException::class);

        $connection->rollBack();
    }

    /* ---------------- DialectAbstract Edge Cases Tests ---------------- */

    public function testQuoteTableWithAliasUsingAS(): void
    {
        // Test table alias with AS keyword
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage AS tc')
            ->select(['tc.name'])
            ->limit(1)
            ->getOne();

        // Verify AS syntax works
        $this->assertArrayHasKey('name', $result);
    }

    public function testNormalizeJsonPathWithEmptyString(): void
    {
        // Test with empty JSON path (should return empty array)
        // This tests the edge case in normalizeJsonPath
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'meta' => '{}']);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['data' => Db::jsonGet('meta', [])])
            ->limit(1)
            ->getOne();

        // Should execute without error
        $this->assertArrayHasKey('data', $result);
    }

    public function testFormatDefaultValueWithNumbers(): void
    {
        // Test formatDefaultValue with numeric values
        // Covered through coalesce with different types
        // Insert a dummy row since we need at least one row for SELECT to return results
        self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 1]);

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::coalesce('value', Db::raw('0'))])
            ->limit(1)
            ->getOne();

        $this->assertArrayHasKey('result', $result);
    }

    /* ---------------- Db::concat() Edge Cases (Bug Fixes) ---------------- */

    public function testConcatWithStringLiterals(): void
    {
        // Test that string literals with spaces are automatically quoted
        // This was a bug where ' ' was treated as column name
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES ('John', 1)");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat('name', ' ', 'value')])
            ->where('name', 'John')
            ->getOne();

        $this->assertNotNull($result, 'Result should not be null');
        $this->assertEquals('John 1', $result['display']);
    }

    public function testConcatWithSpecialCharacters(): void
    {
        // Test concat with various special characters that should be quoted
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES ('TestSpecial', 42)");

        // Test with colon
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat('ID: ', 'value')])
            ->where('name', 'TestSpecial')
            ->getOne();

        $this->assertNotNull($result, 'Result should not be null for colon test');
        $this->assertEquals('ID: 42', $result['display']);

        // Test with pipe
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat('name', ' | ', 'value')])
            ->where('name', 'TestSpecial')
            ->getOne();

        $this->assertNotNull($result, 'Result should not be null for pipe test');
        $this->assertEquals('TestSpecial | 42', $result['display']);

        // Test with dash
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat('name', ' - ', 'value')])
            ->where('name', 'TestSpecial')
            ->getOne();

        $this->assertNotNull($result, 'Result should not be null for dash test');
        $this->assertEquals('TestSpecial - 42', $result['display']);
    }

    public function testConcatWithNestedHelpers(): void
    {
        // Test that Db::upper/lower can be used inside Db::concat
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('alice')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat(Db::upper('name'), ' ', Db::raw("'USER'"))])
            ->where('name', 'alice')
            ->getOne();

        $this->assertEquals('ALICE USER', $result['display']);

        // Test with Db::lower
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat(Db::lower('name'), ' ', Db::raw("'test'"))])
            ->where('name', 'alice')
            ->getOne();

        $this->assertEquals('alice test', $result['display']);
    }

    public function testConcatNestedInHelperThrowsException(): void
    {
        // Test that nesting Db::concat inside Db::upper throws helpful exception
        // This was a bug that caused "Typed property not initialized" error
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('test')");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ConcatValue cannot be used directly in SQL expressions');

        self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::upper(Db::concat('name', ' ', 'value'))])
            ->where('name', 'test')
            ->getOne();
    }

    public function testConcatWithQuotedLiterals(): void
    {
        // Test that already-quoted literals are passed through unchanged
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('Bob')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat('name', Db::raw("' is here'"))])
            ->where('name', 'Bob')
            ->getOne();

        $this->assertEquals('Bob is here', $result['display']);
    }

    public function testConcatWithNumericValues(): void
    {
        // Test concat with numbers (should be converted to string)
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES ('Item', 123)");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat('name', ': ', 'value')])
            ->where('name', 'Item')
            ->getOne();

        $this->assertEquals('Item: 123', $result['display']);
    }

    public function testConcatWithEmptyString(): void
    {
        // Test concat with empty strings
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('Test')");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['display' => Db::concat('name', '', 'name')])
            ->where('name', 'Test')
            ->getOne();

        // Empty string should still be quoted but result in concatenation
        $this->assertIsString($result['display']);
        $this->assertStringContainsString('Test', $result['display']);
    }

    public function testConcatWithMixedTypes(): void
    {
        // Test concat with various mixed types
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES ('Product', 99)");

        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'display' => Db::concat(
                    'Name: ',
                    Db::upper('name'),
                    ' | ',
                    'Price: ',
                    'value',
                    '$'
                ),
            ])
            ->where('name', 'Product')
            ->getOne();

        $this->assertEquals('Name: PRODUCT | Price: 99$', $result['display']);
    }

    /* ---------------- Constants Tests ---------------- */

    public function testLockConstants(): void
    {
        $this->assertEquals('WRITE', PdoDb::LOCK_WRITE);
        $this->assertEquals('READ', PdoDb::LOCK_READ);
    }

    /* ---------------- Transaction Methods Tests ---------------- */

    public function testInTransaction(): void
    {
        // Initially no transaction
        $this->assertFalse(self::$db->inTransaction());

        // Start transaction
        self::$db->startTransaction();
        $this->assertTrue(self::$db->inTransaction());

        // Commit transaction
        self::$db->commit();
        $this->assertFalse(self::$db->inTransaction());
    }

    public function testTransactionCallback(): void
    {
        $result = self::$db->transaction(function ($db) {
            $db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 42]);
            return 'success';
        });

        $this->assertEquals('success', $result);

        // Verify data was committed
        $count = self::$db->rawQueryValue('SELECT COUNT(*) FROM test_coverage WHERE name = ?', ['test']);
        $this->assertEquals(1, $count);

        // Verify no active transaction
        $this->assertFalse(self::$db->inTransaction());
    }

    public function testTransactionCallbackRollback(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Test rollback');

        try {
            self::$db->transaction(function ($db) {
                $db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 42]);

                throw new Exception('Test rollback');
            });
        } catch (Exception $e) {
            // Verify data was rolled back
            $count = self::$db->rawQueryValue('SELECT COUNT(*) FROM test_coverage WHERE name = ?', ['test']);
            $this->assertEquals(0, $count);

            // Verify no active transaction
            $this->assertFalse(self::$db->inTransaction());

            throw $e;
        }
    }

    /* ---------------- Locking Methods Tests ---------------- */

    public function testLockUnlock(): void
    {
        // SQLite doesn't support LOCK TABLES, so we expect an exception
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LOCK TABLES not supported');

        self::$db->lock('test_coverage');
    }

    public function testLockMultipleTables(): void
    {
        // SQLite doesn't support LOCK TABLES, so we expect an exception
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LOCK TABLES not supported');

        self::$db->lock(['test_coverage', 'test_coverage']);
    }

    public function testSetLockMethodWithConstants(): void
    {
        // Test using constants
        $result = self::$db->setLockMethod(PdoDb::LOCK_READ);
        $this->assertInstanceOf(PdoDb::class, $result);

        $result = self::$db->setLockMethod(PdoDb::LOCK_WRITE);
        $this->assertInstanceOf(PdoDb::class, $result);
    }

    public function testSetLockMethodInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid lock method: INVALID');

        self::$db->setLockMethod('INVALID');
    }

    /* ---------------- Connection Methods Tests ---------------- */

    public function testPing(): void
    {
        $result = self::$db->ping();
        $this->assertTrue($result);
    }

    public function testHasConnection(): void
    {
        $db = new PdoDb();

        // Initially no connections
        $this->assertFalse($db->hasConnection('test'));

        // Add connection
        $db->addConnection('test', ['driver' => 'sqlite', 'path' => ':memory:']);
        $this->assertTrue($db->hasConnection('test'));

        // Non-existent connection
        $this->assertFalse($db->hasConnection('nonexistent'));
    }

    public function testDisconnectSpecificConnection(): void
    {
        $db = new PdoDb();

        // Add multiple connections
        $db->addConnection('conn1', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->addConnection('conn2', ['driver' => 'sqlite', 'path' => ':memory:']);

        $this->assertTrue($db->hasConnection('conn1'));
        $this->assertTrue($db->hasConnection('conn2'));

        // Disconnect specific connection
        $db->disconnect('conn1');
        $this->assertFalse($db->hasConnection('conn1'));
        $this->assertTrue($db->hasConnection('conn2'));
    }

    public function testDisconnectAllConnections(): void
    {
        $db = new PdoDb();

        // Add connections
        $db->addConnection('conn1', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->addConnection('conn2', ['driver' => 'sqlite', 'path' => ':memory:']);

        $this->assertTrue($db->hasConnection('conn1'));
        $this->assertTrue($db->hasConnection('conn2'));

        // Disconnect all
        $db->disconnect();
        $this->assertFalse($db->hasConnection('conn1'));
        $this->assertFalse($db->hasConnection('conn2'));
    }

    public function testDisconnectCurrentConnection(): void
    {
        $db = new PdoDb();

        // Add and select connection
        $db->addConnection('test', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->connection('test');

        $this->assertTrue($db->hasConnection('test'));

        // Disconnect current connection
        $db->disconnect('test');
        $this->assertFalse($db->hasConnection('test'));

        // Should throw exception when trying to use disconnected connection
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection not initialized');
        $db->find();
    }

    /* ---------------- Connection Retry Tests ---------------- */

    public function testRetryableConnectionCreation(): void
    {
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 100,
                'retryable_errors' => [
                    DbError::MYSQL_CANNOT_CONNECT,
                    DbError::MYSQL_CONNECTION_KILLED,
                    DbError::MYSQL_CONNECTION_LOST,
                ],
            ],
        ];

        $db = new PdoDb('sqlite', $config);

        // Verify we get a RetryableConnection
        $connection = $db->connection;
        $this->assertInstanceOf(RetryableConnection::class, $connection);

        // Test basic functionality still works
        $db->rawQuery('CREATE TABLE retry_test (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery('INSERT INTO retry_test (name) VALUES (?)', ['test']);

        $result = $db->rawQueryValue('SELECT name FROM retry_test WHERE id = 1');
        $this->assertEquals('test', $result);
    }

    public function testRetryableConnectionConfig(): void
    {
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 5,
                'delay_ms' => 200,
                'backoff_multiplier' => 3,
                'max_delay_ms' => 5000,
                'retryable_errors' => [
                    DbError::MYSQL_CANNOT_CONNECT,
                    DbError::MYSQL_CONNECTION_KILLED,
                    DbError::MYSQL_CONNECTION_LOST,
                    DbError::MYSQL_CONNECTION_KILLED,
                ],
            ],
        ];

        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;

        $this->assertInstanceOf(RetryableConnection::class, $connection);

        if ($connection instanceof RetryableConnection) {
            $retryConfig = $connection->getRetryConfig();
            $this->assertTrue($retryConfig['enabled']);
            $this->assertEquals(5, $retryConfig['max_attempts']);
            $this->assertEquals(200, $retryConfig['delay_ms']);
            $this->assertEquals(3, $retryConfig['backoff_multiplier']);
            $this->assertEquals(5000, $retryConfig['max_delay_ms']);
            $this->assertEquals([
                DbError::MYSQL_CANNOT_CONNECT,
                DbError::MYSQL_CONNECTION_KILLED,
                DbError::MYSQL_CONNECTION_LOST,
                DbError::MYSQL_CONNECTION_KILLED,
            ], $retryConfig['retryable_errors']);
        }
    }

    public function testRetryableConnectionDisabled(): void
    {
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => false,
                'max_attempts' => 3,
                'retryable_errors' => [
                    DbError::MYSQL_CANNOT_CONNECT,
                    DbError::MYSQL_CONNECTION_KILLED,
                    DbError::MYSQL_CONNECTION_LOST,
                ],
            ],
        ];

        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;

        // Should get regular Connection when retry is disabled
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertNotInstanceOf(RetryableConnection::class, $connection);
    }

    public function testRetryableConnectionWithoutConfig(): void
    {
        $config = [
            'path' => ':memory:',
            // No retry config
        ];

        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;

        // Should get regular Connection when no retry config
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertNotInstanceOf(RetryableConnection::class, $connection);
    }

    public function testRetryableConnectionCurrentAttempt(): void
    {
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 10, // Very short delay for testing
                'retryable_errors' => [
                    DbError::MYSQL_CANNOT_CONNECT,
                    DbError::MYSQL_CONNECTION_KILLED,
                    DbError::MYSQL_CONNECTION_LOST,
                ],
            ],
        ];

        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;

        $this->assertInstanceOf(RetryableConnection::class, $connection);

        // Initially should be 0
        if ($connection instanceof RetryableConnection) {
            $this->assertEquals(0, $connection->getCurrentAttempt());
        }

        // Test that normal operations work
        $db->rawQuery('CREATE TABLE retry_attempt_test (id INTEGER PRIMARY KEY)');
        $db->rawQuery('INSERT INTO retry_attempt_test (id) VALUES (1)');

        // Should be 1 after successful operation (attempt counter starts at 1)
        if ($connection instanceof RetryableConnection) {
            $this->assertEquals(1, $connection->getCurrentAttempt());
        }
    }

    public function testDbErrorConstants(): void
    {
        // Test MySQL constants
        $this->assertEquals(2006, DbError::MYSQL_CONNECTION_LOST);
        $this->assertEquals(2002, DbError::MYSQL_CANNOT_CONNECT);
        $this->assertEquals(2013, DbError::MYSQL_CONNECTION_KILLED);
        $this->assertEquals(1062, DbError::MYSQL_DUPLICATE_KEY);
        $this->assertEquals(1050, DbError::MYSQL_TABLE_EXISTS);

        // Test PostgreSQL constants
        $this->assertEquals('08006', DbError::POSTGRESQL_CONNECTION_FAILURE);
        $this->assertEquals('08003', DbError::POSTGRESQL_CONNECTION_DOES_NOT_EXIST);
        $this->assertEquals('23505', DbError::POSTGRESQL_UNIQUE_VIOLATION);
        $this->assertEquals('42P01', DbError::POSTGRESQL_UNDEFINED_TABLE);

        // Test SQLite constants
        $this->assertEquals(1, DbError::SQLITE_ERROR);
        $this->assertEquals(5, DbError::SQLITE_BUSY);
        $this->assertEquals(6, DbError::SQLITE_LOCKED);
        $this->assertEquals(19, DbError::SQLITE_CONSTRAINT);
        $this->assertEquals(100, DbError::SQLITE_ROW);
        $this->assertEquals(101, DbError::SQLITE_DONE);
    }

    public function testDbErrorHelperMethods(): void
    {
        // Test getMysqlRetryableErrors
        $mysqlErrors = DbError::getMysqlRetryableErrors();
        $this->assertIsArray($mysqlErrors);
        $this->assertContains(DbError::MYSQL_CONNECTION_LOST, $mysqlErrors);
        $this->assertContains(DbError::MYSQL_CANNOT_CONNECT, $mysqlErrors);
        $this->assertContains(DbError::MYSQL_CONNECTION_KILLED, $mysqlErrors);

        // Test getPostgresqlRetryableErrors
        $postgresqlErrors = DbError::getPostgresqlRetryableErrors();
        $this->assertIsArray($postgresqlErrors);
        $this->assertContains(DbError::POSTGRESQL_CONNECTION_FAILURE, $postgresqlErrors);
        $this->assertContains(DbError::POSTGRESQL_CONNECTION_DOES_NOT_EXIST, $postgresqlErrors);

        // Test getSqliteRetryableErrors
        $sqliteErrors = DbError::getSqliteRetryableErrors();
        $this->assertIsArray($sqliteErrors);
        $this->assertContains(DbError::SQLITE_BUSY, $sqliteErrors);
        $this->assertContains(DbError::SQLITE_LOCKED, $sqliteErrors);
        $this->assertContains(DbError::SQLITE_CONSTRAINT, $sqliteErrors);

        // Test getRetryableErrors with driver parameter
        $mysqlErrorsFromMethod = DbError::getRetryableErrors('mysql');
        $this->assertEquals($mysqlErrors, $mysqlErrorsFromMethod);

        $postgresqlErrorsFromMethod = DbError::getRetryableErrors('pgsql');
        $this->assertEquals($postgresqlErrors, $postgresqlErrorsFromMethod);

        $sqliteErrorsFromMethod = DbError::getRetryableErrors('sqlite');
        $this->assertEquals($sqliteErrors, $sqliteErrorsFromMethod);

        // Test unknown driver
        $unknownErrors = DbError::getRetryableErrors('unknown');
        $this->assertEquals([], $unknownErrors);
    }

    public function testDbErrorIsRetryable(): void
    {
        // Test MySQL errors
        $this->assertTrue(DbError::isRetryable(DbError::MYSQL_CONNECTION_LOST, 'mysql'));
        $this->assertTrue(DbError::isRetryable(DbError::MYSQL_CANNOT_CONNECT, 'mysql'));
        $this->assertFalse(DbError::isRetryable(DbError::MYSQL_DUPLICATE_KEY, 'mysql'));

        // Test PostgreSQL errors
        $this->assertTrue(DbError::isRetryable(DbError::POSTGRESQL_CONNECTION_FAILURE, 'pgsql'));
        $this->assertTrue(DbError::isRetryable(DbError::POSTGRESQL_CONNECTION_DOES_NOT_EXIST, 'pgsql'));
        $this->assertFalse(DbError::isRetryable(DbError::POSTGRESQL_UNIQUE_VIOLATION, 'pgsql'));

        // Test SQLite errors
        $this->assertTrue(DbError::isRetryable(DbError::SQLITE_BUSY, 'sqlite'));
        $this->assertTrue(DbError::isRetryable(DbError::SQLITE_LOCKED, 'sqlite'));
        $this->assertFalse(DbError::isRetryable(DbError::SQLITE_ROW, 'sqlite'));

        // Test unknown driver
        $this->assertFalse(DbError::isRetryable(2006, 'unknown'));
    }

    public function testDbErrorDescriptions(): void
    {
        // Test MySQL descriptions
        $mysqlDesc = DbError::getDescription(DbError::MYSQL_CONNECTION_LOST, 'mysql');
        $this->assertStringContainsString('gone away', $mysqlDesc);

        $mysqlDesc2 = DbError::getDescription(DbError::MYSQL_DUPLICATE_KEY, 'mysql');
        $this->assertStringContainsString('Duplicate', $mysqlDesc2);

        // Test PostgreSQL descriptions
        $postgresqlDesc = DbError::getDescription(DbError::POSTGRESQL_CONNECTION_FAILURE, 'pgsql');
        $this->assertStringContainsString('Connection failure', $postgresqlDesc);

        $postgresqlDesc2 = DbError::getDescription(DbError::POSTGRESQL_UNIQUE_VIOLATION, 'pgsql');
        $this->assertStringContainsString('Unique', $postgresqlDesc2);

        // Test SQLite descriptions
        $sqliteDesc = DbError::getDescription(DbError::SQLITE_BUSY, 'sqlite');
        $this->assertStringContainsString('locked', $sqliteDesc);

        $sqliteDesc2 = DbError::getDescription(DbError::SQLITE_CONSTRAINT, 'sqlite');
        $this->assertStringContainsString('constraint', $sqliteDesc2);

        // Test unknown error
        $unknownDesc = DbError::getDescription(99999, 'mysql');
        $this->assertEquals('Unknown error', $unknownDesc);
    }

    public function testRetryConfigValidation(): void
    {
        // Test valid configuration
        $validConfig = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'backoff_multiplier' => 2.0,
                'max_delay_ms' => 10000,
                'retryable_errors' => [2006, '08006'],
            ],
        ];

        $db = new PdoDb('sqlite', $validConfig);
        $this->assertInstanceOf(RetryableConnection::class, $db->connection);
    }

    public function testRetryConfigValidationInvalidEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.enabled must be a boolean');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => 'true', // Should be boolean
                'max_attempts' => 3,
                'delay_ms' => 1000,
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidMaxAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_attempts must be a positive integer');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 0, // Should be >= 1
                'delay_ms' => 1000,
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationMaxAttemptsTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_attempts cannot exceed 100');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 101, // Should be <= 100
                'delay_ms' => 1000,
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.delay_ms must be a non-negative integer');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => -100, // Should be >= 0
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationDelayTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.delay_ms cannot exceed 300000ms (5 minutes)');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 300001, // Should be <= 300000
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidBackoffMultiplier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.backoff_multiplier must be a number >= 1.0');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'backoff_multiplier' => 0.5, // Should be >= 1.0
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationBackoffMultiplierTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.backoff_multiplier cannot exceed 10.0');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'backoff_multiplier' => 11.0, // Should be <= 10.0
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidMaxDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms must be a non-negative integer');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'max_delay_ms' => -1000, // Should be >= 0
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationMaxDelayTooHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms cannot exceed 300000ms (5 minutes)');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'max_delay_ms' => 300001, // Should be <= 300000
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidRetryableErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.retryable_errors must be an array');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'retryable_errors' => 'not_an_array', // Should be array
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidRetryableErrorsContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.retryable_errors must contain only integers or strings');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'retryable_errors' => [2006, ['nested_array']], // Should contain only int/string
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationLogicalConstraint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms cannot be less than retry.delay_ms');

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 5000,
                'max_delay_ms' => 1000, // Should be >= delay_ms
            ],
        ];

        new PdoDb('sqlite', $config);
    }

    public function testRetryLogging(): void
    {
        // Create a Monolog logger with TestHandler
        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 2,
                'delay_ms' => 10, // Very short delay for testing
                'backoff_multiplier' => 2,
                'max_delay_ms' => 100,
                'retryable_errors' => [2006], // MySQL connection lost
            ],
        ];

        $db = new PdoDb('sqlite', $config, [], $logger);
        $connection = $db->connection;

        // Set the logger on the connection
        if ($connection instanceof RetryableConnection) {
            $reflection = new ReflectionClass($connection);
            $loggerProperty = $reflection->getProperty('logger');
            $loggerProperty->setAccessible(true);
            $loggerProperty->setValue($connection, $logger);
        }

        // Execute a simple query that should succeed
        $result = $db->rawQuery('SELECT 1 as test');
        $this->assertNotEmpty($result);

        // Check that some logs were created (at minimum we should have some logs)
        $records = $testHandler->getRecords();
        $this->assertGreaterThan(0, count($records), 'Should create some logs');

        // Check for specific log messages
        $logMessages = array_column($records, 'message');
        $this->assertContains('connection.retry.start', $logMessages, 'Should log retry start');
        $this->assertContains('connection.retry.attempt', $logMessages, 'Should log attempt');
        $this->assertContains('connection.retry.success', $logMessages, 'Should log success');
    }

    public function testRetryLoggingWithFailure(): void
    {
        // Create a Monolog logger with TestHandler
        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 2,
                'delay_ms' => 10,
                'backoff_multiplier' => 2,
                'max_delay_ms' => 100,
                'retryable_errors' => [9999], // Non-existent error code
            ],
        ];

        $db = new PdoDb('sqlite', $config, [], $logger);
        $connection = $db->connection;

        // Set the logger on the connection
        if ($connection instanceof RetryableConnection) {
            $reflection = new ReflectionClass($connection);
            $loggerProperty = $reflection->getProperty('logger');
            $loggerProperty->setAccessible(true);
            $loggerProperty->setValue($connection, $logger);
        }

        // Execute a query that should succeed (no retry needed)
        $result = $db->rawQuery('SELECT 1 as test');
        $this->assertNotEmpty($result);

        // Check that some logs were created
        $records = $testHandler->getRecords();
        $this->assertGreaterThan(0, count($records), 'Should create some logs');

        // Check for specific log messages
        $logMessages = array_column($records, 'message');
        $this->assertContains('connection.retry.start', $logMessages, 'Should log retry start');
        $this->assertContains('connection.retry.attempt', $logMessages, 'Should log attempt');
        $this->assertContains('connection.retry.success', $logMessages, 'Should log success');

        // Verify no failure logs were created (since query succeeded)
        $this->assertNotContains('connection.retry.attempt_failed', $logMessages, 'Should not log failures for successful queries');
    }

    public function testRetryLoggingWaitDetails(): void
    {
        // Create a Monolog logger with TestHandler
        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);

        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 100,
                'backoff_multiplier' => 2,
                'max_delay_ms' => 500,
                'retryable_errors' => [2006],
            ],
        ];

        $db = new PdoDb('sqlite', $config, [], $logger);
        $connection = $db->connection;

        // Set the logger on the connection
        if ($connection instanceof RetryableConnection) {
            $reflection = new ReflectionClass($connection);
            $loggerProperty = $reflection->getProperty('logger');
            $loggerProperty->setAccessible(true);
            $loggerProperty->setValue($connection, $logger);
        }

        // Execute a successful query
        $result = $db->rawQuery('SELECT 1 as test');
        $this->assertNotEmpty($result);

        // Check that some logs were created
        $records = $testHandler->getRecords();
        $this->assertGreaterThan(0, count($records), 'Should create some logs');

        // Check for specific log messages
        $logMessages = array_column($records, 'message');
        $this->assertContains('connection.retry.start', $logMessages, 'Should log retry start');
        $this->assertContains('connection.retry.attempt', $logMessages, 'Should log attempt');
        $this->assertContains('connection.retry.success', $logMessages, 'Should log success');
    }

    /* ---------------- Exception Hierarchy Tests ---------------- */

    /**
     * Test basic DatabaseException functionality.
     */
    public function testDatabaseException(): void
    {
        $pdoException = new PDOException('Test error', 12345);
        $exception = new QueryException(
            'Test error',
            12345,
            $pdoException,
            'mysql',
            'SELECT * FROM users',
            ['test' => 'context']
        );

        $this->assertEquals('Test error', $exception->getMessage());
        $this->assertEquals(12345, $exception->getCode());
        $this->assertEquals('mysql', $exception->getDriver());
        $this->assertEquals('SELECT * FROM users', $exception->getQuery());
        $this->assertEquals(['test' => 'context'], $exception->getContext());
        $this->assertEquals('query', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());

        // Test context manipulation
        $exception->addContext('new_key', 'new_value');
        $this->assertEquals('new_value', $exception->getContext()['new_key']);

        // Test array conversion
        $array = $exception->toArray();
        $this->assertArrayHasKey('exception', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('driver', $array);
        $this->assertArrayHasKey('query', $array);
        $this->assertArrayHasKey('context', $array);
        $this->assertArrayHasKey('category', $array);
        $this->assertArrayHasKey('retryable', $array);
    }

    /**
     * Test ConnectionException.
     */
    public function testConnectionException(): void
    {
        $exception = new ConnectionException(
            'Connection failed',
            2006,
            null,
            'mysql',
            'SELECT 1',
            []
        );

        $this->assertEquals('connection', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertStringContainsString('Query: SELECT 1', $exception->getDescription());
    }

    /**
     * Test ConstraintViolationException.
     */
    public function testConstraintViolationException(): void
    {
        $exception = new ConstraintViolationException(
            'Duplicate entry',
            1062,
            null,
            'mysql',
            'INSERT INTO users (email) VALUES (?)',
            [],
            'unique_email',
            'users',
            'email'
        );

        $this->assertEquals('constraint', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());
        $this->assertEquals('unique_email', $exception->getConstraintName());
        $this->assertEquals('users', $exception->getTableName());
        $this->assertEquals('email', $exception->getColumnName());
        $this->assertStringContainsString('Constraint: unique_email', $exception->getDescription());
        $this->assertStringContainsString('Table: users', $exception->getDescription());
        $this->assertStringContainsString('Column: email', $exception->getDescription());

        $array = $exception->toArray();
        $this->assertEquals('unique_email', $array['constraint_name']);
        $this->assertEquals('users', $array['table_name']);
        $this->assertEquals('email', $array['column_name']);
    }

    /**
     * Test TransactionException.
     */
    public function testTransactionException(): void
    {
        $exception = new TransactionException(
            'Deadlock detected',
            40001,
            null,
            'pgsql',
            'UPDATE users SET balance = balance - 100',
            []
        );

        $this->assertEquals('transaction', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test AuthenticationException.
     */
    public function testAuthenticationException(): void
    {
        $exception = new AuthenticationException(
            'Access denied',
            1045,
            null,
            'mysql',
            null, // Don't include query for security
            []
        );

        $this->assertEquals('authentication', $exception->getCategory());
        $this->assertFalse($exception->isRetryable());
        $this->assertEquals('Access denied', $exception->getDescription());
    }

    /**
     * Test TimeoutException.
     */
    public function testTimeoutException(): void
    {
        $exception = new TimeoutException(
            'Query timeout',
            57014,
            null,
            'pgsql',
            'SELECT * FROM large_table',
            [],
            30.0
        );

        $this->assertEquals('timeout', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertEquals(30.0, $exception->getTimeoutSeconds());
        $this->assertStringContainsString('Timeout: 30s', $exception->getDescription());

        $array = $exception->toArray();
        $this->assertEquals(30.0, $array['timeout_seconds']);
    }

    /**
     * Test ResourceException.
     */
    public function testResourceException(): void
    {
        $exception = new ResourceException(
            'Too many connections',
            1040,
            null,
            'mysql',
            'SELECT 1',
            [],
            'connections'
        );

        $this->assertEquals('resource', $exception->getCategory());
        $this->assertTrue($exception->isRetryable());
        $this->assertEquals('connections', $exception->getResourceType());
        $this->assertStringContainsString('Resource: connections', $exception->getDescription());

        $array = $exception->toArray();
        $this->assertEquals('connections', $array['resource_type']);
    }

    /**
     * Test ExceptionFactory with connection errors.
     */
    public function testExceptionFactoryConnectionErrors(): void
    {
        // MySQL connection errors
        $mysqlConnectionError = new PDOException('MySQL server has gone away', 2006);
        $exception = ExceptionFactory::createFromPdoException($mysqlConnectionError, 'mysql');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // PostgreSQL connection errors
        $pgsqlConnectionError = new PDOException('Connection refused', 0);
        $pgsqlConnectionError->errorInfo = ['08006', '08006', 'Connection refused'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlConnectionError, 'pgsql');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // SQLite connection errors
        $sqliteConnectionError = new PDOException('Unable to open database file', 14);
        $exception = ExceptionFactory::createFromPdoException($sqliteConnectionError, 'sqlite');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with constraint violations.
     */
    public function testExceptionFactoryConstraintViolations(): void
    {
        // MySQL duplicate key
        $mysqlDuplicate = new PDOException('Duplicate entry \'test@example.com\' for key \'unique_email\'', 1062);
        $exception = ExceptionFactory::createFromPdoException($mysqlDuplicate, 'mysql');
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // PostgreSQL unique violation
        $pgsqlUnique = new PDOException('duplicate key value violates unique constraint "users_email_key"', 0);
        $pgsqlUnique->errorInfo = ['23505', '23505', 'duplicate key value violates unique constraint "users_email_key"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlUnique, 'pgsql');
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // SQLite constraint
        $sqliteConstraint = new PDOException('UNIQUE constraint failed: users.email', 19);
        $exception = ExceptionFactory::createFromPdoException($sqliteConstraint, 'sqlite');
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertFalse($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with transaction errors.
     */
    public function testExceptionFactoryTransactionErrors(): void
    {
        // PostgreSQL deadlock
        $pgsqlDeadlock = new PDOException('deadlock detected', 0);
        $pgsqlDeadlock->errorInfo = ['40001', '40001', 'deadlock detected'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlDeadlock, 'pgsql');
        $this->assertInstanceOf(TransactionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // SQLite busy
        $sqliteBusy = new PDOException('database is locked', 5);
        $exception = ExceptionFactory::createFromPdoException($sqliteBusy, 'sqlite');
        $this->assertInstanceOf(TransactionException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with authentication errors.
     */
    public function testExceptionFactoryAuthenticationErrors(): void
    {
        // MySQL access denied
        $mysqlAuth = new PDOException('Access denied for user \'testuser\'@\'localhost\'', 1045);
        $exception = ExceptionFactory::createFromPdoException($mysqlAuth, 'mysql');
        $this->assertInstanceOf(AuthenticationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // PostgreSQL authentication failed
        $pgsqlAuth = new PDOException('password authentication failed for user "testuser"', 0);
        $pgsqlAuth->errorInfo = ['28P01', '28P01', 'password authentication failed for user "testuser"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlAuth, 'pgsql');
        $this->assertInstanceOf(AuthenticationException::class, $exception);
        $this->assertFalse($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with timeout errors.
     */
    public function testExceptionFactoryTimeoutErrors(): void
    {
        // PostgreSQL timeout
        $pgsqlTimeout = new PDOException('canceling statement due to statement timeout', 0);
        $pgsqlTimeout->errorInfo = ['57014', '57014', 'canceling statement due to statement timeout'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlTimeout, 'pgsql');
        $this->assertInstanceOf(TimeoutException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // MySQL timeout (this is actually a connection error)
        $mysqlTimeout = new PDOException('Lost connection to MySQL server during query', 2006);
        $exception = ExceptionFactory::createFromPdoException($mysqlTimeout, 'mysql');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with resource errors.
     */
    public function testExceptionFactoryResourceErrors(): void
    {
        // MySQL too many connections
        $mysqlResource = new PDOException('Too many connections', 1040);
        $exception = ExceptionFactory::createFromPdoException($mysqlResource, 'mysql');
        $this->assertInstanceOf(ResourceException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // PostgreSQL too many connections
        $pgsqlResource = new PDOException('too many connections for role "testuser"', 0);
        $pgsqlResource->errorInfo = ['53300', '53300', 'too many connections for role "testuser"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlResource, 'pgsql');
        $this->assertInstanceOf(ResourceException::class, $exception);
        $this->assertTrue($exception->isRetryable());
    }

    /**
     * Test ExceptionFactory with query errors (default case).
     */
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

    /**
     * Test ExceptionFactory with context and query.
     */
    public function testExceptionFactoryWithContext(): void
    {
        $pdoException = new PDOException('Test error', 12345);
        $exception = ExceptionFactory::createFromPdoException(
            $pdoException,
            'mysql',
            'SELECT * FROM users WHERE id = ?',
            ['operation' => 'test', 'user_id' => 123]
        );

        $this->assertEquals('mysql', $exception->getDriver());
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $exception->getQuery());
        $this->assertEquals(['operation' => 'test', 'user_id' => 123], $exception->getContext());
    }

    /**
     * Test constraint violation parsing.
     */
    public function testConstraintViolationParsing(): void
    {
        // Test MySQL constraint parsing
        $mysqlError = new PDOException('Duplicate entry \'test@example.com\' for key \'unique_email\' in table \'users\'', 1062);
        $exception = ExceptionFactory::createFromPdoException($mysqlError, 'mysql');

        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertEquals('unique_email', $exception->getConstraintName());
        $this->assertEquals('users', $exception->getTableName());

        // Test PostgreSQL constraint parsing
        $pgsqlError = new PDOException('duplicate key value violates unique constraint "users_email_key"', 0);
        $pgsqlError->errorInfo = ['23505', '23505', 'duplicate key value violates unique constraint "users_email_key"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlError, 'pgsql');

        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertEquals('users_email_key', $exception->getConstraintName());
    }

    /**
     * Test setTimeout and getTimeout methods.
     */
    public function testTimeoutMethods(): void
    {
        // Test setting timeout
        $db = self::$db;
        $result = $db->setTimeout(30);
        $this->assertSame($db, $result); // Should return self for chaining

        // Test getting timeout
        $timeout = $db->getTimeout();
        $this->assertIsInt($timeout);
        $this->assertGreaterThanOrEqual(0, $timeout);

        // For SQLite, timeout might be 0 (not supported)
        // For MySQL/PostgreSQL, it should be the set value
        if ($timeout > 0) {
            // Test setting different timeout values
            $db->setTimeout(60);
            $this->assertEquals(60, $db->getTimeout());

            $db->setTimeout(0);
            $this->assertEquals(0, $db->getTimeout());
        } else {
            // SQLite doesn't support timeout, so we just verify it doesn't throw
            $this->assertEquals(0, $timeout);
        }
    }

    /**
     * Test setTimeout with invalid values.
     */
    public function testSetTimeoutWithInvalidValues(): void
    {
        $db = self::$db;

        // Test negative timeout (should be handled gracefully)
        try {
            $db->setTimeout(-1);
            // Some databases might accept negative values, so we just check it doesn't throw
            $this->assertTrue(true);
        } catch (Exception $e) {
            // If it throws an exception, that's also acceptable behavior
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    /**
     * Test timeout methods with connection pooling.
     */
    public function testTimeoutWithConnectionPooling(): void
    {
        $db = new PdoDb();

        // Add multiple connections
        $db->addConnection('conn1', [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);

        $db->addConnection('conn2', [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);

        // Test timeout on first connection
        $db->connection('conn1');
        $db->setTimeout(30);
        $timeout1 = $db->getTimeout();
        $this->assertIsInt($timeout1);
        $this->assertGreaterThanOrEqual(0, $timeout1);

        // Test timeout on second connection
        $db->connection('conn2');
        $db->setTimeout(60);
        $timeout2 = $db->getTimeout();
        $this->assertIsInt($timeout2);
        $this->assertGreaterThanOrEqual(0, $timeout2);

        // Verify first connection timeout is unchanged
        $db->connection('conn1');
        $this->assertEquals($timeout1, $db->getTimeout());
    }

    /**
     * Test batch processing method.
     */
    public function testBatchProcessing(): void
    {
        $db = self::$db;

        // Insert test data
        $testData = [];
        for ($i = 1; $i <= 25; $i++) {
            $testData[] = [
                'name' => "User {$i}",
                'value' => $i * 10,
                'meta' => json_encode(['id' => $i, 'active' => $i % 2 === 0]),
            ];
        }
        $db->find()->table('test_coverage')->insertMulti($testData);

        // Test batch processing with batch size 10
        $batches = [];
        $totalRecords = 0;

        foreach ($db->find()->from('test_coverage')->orderBy('id')->batch(10) as $batch) {
            $batches[] = $batch;
            $totalRecords += count($batch);
        }

        $this->assertCount(3, $batches); // 10 + 10 + 5 records
        $this->assertEquals(25, $totalRecords);
        $this->assertCount(10, $batches[0]); // First batch
        $this->assertCount(10, $batches[1]); // Second batch
        $this->assertCount(5, $batches[2]);  // Last batch

        // Test batch processing with batch size 7
        $batches = [];
        foreach ($db->find()->from('test_coverage')->orderBy('id')->batch(7) as $batch) {
            $batches[] = $batch;
        }

        $this->assertCount(4, $batches); // 7 + 7 + 7 + 4 records
        $this->assertCount(7, $batches[0]);
        $this->assertCount(7, $batches[1]);
        $this->assertCount(7, $batches[2]);
        $this->assertCount(4, $batches[3]);
    }

    /**
     * Test each processing method.
     */
    public function testEachProcessing(): void
    {
        $db = self::$db;

        // Clear and insert test data
        $db->find()->table('test_coverage')->delete();
        $testData = [];
        for ($i = 1; $i <= 15; $i++) {
            $testData[] = [
                'name' => "Item {$i}",
                'value' => $i * 5,
                'meta' => json_encode(['category' => $i % 3]),
            ];
        }
        $db->find()->table('test_coverage')->insertMulti($testData);

        // Test each processing
        $records = [];
        $count = 0;

        foreach ($db->find()->from('test_coverage')->orderBy('id')->each(5) as $record) {
            $records[] = $record;
            $count++;
        }

        $this->assertEquals(15, $count);
        $this->assertCount(15, $records);

        // Verify order is maintained
        $this->assertEquals('Item 1', $records[0]['name']);
        $this->assertEquals('Item 15', $records[14]['name']);
        $this->assertEquals(5, $records[0]['value']);
        $this->assertEquals(75, $records[14]['value']);
    }

    /**
     * Test cursor processing method.
     */
    public function testCursorProcessing(): void
    {
        $db = self::$db;

        // Clear and insert test data
        $db->find()->table('test_coverage')->delete();
        $testData = [];
        for ($i = 1; $i <= 20; $i++) {
            $testData[] = [
                'name' => "Record {$i}",
                'value' => $i * 3,
                'meta' => json_encode(['type' => 'test']),
            ];
        }
        $db->find()->table('test_coverage')->insertMulti($testData);

        // Test cursor processing
        $records = [];
        $count = 0;

        foreach ($db->find()->from('test_coverage')->orderBy('id')->cursor() as $record) {
            $records[] = $record;
            $count++;
        }

        $this->assertEquals(20, $count);
        $this->assertCount(20, $records);

        // Verify data integrity
        $this->assertEquals('Record 1', $records[0]['name']);
        $this->assertEquals('Record 20', $records[19]['name']);
        $this->assertEquals(3, $records[0]['value']);
        $this->assertEquals(60, $records[19]['value']);
    }

    /**
     * Test batch processing with WHERE conditions.
     */
    public function testBatchWithConditions(): void
    {
        $db = self::$db;

        // Clear and insert test data
        $db->find()->table('test_coverage')->delete();
        $testData = [];
        for ($i = 1; $i <= 30; $i++) {
            $testData[] = [
                'name' => "User {$i}",
                'value' => $i,
                'meta' => json_encode(['active' => $i % 2 === 0]),
            ];
        }
        $db->find()->table('test_coverage')->insertMulti($testData);

        // Test batch with WHERE condition
        $batches = [];
        foreach ($db->find()
            ->from('test_coverage')
            ->where('value', 20, '<=')
            ->orderBy('id')
            ->batch(8) as $batch) {
            $batches[] = $batch;
        }

        $this->assertCount(3, $batches); // 8 + 8 + 4 records (values 1-20)
        $this->assertCount(8, $batches[0]);
        $this->assertCount(8, $batches[1]);
        $this->assertCount(4, $batches[2]);

        // Verify all records have value <= 20
        foreach ($batches as $batch) {
            foreach ($batch as $record) {
                $this->assertLessThanOrEqual(20, $record['value']);
            }
        }
    }

    /**
     * Test error handling for invalid batch sizes.
     */
    public function testBatchErrorHandling(): void
    {
        $db = self::$db;

        // Test zero batch size - need to iterate to trigger the exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be greater than 0');
        foreach ($db->find()->from('test_coverage')->batch(0) as $batch) {
            // This should not execute due to exception
        }

        // Test negative batch size - need to iterate to trigger the exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be greater than 0');
        foreach ($db->find()->from('test_coverage')->batch(-5) as $batch) {
            // This should not execute due to exception
        }
    }

    /**
     * Test each error handling for invalid batch sizes.
     */
    public function testEachErrorHandling(): void
    {
        $db = self::$db;

        // Test zero batch size - need to iterate to trigger the exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be greater than 0');
        foreach ($db->find()->from('test_coverage')->each(0) as $record) {
            // This should not execute due to exception
        }

        // Test negative batch size - need to iterate to trigger the exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be greater than 0');
        foreach ($db->find()->from('test_coverage')->each(-10) as $record) {
            // This should not execute due to exception
        }
    }

    /**
     * Test empty result sets.
     */
    public function testEmptyResultSets(): void
    {
        $db = self::$db;

        // Clear table
        $db->find()->table('test_coverage')->delete();

        // Test batch with empty results
        $batches = [];
        foreach ($db->find()->from('test_coverage')->batch(10) as $batch) {
            $batches[] = $batch;
        }
        $this->assertEmpty($batches);

        // Test each with empty results
        $records = [];
        foreach ($db->find()->from('test_coverage')->each(10) as $record) {
            $records[] = $record;
        }
        $this->assertEmpty($records);

        // Test cursor with empty results
        $records = [];
        foreach ($db->find()->from('test_coverage')->cursor() as $record) {
            $records[] = $record;
        }
        $this->assertEmpty($records);
    }

    /**
     * Test JSON export helper.
     */
    public function testJsonExport(): void
    {
        $db = self::$db;

        // Insert test data
        $db->find()->table('test_coverage')->insert([
            'name' => 'Alice',
            'value' => 100,
        ]);

        $data = $db->find()->from('test_coverage')->get();

        // Export to JSON
        $json = Db::toJson($data);

        $this->assertIsString($json);
        $this->assertJson($json);

        // Verify data
        $decoded = json_decode($json, true);
        $this->assertCount(1, $decoded);
        $this->assertEquals('Alice', $decoded[0]['name']);
        $this->assertEquals(100, $decoded[0]['value']);
    }

    /**
     * Test CSV export helper.
     */
    public function testCsvExport(): void
    {
        $db = self::$db;

        // Insert test data
        $db->find()->table('test_coverage')->insertMulti([
            ['name' => 'Alice', 'value' => 100],
            ['name' => 'Bob', 'value' => 200],
        ]);

        $data = $db->find()->from('test_coverage')->get();

        // Export to CSV
        $csv = Db::toCsv($data);

        $this->assertIsString($csv);
        $this->assertStringContainsString('name', $csv);
        $this->assertStringContainsString('value', $csv);
        $this->assertStringContainsString('Alice', $csv);
        $this->assertStringContainsString('Bob', $csv);
    }

    /**
     * Test XML export helper.
     */
    public function testXmlExport(): void
    {
        $db = self::$db;

        // Insert test data
        $db->find()->table('test_coverage')->insert([
            'name' => 'Alice',
            'value' => 100,
        ]);

        $data = $db->find()->from('test_coverage')->get();

        // Export to XML
        $xml = Db::toXml($data);

        $this->assertIsString($xml);
        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('<data>', $xml);
        $this->assertStringContainsString('Alice', $xml);
    }

    /**
     * Test export with custom options.
     */
    public function testExportWithCustomOptions(): void
    {
        $db = self::$db;

        $data = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        // Test JSON with custom flags
        $json = Db::toJson($data, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertJson($json);

        // Test CSV with custom delimiter
        $csv = Db::toCsv($data, ';');
        $this->assertStringContainsString(';', $csv);

        // Test XML with custom elements
        $xml = Db::toXml($data, 'users', 'user');
        $this->assertStringContainsString('<users>', $xml);
        $this->assertStringContainsString('<user>', $xml);
    }

    /**
     * Test export of empty data.
     */
    public function testExportEmptyData(): void
    {
        $emptyData = [];

        // Test empty JSON
        $json = Db::toJson($emptyData);
        $this->assertEquals('[]', $json);

        // Test empty CSV
        $csv = Db::toCsv($emptyData);
        $this->assertEquals('', $csv);

        // Test empty XML
        $xml = Db::toXml($emptyData);
        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('<data', $xml);
    }

    /**
     * Test JSON export with nested arrays.
     */
    public function testJsonExportWithNestedArrays(): void
    {
        $data = [
            ['id' => 1, 'tags' => ['php', 'mysql']],
            ['id' => 2, 'tags' => ['python', 'sql']],
        ];

        $json = Db::toJson($data);
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded[0]['tags']);
        $this->assertCount(2, $decoded[0]['tags']);
    }

    /**
     * Test paginate method.
     */
    public function testPaginate(): void
    {
        // Insert test data
        $testData = [];
        for ($i = 1; $i <= 50; $i++) {
            $testData[] = ['name' => "Item $i", 'value' => $i];
        }
        self::$db->find()->table('test_coverage')->insertMulti($testData);

        // Test first page
        $result = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->paginate(10, 1);

        $this->assertInstanceOf(PaginationResult::class, $result);
        $this->assertCount(10, $result->items());
        $this->assertEquals(50, $result->total());
        $this->assertEquals(1, $result->currentPage());
        $this->assertEquals(10, $result->perPage());
        $this->assertEquals(5, $result->lastPage());
        $this->assertEquals(1, $result->from());
        $this->assertEquals(10, $result->to());
        $this->assertTrue($result->hasMorePages());
        $this->assertTrue($result->onFirstPage());
        $this->assertFalse($result->onLastPage());

        // Test second page
        $result2 = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->paginate(10, 2);

        $this->assertEquals(2, $result2->currentPage());
        $this->assertEquals(11, $result2->from());
        $this->assertEquals(20, $result2->to());
        $this->assertFalse($result2->onFirstPage());

        // Test last page
        $result3 = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->paginate(10, 5);

        $this->assertEquals(5, $result3->currentPage());
        $this->assertEquals(41, $result3->from());
        $this->assertEquals(50, $result3->to());
        $this->assertFalse($result3->hasMorePages());
        $this->assertTrue($result3->onLastPage());

        // Test JSON serialization
        $json = json_encode($result);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('meta', $decoded);
        $this->assertArrayHasKey('links', $decoded);
        $this->assertEquals(10, count($decoded['data']));
        $this->assertEquals(50, $decoded['meta']['total']);
    }

    /**
     * Test simplePaginate method.
     */
    public function testSimplePaginate(): void
    {
        // Insert test data
        $testData = [];
        for ($i = 1; $i <= 30; $i++) {
            $testData[] = ['name' => "Item $i", 'value' => $i];
        }
        self::$db->find()->table('test_coverage')->insertMulti($testData);

        // Test first page
        $result = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->simplePaginate(10, 1);

        $this->assertInstanceOf(SimplePaginationResult::class, $result);
        $this->assertCount(10, $result->items());
        $this->assertEquals(1, $result->currentPage());
        $this->assertEquals(10, $result->perPage());
        $this->assertTrue($result->hasMorePages());
        $this->assertTrue($result->onFirstPage());

        // Test last page
        $result2 = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->simplePaginate(10, 3);

        $this->assertEquals(3, $result2->currentPage());
        $this->assertFalse($result2->hasMorePages());
        $this->assertFalse($result2->onFirstPage());

        // Test JSON serialization
        $json = json_encode($result);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('meta', $decoded);
        $this->assertArrayHasKey('links', $decoded);
        $this->assertEquals(10, count($decoded['data']));
        $this->assertTrue($decoded['meta']['has_more']);
        $this->assertArrayNotHasKey('total', $decoded['meta']); // No total in simple pagination
    }

    /**
     * Test cursorPaginate method.
     */
    public function testCursorPaginate(): void
    {
        // Insert test data
        $testData = [];
        for ($i = 1; $i <= 25; $i++) {
            $testData[] = ['name' => "Item $i", 'value' => $i];
        }
        self::$db->find()->table('test_coverage')->insertMulti($testData);

        // Test first page
        $result = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->cursorPaginate(10);

        $this->assertInstanceOf(CursorPaginationResult::class, $result);
        $this->assertCount(10, $result->items());
        $this->assertEquals(10, $result->perPage());
        $this->assertTrue($result->hasMorePages());
        $this->assertNotNull($result->nextCursor());

        // Test next page with cursor
        $cursor = $result->nextCursor();
        $this->assertNotNull($cursor);

        $result2 = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->cursorPaginate(10, $cursor);

        $this->assertCount(10, $result2->items());
        $this->assertTrue($result2->hasMorePages());

        // Test JSON serialization
        $json = json_encode($result);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('meta', $decoded);
        $this->assertArrayHasKey('cursor', $decoded);
        $this->assertArrayHasKey('links', $decoded);
        $this->assertTrue($decoded['meta']['has_more']);
        $this->assertNotNull($decoded['cursor']['next']);
    }

    /**
     * Test pagination with URL options.
     */
    public function testPaginationWithUrlOptions(): void
    {
        // Insert test data
        $testData = [];
        for ($i = 1; $i <= 20; $i++) {
            $testData[] = ['name' => "Item $i", 'value' => $i];
        }
        self::$db->find()->table('test_coverage')->insertMulti($testData);

        $result = self::$db->find()
            ->from('test_coverage')
            ->orderBy('id')
            ->paginate(5, 2, [
                'path' => '/api/items',
                'query' => ['filter' => 'active'],
            ]);

        $this->assertStringContainsString('/api/items?', $result->url(1));
        $this->assertStringContainsString('filter=active', $result->url(1));
        $this->assertStringContainsString('page=1', $result->url(1));
        $this->assertStringContainsString('page=3', $result->nextPageUrl() ?? ''); // Next page from page 2 is page 3
    }

    /**
     * Test Cursor encode and decode.
     */
    public function testCursorEncodeAndDecode(): void
    {
        $cursor = new Cursor(['id' => 42, 'created_at' => '2025-10-28']);
        $encoded = $cursor->encode();
        $this->assertIsString($encoded);

        $decoded = Cursor::decode($encoded);
        $this->assertInstanceOf(Cursor::class, $decoded);
        $this->assertEquals(['id' => 42, 'created_at' => '2025-10-28'], $decoded->parameters());
        $this->assertTrue($decoded->pointsToNextItems());
        $this->assertFalse($decoded->pointsToPreviousItems());
    }

    /**
     * Test Cursor from item.
     */
    public function testCursorFromItem(): void
    {
        $item = ['id' => 123, 'name' => 'Test', 'created_at' => '2025-10-28'];
        $cursor = Cursor::fromItem($item, ['id', 'created_at']);

        $this->assertEquals(['id' => 123, 'created_at' => '2025-10-28'], $cursor->parameters());
    }

    /**
     * Test orderBy with array and comma-separated values.
     */
    public function testOrderByArrayAndCommaSeparated(): void
    {
        // Insert test data
        self::$db->find()->table('test_coverage')->insertMulti([
            ['name' => 'Alice', 'value' => 30],
            ['name' => 'Bob', 'value' => 10],
            ['name' => 'Charlie', 'value' => 20],
            ['name' => 'David', 'value' => 20],
        ]);

        // Test 1: Array with numeric keys (default direction)
        $result1 = self::$db->find()
            ->from('test_coverage')
            ->select('name')
            ->orderBy(['value', 'name'], 'ASC')
            ->getColumn();
        $this->assertEquals(['Bob', 'Charlie', 'David', 'Alice'], $result1);

        // Test 2: Array with associative keys (explicit directions)
        $result2 = self::$db->find()
            ->from('test_coverage')
            ->select('name')
            ->orderBy(['value' => 'DESC', 'name' => 'ASC'])
            ->getColumn();
        $this->assertEquals(['Alice', 'Charlie', 'David', 'Bob'], $result2);

        // Test 3: Comma-separated string with directions
        $result3 = self::$db->find()
            ->from('test_coverage')
            ->select('name')
            ->orderBy('value ASC, name DESC')
            ->getColumn();
        $this->assertEquals(['Bob', 'David', 'Charlie', 'Alice'], $result3);

        // Test 4: Comma-separated string without directions (uses default)
        $result4 = self::$db->find()
            ->from('test_coverage')
            ->select('name')
            ->orderBy('value, name', 'DESC')
            ->getColumn();
        $this->assertEquals(['Alice', 'David', 'Charlie', 'Bob'], $result4);

        // Test 5: Mix of formats (array then single)
        $result5 = self::$db->find()
            ->from('test_coverage')
            ->select(['name'])
            ->orderBy(['value' => 'ASC'])
            ->orderBy('name', 'DESC')
            ->getColumn();
        $this->assertEquals(['Bob', 'David', 'Charlie', 'Alice'], $result5);

        // Test 6: Comma-separated with mixed formats (name defaults to ASC)
        $result6 = self::$db->find()
            ->from('test_coverage')
            ->select(['name'])
            ->orderBy('value DESC, name')
            ->getColumn();
        $this->assertEquals(['Alice', 'Charlie', 'David', 'Bob'], $result6);

        // Test 7: Multiple chained orderBy calls
        $result7 = self::$db->find()
            ->from('test_coverage')
            ->select(['name'])
            ->orderBy('value', 'DESC')
            ->orderBy('name', 'ASC')
            ->getColumn();
        $this->assertEquals(['Alice', 'Charlie', 'David', 'Bob'], $result7);
    }

    /**
     * Test query result caching.
     */
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

    public function testIdentifierQuotingAndUnsafeDetection(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INT)');

        // Quoting of qualified identifier
        $db->find()->from('users')->select(['users.name'])->get();
        $this->assertStringContainsString('"users"."name"', $db->lastQuery);

        // Pass-through expression
        $db->find()->from('users')->select(['SUM(age)'])->get();
        $this->assertStringContainsString('SUM(age)', $db->lastQuery);

        // Unsafe token should throw
        $this->expectException(InvalidArgumentException::class);
        $db->find()->from('users')->select(['name; DROP TABLE'])->get();
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

    public function testFilterValueAndWindowFunctionResolution(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE s (id INTEGER PRIMARY KEY, grp INT, val INT)');
        $db->find()->table('s')->insertMulti([
            ['grp' => 1, 'val' => 10],
            ['grp' => 1, 'val' => 20],
            ['grp' => 2, 'val' => 30],
        ]);

        // FilterValue COUNT(*) where val > 10 -> in SQLite fallback to CASE WHEN form
        $fv = new FilterValue('COUNT(*)');
        $fv->filter('val', 10, '>');
        $db->find()->from('s')->select(['cnt' => $fv])->get();
        $this->assertTrue(
            str_contains($db->lastQuery, ' FILTER (WHERE')
            || str_contains($db->lastQuery, 'COUNT(CASE WHEN')
        );

        // Window function example: ROW_NUMBER() OVER (PARTITION BY grp ORDER BY val)
        $wf = new WindowFunctionValue('ROW_NUMBER');
        $db->find()->from('s')->select(['rn' => $wf])->get();
        $this->assertStringContainsString('ROW_NUMBER', $db->lastQuery);
    }

    /**
     * Ensure wildcard selections work uniformly across input forms.
     * - select(['*']) behaves like select('*')
     * - select(['u.*', 'o.*']) behaves like select('u.*, o.*').
     */
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

    /**
     * Test caching without cache manager (should work normally).
     */
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

    /* ---------------- Read/Write Splitting Tests ---------------- */

    public function testEnableReadWriteSplitting(): void
    {
        $db = new PdoDb();

        // Enable read/write splitting first
        $db->enableReadWriteSplitting();

        // Then add connections
        $db->addConnection('write', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->addConnection('read', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'read']);

        $router = $db->getConnectionRouter();
        $this->assertNotNull($router);
        $this->assertCount(1, $router->getWriteConnections());
        $this->assertCount(1, $router->getReadConnections());
    }

    public function testDisableReadWriteSplitting(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();
        $this->assertNotNull($db->getConnectionRouter());

        $db->disableReadWriteSplitting();
        $this->assertNull($db->getConnectionRouter());
    }

    public function testEnableStickyWritesWithoutRouter(): void
    {
        $db = new PdoDb();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Read/write splitting must be enabled first');
        $db->enableStickyWrites(60);
    }

    public function testEnableStickyWrites(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();
        $db->enableStickyWrites(60);

        $router = $db->getConnectionRouter();
        $this->assertTrue($router->isStickyWritesEnabled());
    }

    public function testDisableStickyWrites(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();
        $db->enableStickyWrites(60);

        $db->disableStickyWrites();

        $router = $db->getConnectionRouter();
        $this->assertFalse($router->isStickyWritesEnabled());
    }

    public function testConnectionRouterAddConnections(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();

        $db->addConnection('master', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'write']);
        $db->addConnection('slave1', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'read']);
        $db->addConnection('slave2', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'read']);

        $router = $db->getConnectionRouter();
        $this->assertCount(1, $router->getWriteConnections());
        $this->assertCount(2, $router->getReadConnections());
    }

    public function testConnectionRouterDefaultTypeIsWrite(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();

        // Without 'type' parameter, should default to write
        $db->addConnection('default', ['driver' => 'sqlite', 'path' => ':memory:']);

        $router = $db->getConnectionRouter();
        $this->assertCount(1, $router->getWriteConnections());
        $this->assertCount(0, $router->getReadConnections());
    }

    public function testForceWriteMode(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();

        $db->addConnection('write', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->addConnection('read', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'read']);

        $router = $db->getConnectionRouter();
        $router->enableForceWrite();

        $this->assertTrue($router->isForceWriteMode());

        $router->disableForceWrite();
        $this->assertFalse($router->isForceWriteMode());
    }

    public function testConnectionRouterHealthCheck(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();
        $db->addConnection('test', ['driver' => 'sqlite', 'path' => ':memory:', 'type' => 'write']);
        $db->connection('test');

        $router = $db->getConnectionRouter();
        $this->assertNotNull($router);
        $this->assertTrue($router->healthCheck($db->connection));
    }

    public function testTransactionStateUpdateInRouter(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();
        $db->addConnection('write', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->connection('write');

        $router = $db->getConnectionRouter();
        $this->assertFalse($router->isInTransaction());

        $db->startTransaction();
        $this->assertTrue($router->isInTransaction());

        $db->commit();
        $this->assertFalse($router->isInTransaction());
    }

    public function testTransactionStateOnRollback(): void
    {
        $db = new PdoDb();
        $db->enableReadWriteSplitting();
        $db->addConnection('write', ['driver' => 'sqlite', 'path' => ':memory:']);
        $db->connection('write');

        $router = $db->getConnectionRouter();

        $db->startTransaction();
        $this->assertTrue($router->isInTransaction());

        $db->rollback();
        $this->assertFalse($router->isInTransaction());
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

    public function testConnectionTypeEnum(): void
    {
        $readType = ConnectionType::READ;
        $writeType = ConnectionType::WRITE;

        $this->assertEquals('read', $readType->value);
        $this->assertEquals('write', $writeType->value);
    }

    /* ---------------- Window Functions Tests ---------------- */

    public function testWindowFunctionRowNumber(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_window (id INTEGER PRIMARY KEY, category TEXT, value INTEGER)');
        $db->find()->table('test_window')->insertMulti([
            ['id' => 1, 'category' => 'A', 'value' => 100],
            ['id' => 2, 'category' => 'A', 'value' => 200],
            ['id' => 3, 'category' => 'B', 'value' => 150],
            ['id' => 4, 'category' => 'B', 'value' => 250],
        ]);

        $results = $db->find()
            ->from('test_window')
            ->select([
                'id',
                'category',
                'value',
                'row_num' => Db::rowNumber()->partitionBy('category')->orderBy('value', 'ASC'),
            ])
            ->orderBy('category')
            ->orderBy('value')
            ->get();

        $this->assertCount(4, $results);
        $this->assertEquals(1, $results[0]['row_num']);
        $this->assertEquals(2, $results[1]['row_num']);
        $this->assertEquals(1, $results[2]['row_num']);
        $this->assertEquals(2, $results[3]['row_num']);
    }

    public function testWindowFunctionRank(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_rank (id INTEGER PRIMARY KEY, score INTEGER)');
        $db->find()->table('test_rank')->insertMulti([
            ['id' => 1, 'score' => 100],
            ['id' => 2, 'score' => 100],
            ['id' => 3, 'score' => 90],
            ['id' => 4, 'score' => 80],
        ]);

        $results = $db->find()
            ->from('test_rank')
            ->select([
                'id',
                'score',
                'rank' => Db::rank()->orderBy('score', 'DESC'),
            ])
            ->orderBy('score', 'DESC')
            ->get();

        $this->assertCount(4, $results);
        $this->assertEquals(1, $results[0]['rank']);
        $this->assertEquals(1, $results[1]['rank']); // Same rank for tie
        $this->assertEquals(3, $results[2]['rank']); // Gap after tie
        $this->assertEquals(4, $results[3]['rank']);
    }

    public function testWindowFunctionDenseRank(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_dense_rank (id INTEGER PRIMARY KEY, score INTEGER)');
        $db->find()->table('test_dense_rank')->insertMulti([
            ['id' => 1, 'score' => 100],
            ['id' => 2, 'score' => 100],
            ['id' => 3, 'score' => 90],
            ['id' => 4, 'score' => 80],
        ]);

        $results = $db->find()
            ->from('test_dense_rank')
            ->select([
                'id',
                'score',
                'dense_rank' => Db::denseRank()->orderBy('score', 'DESC'),
            ])
            ->orderBy('score', 'DESC')
            ->get();

        $this->assertCount(4, $results);
        $this->assertEquals(1, $results[0]['dense_rank']);
        $this->assertEquals(1, $results[1]['dense_rank']); // Same rank for tie
        $this->assertEquals(2, $results[2]['dense_rank']); // No gap
        $this->assertEquals(3, $results[3]['dense_rank']);
    }

    public function testWindowFunctionLag(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_lag (id INTEGER PRIMARY KEY, value INTEGER)');
        $db->find()->table('test_lag')->insertMulti([
            ['id' => 1, 'value' => 10],
            ['id' => 2, 'value' => 20],
            ['id' => 3, 'value' => 30],
        ]);

        $results = $db->find()
            ->from('test_lag')
            ->select([
                'id',
                'value',
                'prev_value' => Db::lag('value', 1, 0)->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals(0, $results[0]['prev_value']); // Default value for first row
        $this->assertEquals(10, $results[1]['prev_value']);
        $this->assertEquals(20, $results[2]['prev_value']);
    }

    public function testWindowFunctionLead(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_lead (id INTEGER PRIMARY KEY, value INTEGER)');
        $db->find()->table('test_lead')->insertMulti([
            ['id' => 1, 'value' => 10],
            ['id' => 2, 'value' => 20],
            ['id' => 3, 'value' => 30],
        ]);

        $results = $db->find()
            ->from('test_lead')
            ->select([
                'id',
                'value',
                'next_value' => Db::lead('value', 1, 0)->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals(20, $results[0]['next_value']);
        $this->assertEquals(30, $results[1]['next_value']);
        $this->assertEquals(0, $results[2]['next_value']); // Default value for last row
    }

    public function testWindowFunctionRunningTotal(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_running_total (id INTEGER PRIMARY KEY, amount INTEGER)');
        $db->find()->table('test_running_total')->insertMulti([
            ['id' => 1, 'amount' => 100],
            ['id' => 2, 'amount' => 200],
            ['id' => 3, 'amount' => 150],
        ]);

        $results = $db->find()
            ->from('test_running_total')
            ->select([
                'id',
                'amount',
                'running_total' => Db::windowAggregate('SUM', 'amount')
                    ->orderBy('id')
                    ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW'),
            ])
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals(100, $results[0]['running_total']);
        $this->assertEquals(300, $results[1]['running_total']);
        $this->assertEquals(450, $results[2]['running_total']);
    }

    public function testWindowFunctionMultiplePartitions(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_multi_part (id INTEGER PRIMARY KEY, dept TEXT, team TEXT, salary INTEGER)');
        $db->find()->table('test_multi_part')->insertMulti([
            ['id' => 1, 'dept' => 'IT', 'team' => 'Backend', 'salary' => 5000],
            ['id' => 2, 'dept' => 'IT', 'team' => 'Backend', 'salary' => 6000],
            ['id' => 3, 'dept' => 'IT', 'team' => 'Frontend', 'salary' => 5500],
        ]);

        $results = $db->find()
            ->from('test_multi_part')
            ->select([
                'id',
                'dept',
                'team',
                'salary',
                'rank_in_team' => Db::rank()->partitionBy(['dept', 'team'])->orderBy('salary', 'DESC'),
            ])
            ->orderBy('dept')
            ->orderBy('team')
            ->orderBy('salary', 'DESC')
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals(1, $results[0]['rank_in_team']);
        $this->assertEquals(2, $results[1]['rank_in_team']);
        $this->assertEquals(1, $results[2]['rank_in_team']);
    }

    public function testWindowFunctionFirstAndLastValue(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_first_last (id INTEGER PRIMARY KEY, value INTEGER)');
        $db->find()->table('test_first_last')->insertMulti([
            ['id' => 1, 'value' => 10],
            ['id' => 2, 'value' => 20],
            ['id' => 3, 'value' => 30],
        ]);

        $results = $db->find()
            ->from('test_first_last')
            ->select([
                'id',
                'value',
                'first_val' => Db::firstValue('value')->orderBy('id'),
                'last_val' => Db::lastValue('value')
                    ->orderBy('id')
                    ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING'),
            ])
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $results);
        $this->assertEquals(10, $results[0]['first_val']);
        $this->assertEquals(30, $results[0]['last_val']);
        $this->assertEquals(10, $results[2]['first_val']);
        $this->assertEquals(30, $results[2]['last_val']);
    }

    /* ---------------- CTE (Common Table Expressions) Tests ---------------- */

    public function testSimpleCte(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_cte (id INTEGER PRIMARY KEY, value INTEGER)');
        $db->find()->table('test_cte')->insertMulti([
            ['id' => 1, 'value' => 100],
            ['id' => 2, 'value' => 200],
            ['id' => 3, 'value' => 300],
        ]);

        $results = $db->find()
            ->with('high_values', function ($q) {
                $q->from('test_cte')->where('value', 150, '>');
            })
            ->from('high_values')
            ->orderBy('value')
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals(200, $results[0]['value']);
        $this->assertEquals(300, $results[1]['value']);
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

    public function testMultipleCtes(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_multi_cte (id INTEGER PRIMARY KEY, category TEXT, value INTEGER)');
        $db->find()->table('test_multi_cte')->insertMulti([
            ['id' => 1, 'category' => 'A', 'value' => 100],
            ['id' => 2, 'category' => 'A', 'value' => 200],
            ['id' => 3, 'category' => 'B', 'value' => 150],
            ['id' => 4, 'category' => 'B', 'value' => 250],
        ]);

        $results = $db->find()
            ->with('cat_a', function ($q) {
                $q->from('test_multi_cte')->where('category', 'A');
            })
            ->with('cat_b', function ($q) {
                $q->from('test_multi_cte')->where('category', 'B');
            })
            ->with('combined', Db::raw('SELECT * FROM cat_a UNION ALL SELECT * FROM cat_b'))
            ->from('combined')
            ->orderBy('value')
            ->get();

        $this->assertCount(4, $results);
        $this->assertEquals(100, $results[0]['value']);
        $this->assertEquals(250, $results[3]['value']);
    }

    public function testCteWithColumnList(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_cte_cols (id INTEGER PRIMARY KEY, val INTEGER)');
        $db->find()->table('test_cte_cols')->insertMulti([
            ['id' => 1, 'val' => 10],
            ['id' => 2, 'val' => 20],
        ]);

        $results = $db->find()
            ->with('renamed', function ($q) {
                $q->from('test_cte_cols')->select(['id', 'val']);
            }, ['record_id', 'value'])
            ->from('renamed')
            ->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('record_id', $results[0]);
        $this->assertArrayHasKey('value', $results[0]);
    }

    public function testRecursiveCte(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_hierarchy (id INTEGER PRIMARY KEY, parent_id INTEGER, name TEXT)');
        $db->find()->table('test_hierarchy')->insertMulti([
            ['id' => 1, 'parent_id' => null, 'name' => 'Root'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Child 1'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'Child 2'],
            ['id' => 4, 'parent_id' => 2, 'name' => 'Grandchild'],
        ]);

        $results = $db->find()
            ->withRecursive(
                'tree',
                Db::raw('SELECT id, parent_id, name, 1 as level FROM test_hierarchy WHERE parent_id IS NULL ' .
                    'UNION ALL ' .
                    'SELECT t.id, t.parent_id, t.name, tree.level + 1 ' .
                    'FROM test_hierarchy t JOIN tree ON t.parent_id = tree.id'),
                ['id', 'parent_id', 'name', 'level']
            )
            ->from('tree')
            ->orderBy('level')
            ->get();

        $this->assertCount(4, $results);
        $this->assertEquals(1, $results[0]['level']);
        $this->assertEquals('Root', $results[0]['name']);
        $this->assertEquals(2, $results[1]['level']);
        $this->assertEquals(3, $results[3]['level']);
        $this->assertEquals('Grandchild', $results[3]['name']);
    }

    public function testCteManagerGetters(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_cte_mgr (id INTEGER PRIMARY KEY)');

        $query = $db->find()
            ->with('test1', function ($q) {
                $q->from('test_cte_mgr');
            })
            ->withRecursive('test2', Db::raw('SELECT * FROM test_cte_mgr'), ['id'])
            ->from('test1');

        $cteManager = $query->getCteManager();
        $this->assertNotNull($cteManager);
        $this->assertFalse($cteManager->isEmpty());
        $this->assertTrue($cteManager->hasRecursive());
        $this->assertCount(2, $cteManager->getAll());
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

    /* ---------------- UNION/INTERSECT/EXCEPT Tests ---------------- */

    public function testSimpleUnion(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_union_a (id INTEGER PRIMARY KEY, value TEXT)');
        $db->rawQuery('CREATE TABLE test_union_b (id INTEGER PRIMARY KEY, value TEXT)');
        $db->find()->table('test_union_a')->insertMulti([
            ['id' => 1, 'value' => 'A'],
            ['id' => 2, 'value' => 'B'],
        ]);
        $db->find()->table('test_union_b')->insertMulti([
            ['id' => 3, 'value' => 'C'],
            ['id' => 2, 'value' => 'B'], // Duplicate
        ]);

        $results = $db->find()
            ->from('test_union_a')
            ->select(['id', 'value'])
            ->union(function ($qb) {
                $qb->from('test_union_b')->select(['id', 'value']);
            })
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $results); // UNION removes duplicates
        $this->assertEquals(1, $results[0]['id']);
        $this->assertEquals(2, $results[1]['id']);
        $this->assertEquals(3, $results[2]['id']);
    }

    public function testUnionAll(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_union_all_a (id INTEGER PRIMARY KEY, value TEXT)');
        $db->rawQuery('CREATE TABLE test_union_all_b (id INTEGER PRIMARY KEY, value TEXT)');
        $db->find()->table('test_union_all_a')->insertMulti([
            ['id' => 1, 'value' => 'A'],
            ['id' => 2, 'value' => 'B'],
        ]);
        $db->find()->table('test_union_all_b')->insertMulti([
            ['id' => 3, 'value' => 'C'],
            ['id' => 2, 'value' => 'B'], // Duplicate
        ]);

        $results = $db->find()
            ->from('test_union_all_a')
            ->select(['value'])
            ->unionAll(function ($qb) {
                $qb->from('test_union_all_b')->select(['value']);
            })
            ->get();

        $this->assertCount(4, $results); // UNION ALL keeps duplicates
    }

    public function testIntersect(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_intersect_a (id INTEGER PRIMARY KEY, value TEXT)');
        $db->rawQuery('CREATE TABLE test_intersect_b (id INTEGER PRIMARY KEY, value TEXT)');
        $db->find()->table('test_intersect_a')->insertMulti([
            ['id' => 1, 'value' => 'A'],
            ['id' => 2, 'value' => 'B'],
        ]);
        $db->find()->table('test_intersect_b')->insertMulti([
            ['id' => 3, 'value' => 'B'],
            ['id' => 4, 'value' => 'C'],
        ]);

        $results = $db->find()
            ->from('test_intersect_a')
            ->select(['value'])
            ->intersect(function ($qb) {
                $qb->from('test_intersect_b')->select(['value']);
            })
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('B', $results[0]['value']);
    }

    public function testExcept(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_except_a (id INTEGER PRIMARY KEY, value TEXT)');
        $db->rawQuery('CREATE TABLE test_except_b (id INTEGER PRIMARY KEY, value TEXT)');
        $db->find()->table('test_except_a')->insertMulti([
            ['id' => 1, 'value' => 'A'],
            ['id' => 2, 'value' => 'B'],
        ]);
        $db->find()->table('test_except_b')->insertMulti([
            ['id' => 3, 'value' => 'B'],
        ]);

        $results = $db->find()
            ->from('test_except_a')
            ->select(['value'])
            ->except(function ($qb) {
                $qb->from('test_except_b')->select(['value']);
            })
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('A', $results[0]['value']);
    }

    /* ---------------- DISTINCT Tests ---------------- */

    public function testDistinct(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_distinct (id INTEGER PRIMARY KEY, category TEXT, value INTEGER)');
        $db->find()->table('test_distinct')->insertMulti([
            ['id' => 1, 'category' => 'A', 'value' => 100],
            ['id' => 2, 'category' => 'A', 'value' => 200],
            ['id' => 3, 'category' => 'B', 'value' => 100],
        ]);

        $results = $db->find()
            ->from('test_distinct')
            ->select(['category'])
            ->distinct()
            ->orderBy('category')
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('A', $results[0]['category']);
        $this->assertEquals('B', $results[1]['category']);
    }

    /* ---------------- FILTER Clause Tests ---------------- */

    public function testFilterClause(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_filter (id INTEGER PRIMARY KEY, category TEXT, amount INTEGER)');
        $db->find()->table('test_filter')->insertMulti([
            ['id' => 1, 'category' => 'A', 'amount' => 100],
            ['id' => 2, 'category' => 'A', 'amount' => 200],
            ['id' => 3, 'category' => 'B', 'amount' => 150],
        ]);

        $results = $db->find()
            ->from('test_filter')
            ->select([
                'total' => Db::count('*'),
                'count_a' => Db::count('*')->filter('category', 'A'),
                'sum_a' => Db::sum('amount')->filter('category', 'A'),
            ])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(3, $results[0]['total']);
        $this->assertEquals(2, $results[0]['count_a']);
        $this->assertEquals(300, $results[0]['sum_a']);
    }

    public function testFilterClauseWithGroupBy(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_filter_group (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, amount INTEGER)');
        $db->find()->table('test_filter_group')->insertMulti([
            ['id' => 1, 'user_id' => 1, 'status' => 'paid', 'amount' => 100],
            ['id' => 2, 'user_id' => 1, 'status' => 'pending', 'amount' => 50],
            ['id' => 3, 'user_id' => 2, 'status' => 'paid', 'amount' => 200],
        ]);

        $results = $db->find()
            ->from('test_filter_group')
            ->select([
                'user_id',
                'total_orders' => Db::count('*'),
                'paid_orders' => Db::count('*')->filter('status', 'paid'),
                'pending_amount' => Db::sum('amount')->filter('status', 'pending'),
            ])
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals(1, $results[0]['user_id']);
        $this->assertEquals(2, $results[0]['total_orders']);
        $this->assertEquals(1, $results[0]['paid_orders']);
        $this->assertEquals(50, $results[0]['pending_amount']);
    }

    /* ---------------- Edge Case Tests ---------------- */

    // CTE Edge Cases
    public function testCteWithEmptyResult(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_cte_empty (id INTEGER PRIMARY KEY, value INTEGER)');

        $results = $db->find()
            ->with('empty_cte', function ($qb) {
                $qb->from('test_cte_empty')->select(['id', 'value'])->where('id', -999);
            })
            ->from('empty_cte')
            ->get();

        $this->assertCount(0, $results);
    }

    public function testCteWithNullValues(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_cte_nulls (id INTEGER PRIMARY KEY, value INTEGER)');
        $db->find()->table('test_cte_nulls')->insertMulti([
            ['id' => 1, 'value' => 100],
            ['id' => 2, 'value' => null],
            ['id' => 3, 'value' => 300],
        ]);

        $results = $db->find()
            ->with('null_cte', function ($qb) {
                $qb->from('test_cte_nulls')->select(['id', 'value']);
            })
            ->from('null_cte')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $results);
        $this->assertNull($results[1]['value']);
    }

    public function testRecursiveCteWithComplexData(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_recursive_complex (id INTEGER PRIMARY KEY, parent_id INTEGER, value INTEGER)');
        $db->find()->table('test_recursive_complex')->insertMulti([
            ['id' => 1, 'parent_id' => null, 'value' => 100],
            ['id' => 2, 'parent_id' => 1, 'value' => 200],
            ['id' => 3, 'parent_id' => 1, 'value' => 300],
        ]);

        // Simple recursive CTE
        $results = $db->find()
            ->withRecursive('tree', function ($qb) {
                $qb->from('test_recursive_complex')
                    ->select(['id', 'parent_id', 'value'])
                    ->where('parent_id', null)
                    ->unionAll(function ($union) {
                        $union->from('test_recursive_complex')
                            ->join('tree', 'test_recursive_complex.parent_id = tree.id')
                            ->select(['test_recursive_complex.id', 'test_recursive_complex.parent_id', 'test_recursive_complex.value']);
                    });
            })
            ->from('tree')
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(1, count($results)); // At least root
        $this->assertLessThanOrEqual(3, count($results)); // At most all 3
    }

    // UNION Edge Cases
    public function testUnionWithEmptyResults(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_union_empty1 (id INTEGER PRIMARY KEY, value TEXT)');
        $db->rawQuery('CREATE TABLE test_union_empty2 (id INTEGER PRIMARY KEY, value TEXT)');
        $db->find()->table('test_union_empty1')->insertMulti([
            ['id' => 1, 'value' => 'A'],
        ]);
        // test_union_empty2 is empty

        $results = $db->find()
            ->from('test_union_empty1')
            ->select(['value'])
            ->union(function ($qb) {
                $qb->from('test_union_empty2')->select(['value']);
            })
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('A', $results[0]['value']);
    }

    public function testUnionAllEmptyTables(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_union_all_empty1 (id INTEGER PRIMARY KEY, value TEXT)');
        $db->rawQuery('CREATE TABLE test_union_all_empty2 (id INTEGER PRIMARY KEY, value TEXT)');

        $results = $db->find()
            ->from('test_union_all_empty1')
            ->select(['value'])
            ->union(function ($qb) {
                $qb->from('test_union_all_empty2')->select(['value']);
            })
            ->get();

        $this->assertCount(0, $results);
    }

    public function testUnionWithNulls(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_union_nulls1 (id INTEGER PRIMARY KEY, value TEXT)');
        $db->rawQuery('CREATE TABLE test_union_nulls2 (id INTEGER PRIMARY KEY, value TEXT)');
        $db->find()->table('test_union_nulls1')->insertMulti([
            ['id' => 1, 'value' => 'A'],
            ['id' => 2, 'value' => null],
        ]);
        $db->find()->table('test_union_nulls2')->insertMulti([
            ['id' => 3, 'value' => null],
            ['id' => 4, 'value' => 'B'],
        ]);

        $results = $db->find()
            ->from('test_union_nulls1')
            ->select(['value'])
            ->unionAll(function ($qb) {
                $qb->from('test_union_nulls2')->select(['value']);
            })
            ->get();

        $this->assertCount(4, $results);
        $nullCount = count(array_filter($results, fn ($r) => $r['value'] === null));
        $this->assertEquals(2, $nullCount);
    }

    public function testIntersectWithNoCommonValues(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_intersect_none1 (id INTEGER PRIMARY KEY, value TEXT)');
        $db->rawQuery('CREATE TABLE test_intersect_none2 (id INTEGER PRIMARY KEY, value TEXT)');
        $db->find()->table('test_intersect_none1')->insertMulti([
            ['id' => 1, 'value' => 'A'],
        ]);
        $db->find()->table('test_intersect_none2')->insertMulti([
            ['id' => 2, 'value' => 'B'],
        ]);

        $results = $db->find()
            ->from('test_intersect_none1')
            ->select(['value'])
            ->intersect(function ($qb) {
                $qb->from('test_intersect_none2')->select(['value']);
            })
            ->get();

        $this->assertCount(0, $results);
    }

    // FILTER Edge Cases
    public function testFilterWithNoMatches(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_filter_nomatch (id INTEGER PRIMARY KEY, status TEXT, amount INTEGER)');
        $db->find()->table('test_filter_nomatch')->insertMulti([
            ['id' => 1, 'status' => 'pending', 'amount' => 100],
            ['id' => 2, 'status' => 'pending', 'amount' => 200],
        ]);

        $results = $db->find()
            ->from('test_filter_nomatch')
            ->select([
                'total' => Db::count('*'),
                'paid' => Db::count('*')->filter('status', 'paid'),
                'paid_sum' => Db::sum('amount')->filter('status', 'paid'),
            ])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]['total']);
        $this->assertEquals(0, $results[0]['paid']);
        $this->assertNull($results[0]['paid_sum']); // SUM of empty set is NULL
    }

    public function testFilterWithAllNulls(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_filter_allnulls (id INTEGER PRIMARY KEY, status TEXT, amount INTEGER)');
        $db->find()->table('test_filter_allnulls')->insertMulti([
            ['id' => 1, 'status' => 'paid', 'amount' => null],
            ['id' => 2, 'status' => 'paid', 'amount' => null],
        ]);

        $results = $db->find()
            ->from('test_filter_allnulls')
            ->select([
                'count_paid' => Db::count('*')->filter('status', 'paid'),
                'sum_paid' => Db::sum('amount')->filter('status', 'paid'),
                'avg_paid' => Db::avg('amount')->filter('status', 'paid'),
            ])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]['count_paid']);
        $this->assertNull($results[0]['sum_paid']); // SUM of NULLs is NULL
        $this->assertNull($results[0]['avg_paid']); // AVG of NULLs is NULL
    }

    public function testFilterOnEmptyTable(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_filter_empty (id INTEGER PRIMARY KEY, status TEXT, amount INTEGER)');

        $results = $db->find()
            ->from('test_filter_empty')
            ->select([
                'total' => Db::count('*'),
                'paid' => Db::count('*')->filter('status', 'paid'),
            ])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(0, $results[0]['total']);
        $this->assertEquals(0, $results[0]['paid']);
    }

    // DISTINCT Edge Cases
    public function testDistinctOnEmptyTable(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_distinct_empty (id INTEGER PRIMARY KEY, category TEXT)');

        $results = $db->find()
            ->from('test_distinct_empty')
            ->select(['category'])
            ->distinct()
            ->get();

        $this->assertCount(0, $results);
    }

    public function testDistinctWithAllNulls(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_distinct_nulls (id INTEGER PRIMARY KEY, category TEXT)');
        $db->find()->table('test_distinct_nulls')->insertMulti([
            ['id' => 1, 'category' => null],
            ['id' => 2, 'category' => null],
            ['id' => 3, 'category' => null],
        ]);

        $results = $db->find()
            ->from('test_distinct_nulls')
            ->select(['category'])
            ->distinct()
            ->get();

        $this->assertCount(1, $results); // DISTINCT treats NULLs as one unique value
        $this->assertNull($results[0]['category']);
    }

    public function testDistinctWithMixedNulls(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_distinct_mixed (id INTEGER PRIMARY KEY, category TEXT)');
        $db->find()->table('test_distinct_mixed')->insertMulti([
            ['id' => 1, 'category' => 'A'],
            ['id' => 2, 'category' => null],
            ['id' => 3, 'category' => 'A'],
            ['id' => 4, 'category' => null],
            ['id' => 5, 'category' => 'B'],
        ]);

        $results = $db->find()
            ->from('test_distinct_mixed')
            ->select(['category'])
            ->distinct()
            ->orderBy('category')
            ->get();

        $this->assertCount(3, $results); // null, A, B
    }

    // Window Functions Edge Cases
    public function testWindowFunctionOnEmptyTable(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_window_empty (id INTEGER PRIMARY KEY, value INTEGER)');

        $results = $db->find()
            ->from('test_window_empty')
            ->select([
                'id',
                'row_num' => Db::rowNumber()->orderBy('id'),
            ])
            ->get();

        $this->assertCount(0, $results);
    }

    public function testWindowFunctionWithAllNullPartitions(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_window_null_partition (id INTEGER PRIMARY KEY, category TEXT, value INTEGER)');
        $db->find()->table('test_window_null_partition')->insertMulti([
            ['id' => 1, 'category' => null, 'value' => 100],
            ['id' => 2, 'category' => null, 'value' => 200],
            ['id' => 3, 'category' => null, 'value' => 150],
        ]);

        $results = $db->find()
            ->from('test_window_null_partition')
            ->select([
                'id',
                'value',
                'row_num' => Db::rowNumber()->partitionBy('category')->orderBy('value'),
            ])
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $results);
        // All NULLs in same partition, should get row numbers 1, 2, 3
        $this->assertEquals(1, $results[0]['row_num']);
        $this->assertEquals(3, $results[1]['row_num']); // value 200 is highest
        $this->assertEquals(2, $results[2]['row_num']); // value 150 is middle
    }

    public function testWindowFunctionWithSingleRow(): void
    {
        $db = self::$db;
        $db->rawQuery('CREATE TABLE test_window_single (id INTEGER PRIMARY KEY, value INTEGER)');
        $db->find()->table('test_window_single')->insert(['id' => 1, 'value' => 100]);

        $results = $db->find()
            ->from('test_window_single')
            ->select([
                'id',
                'value',
                'row_num' => Db::rowNumber()->orderBy('id'),
                'rank_val' => Db::rank()->orderBy('value'),
            ])
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['row_num']);
        $this->assertEquals(1, $results[0]['rank_val']);
    }

    // Prepared Statement Pool Tests
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
