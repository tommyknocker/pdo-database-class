<?php

namespace tommyknocker\pdodb\tests;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\exceptions\AuthenticationException;
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\exceptions\TimeoutException;
use tommyknocker\pdodb\exceptions\TransactionException;
use tommyknocker\pdodb\helpers\DbError;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;

/**
 * Shared coverage tests for dialect-independent code
 * Runs against SQLite for speed and simplicity
 */
class SharedCoverageTest extends TestCase
{
    private static PdoDb $db;

    public static function setUpBeforeClass(): void
    {
        self::$db = new PdoDb('sqlite', ['path' => ':memory:']);
        
        // Create test table
        self::$db->rawQuery("
            CREATE TABLE test_coverage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                value INTEGER,
                meta TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * Clean up test data before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Clear all data from test table before each test
        self::$db->rawQuery("DELETE FROM test_coverage");
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
        $this->expectException(\PDOException::class);
        self::$db->rawQuery('THIS IS NOT VALID SQL SYNTAX');
    }

    public function testQueryNonexistentTable(): void
    {
        $this->expectException(\PDOException::class);
        self::$db->rawQuery('SELECT * FROM absolutely_nonexistent_table_xyz_123');
    }

    public function testInsertIntoNonexistentTable(): void
    {
        $this->expectException(\PDOException::class);
        self::$db->find()->table('nonexistent_xyz')->insert(['name' => 'test']);
    }

    /* ---------------- Db Helper Edge Cases Tests ---------------- */

    public function testDbHelpersWithNullValues(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES (NULL, NULL)");
        
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
        self::$db->rawQuery("INSERT INTO test_coverage (name, value) VALUES (NULL, NULL)");
        
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
                'round_4' => Db::round(Db::raw('3.14159'), 4)
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
                'lower' => Db::lower('name')
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
                'current_time' => Db::curTime()
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
                'second' => Db::second('created_at')
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
                'just_time' => Db::time('created_at')
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
                'as_text' => Db::cast('123', 'TEXT')
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
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (10), (20), (30)");
        
        $result = self::$db->find()
            ->from('test_coverage')
            ->select([
                'total' => Db::count(),
                'sum' => Db::sum('value'),
                'avg' => Db::avg('value'),
                'min' => Db::min('value'),
                'max' => Db::max('value')
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
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (1), (1), (2)");
        
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['distinct_count' => Db::count('DISTINCT value')])
            ->getValue();
        
        $this->assertEquals(2, $result); // Only 1 and 2
    }

    /* ---------------- Comparison Operators Tests ---------------- */

    public function testBetweenOperator(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (5), (15), (25)");
        
        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::between('value', 10, 20))
            ->get();
        
        $this->assertCount(1, $results);
        $this->assertEquals(15, $results[0]['value']);
    }

    public function testNotBetweenOperator(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (5), (15), (25)");
        
        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::notBetween('value', 10, 20))
            ->get();
        
        $this->assertCount(2, $results); // 5 and 25
    }

    public function testInOperator(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (1), (2), (3), (4), (5)");
        
        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::in('value', [2, 4]))
            ->get();
        
        $this->assertCount(2, $results);
    }

    public function testNotInOperator(): void
    {
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (1), (2), (3)");
        
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
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (5), (15), (25)");
        
        $results = self::$db->find()
            ->from('test_coverage')
            ->select([
                'value',
                'category' => Db::raw("CASE WHEN value < 10 THEN 'low' WHEN value < 20 THEN 'medium' ELSE 'high' END")
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
        
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\RawValue::class, $trueVal);
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\RawValue::class, $falseVal);
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
            throw new \Exception('Simulated error');
            
        } catch (\Exception $e) {
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
                'rtrimmed' => Db::rtrim('name')
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
        
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testGetLastError(): void
    {
        $connection = self::$db->connection;
        
        // Initially null
        $this->assertNull($connection->getLastError());
        
        // After error, should be set
        try {
            self::$db->rawQuery('SELECT * FROM nonexistent_table');
        } catch (\PDOException $e) {
            $lastError = $connection->getLastError();
            $this->assertNotNull($lastError);
            $this->assertStringContainsString('nonexistent_table', $lastError);
        }
    }

    public function testQueryBuilderGetters(): void
    {
        $qb = self::$db->find()->table('test_coverage');
        
        // Test getConnection
        $this->assertInstanceOf(\tommyknocker\pdodb\connection\ConnectionInterface::class, $qb->getConnection());
        
        // Test getDialect
        $this->assertInstanceOf(\tommyknocker\pdodb\dialects\DialectInterface::class, $qb->getDialect());
        
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
        self::$db->rawQuery("
            CREATE TABLE IF NOT EXISTS test_json_raw (
                id INTEGER PRIMARY KEY,
                data TEXT
            )
        ");
        
        self::$db->find()->table('test_json_raw')->insert([
            'data' => json_encode(['count' => 5])
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
        
        $this->expectException(\InvalidArgumentException::class);
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
        $this->expectException(\PDOException::class);
        
        try {
            $connection->prepare('INVALID SQL SYNTAX HERE @#$%');
        } catch (\PDOException $e) {
            // Verify lastError is set
            $this->assertNotNull($connection->getLastError());
            $this->assertGreaterThanOrEqual(0, $connection->getLastErrno());
            throw $e;
        }
    }

    public function testQueryException(): void
    {
        $connection = self::$db->connection;
        
        $this->expectException(\PDOException::class);
        
        try {
            $connection->query('SELECT * FROM nonexistent_table_xyz');
        } catch (\PDOException $e) {
            // Verify lastError and lastErrno are set
            $this->assertNotNull($connection->getLastError());
            $this->assertStringContainsString('nonexistent_table_xyz', $connection->getLastError());
            throw $e;
        }
    }

    public function testExecuteException(): void
    {
        $connection = self::$db->connection;
        
        $this->expectException(\PDOException::class);
        
        try {
            // Create a constraint violation
            $connection->prepare('INSERT INTO test_coverage (id, name) VALUES (?, ?)')
                ->execute([999, 'test']);
            // Try to insert same id again
            $connection->prepare('INSERT INTO test_coverage (id, name) VALUES (?, ?)')
                ->execute([999, 'test2']);
        } catch (\PDOException $e) {
            $this->assertNotNull($connection->getLastError());
            throw $e;
        }
    }

    public function testTransactionBeginException(): void
    {
        // SQLite throws exception on nested transactions
        self::$db->startTransaction();
        
        $this->expectException(\PDOException::class);
        
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
        $this->expectException(\PDOException::class);
        
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
        $this->expectException(\PDOException::class);
        
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
                )
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
        $result = self::$db->transaction(function($db) {
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
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Test rollback');
        
        try {
            self::$db->transaction(function($db) {
                $db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 42]);
                throw new \Exception('Test rollback');
            });
        } catch (\Exception $e) {
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
        $this->expectException(\InvalidArgumentException::class);
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
                ]
            ]
        ];
        
        $db = new PdoDb('sqlite', $config);
        
        // Verify we get a RetryableConnection
        $connection = $db->connection;
        $this->assertInstanceOf(\tommyknocker\pdodb\connection\RetryableConnection::class, $connection);
        
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
                ]
            ]
        ];
        
        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;
        
        $this->assertInstanceOf(\tommyknocker\pdodb\connection\RetryableConnection::class, $connection);
        
        if ($connection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
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
                ]
            ]
        ];
        
        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;
        
        // Should get regular Connection when retry is disabled
        $this->assertInstanceOf(\tommyknocker\pdodb\connection\Connection::class, $connection);
        $this->assertNotInstanceOf(\tommyknocker\pdodb\connection\RetryableConnection::class, $connection);
    }

    public function testRetryableConnectionWithoutConfig(): void
    {
        $config = [
            'path' => ':memory:'
            // No retry config
        ];
        
        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;
        
        // Should get regular Connection when no retry config
        $this->assertInstanceOf(\tommyknocker\pdodb\connection\Connection::class, $connection);
        $this->assertNotInstanceOf(\tommyknocker\pdodb\connection\RetryableConnection::class, $connection);
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
                ]
            ]
        ];
        
        $db = new PdoDb('sqlite', $config);
        $connection = $db->connection;
        
        $this->assertInstanceOf(\tommyknocker\pdodb\connection\RetryableConnection::class, $connection);
        
        // Initially should be 0
        if ($connection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
            $this->assertEquals(0, $connection->getCurrentAttempt());
        }
        
        // Test that normal operations work
        $db->rawQuery('CREATE TABLE retry_attempt_test (id INTEGER PRIMARY KEY)');
        $db->rawQuery('INSERT INTO retry_attempt_test (id) VALUES (1)');
        
        // Should be 1 after successful operation (attempt counter starts at 1)
        if ($connection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
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
                'retryable_errors' => [2006, '08006']
            ]
        ];
        
        $db = new PdoDb('sqlite', $validConfig);
        $this->assertInstanceOf(\tommyknocker\pdodb\connection\RetryableConnection::class, $db->connection);
    }

    public function testRetryConfigValidationInvalidEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.enabled must be a boolean');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => 'true', // Should be boolean
                'max_attempts' => 3,
                'delay_ms' => 1000,
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidMaxAttempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_attempts must be a positive integer');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 0, // Should be >= 1
                'delay_ms' => 1000,
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationMaxAttemptsTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_attempts cannot exceed 100');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 101, // Should be <= 100
                'delay_ms' => 1000,
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.delay_ms must be a non-negative integer');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => -100, // Should be >= 0
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationDelayTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.delay_ms cannot exceed 300000ms (5 minutes)');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 300001, // Should be <= 300000
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidBackoffMultiplier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.backoff_multiplier must be a number >= 1.0');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'backoff_multiplier' => 0.5, // Should be >= 1.0
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationBackoffMultiplierTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.backoff_multiplier cannot exceed 10.0');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'backoff_multiplier' => 11.0, // Should be <= 10.0
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidMaxDelay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms must be a non-negative integer');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'max_delay_ms' => -1000, // Should be >= 0
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationMaxDelayTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms cannot exceed 300000ms (5 minutes)');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'max_delay_ms' => 300001, // Should be <= 300000
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidRetryableErrors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.retryable_errors must be an array');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'retryable_errors' => 'not_an_array', // Should be array
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationInvalidRetryableErrorsContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.retryable_errors must contain only integers or strings');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 1000,
                'retryable_errors' => [2006, ['nested_array']], // Should contain only int/string
            ]
        ];
        
        new PdoDb('sqlite', $config);
    }

    public function testRetryConfigValidationLogicalConstraint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms cannot be less than retry.delay_ms');
        
        $config = [
            'path' => ':memory:',
            'retry' => [
                'enabled' => true,
                'max_attempts' => 3,
                'delay_ms' => 5000,
                'max_delay_ms' => 1000, // Should be >= delay_ms
            ]
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
                'retryable_errors' => [2006] // MySQL connection lost
            ]
        ];

        $db = new PdoDb('sqlite', $config, [], $logger);
        $connection = $db->connection;
        
        // Set the logger on the connection
        if ($connection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
            $reflection = new \ReflectionClass($connection);
            $loggerProperty = $reflection->getProperty('logger');
            $loggerProperty->setAccessible(true);
            $loggerProperty->setValue($connection, $logger);
        }

        // Execute a simple query that should succeed
        $result = $db->connection->query('SELECT 1 as test');
        $this->assertNotFalse($result);
        
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
                'retryable_errors' => [9999] // Non-existent error code
            ]
        ];

        $db = new PdoDb('sqlite', $config, [], $logger);
        $connection = $db->connection;
        
        // Set the logger on the connection
        if ($connection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
            $reflection = new \ReflectionClass($connection);
            $loggerProperty = $reflection->getProperty('logger');
            $loggerProperty->setAccessible(true);
            $loggerProperty->setValue($connection, $logger);
        }

        // Execute a query that should succeed (no retry needed)
        $result = $db->connection->query('SELECT 1 as test');
        $this->assertNotFalse($result);
        
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
                'retryable_errors' => [2006]
            ]
        ];

        $db = new PdoDb('sqlite', $config, [], $logger);
        $connection = $db->connection;
        
        // Set the logger on the connection
        if ($connection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
            $reflection = new \ReflectionClass($connection);
            $loggerProperty = $reflection->getProperty('logger');
            $loggerProperty->setAccessible(true);
            $loggerProperty->setValue($connection, $logger);
        }

        // Execute a successful query
        $result = $db->connection->query('SELECT 1 as test');
        $this->assertNotFalse($result);
        
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
        $pdoException = new \PDOException('Test error', 12345);
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
        $mysqlConnectionError = new \PDOException('MySQL server has gone away', 2006);
        $exception = ExceptionFactory::createFromPdoException($mysqlConnectionError, 'mysql');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // PostgreSQL connection errors
        $pgsqlConnectionError = new \PDOException('Connection refused', 0);
        $pgsqlConnectionError->errorInfo = ['08006', '08006', 'Connection refused'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlConnectionError, 'pgsql');
        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // SQLite connection errors
        $sqliteConnectionError = new \PDOException('Unable to open database file', 14);
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
        $mysqlDuplicate = new \PDOException('Duplicate entry \'test@example.com\' for key \'unique_email\'', 1062);
        $exception = ExceptionFactory::createFromPdoException($mysqlDuplicate, 'mysql');
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // PostgreSQL unique violation
        $pgsqlUnique = new \PDOException('duplicate key value violates unique constraint "users_email_key"', 0);
        $pgsqlUnique->errorInfo = ['23505', '23505', 'duplicate key value violates unique constraint "users_email_key"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlUnique, 'pgsql');
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // SQLite constraint
        $sqliteConstraint = new \PDOException('UNIQUE constraint failed: users.email', 19);
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
        $pgsqlDeadlock = new \PDOException('deadlock detected', 0);
        $pgsqlDeadlock->errorInfo = ['40001', '40001', 'deadlock detected'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlDeadlock, 'pgsql');
        $this->assertInstanceOf(TransactionException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // SQLite busy
        $sqliteBusy = new \PDOException('database is locked', 5);
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
        $mysqlAuth = new \PDOException('Access denied for user \'testuser\'@\'localhost\'', 1045);
        $exception = ExceptionFactory::createFromPdoException($mysqlAuth, 'mysql');
        $this->assertInstanceOf(AuthenticationException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // PostgreSQL authentication failed
        $pgsqlAuth = new \PDOException('password authentication failed for user "testuser"', 0);
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
        $pgsqlTimeout = new \PDOException('canceling statement due to statement timeout', 0);
        $pgsqlTimeout->errorInfo = ['57014', '57014', 'canceling statement due to statement timeout'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlTimeout, 'pgsql');
        $this->assertInstanceOf(TimeoutException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // MySQL timeout (this is actually a connection error)
        $mysqlTimeout = new \PDOException('Lost connection to MySQL server during query', 2006);
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
        $mysqlResource = new \PDOException('Too many connections', 1040);
        $exception = ExceptionFactory::createFromPdoException($mysqlResource, 'mysql');
        $this->assertInstanceOf(ResourceException::class, $exception);
        $this->assertTrue($exception->isRetryable());

        // PostgreSQL too many connections
        $pgsqlResource = new \PDOException('too many connections for role "testuser"', 0);
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
        $unknownColumn = new \PDOException('Unknown column \'invalid_column\' in \'field list\'', 0);
        $unknownColumn->errorInfo = ['42S22', '42S22', 'Unknown column \'invalid_column\' in \'field list\''];
        $exception = ExceptionFactory::createFromPdoException($unknownColumn, 'mysql');
        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertFalse($exception->isRetryable());

        // Table doesn't exist
        $tableNotFound = new \PDOException('Table \'testdb.nonexistent_table\' doesn\'t exist', 0);
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
        $pdoException = new \PDOException('Test error', 12345);
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
        $mysqlError = new \PDOException('Duplicate entry \'test@example.com\' for key \'unique_email\' in table \'users\'', 1062);
        $exception = ExceptionFactory::createFromPdoException($mysqlError, 'mysql');
        
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertEquals('unique_email', $exception->getConstraintName());
        $this->assertEquals('users', $exception->getTableName());

        // Test PostgreSQL constraint parsing
        $pgsqlError = new \PDOException('duplicate key value violates unique constraint "users_email_key"', 0);
        $pgsqlError->errorInfo = ['23505', '23505', 'duplicate key value violates unique constraint "users_email_key"'];
        $exception = ExceptionFactory::createFromPdoException($pgsqlError, 'pgsql');
        
        $this->assertInstanceOf(ConstraintViolationException::class, $exception);
        $this->assertEquals('users_email_key', $exception->getConstraintName());
    }
}

