<?php

namespace tommyknocker\pdodb\tests;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;
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
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['max' => Db::greatest(Db::raw('1'), Db::raw('5'), Db::raw('3'))])
            ->getValue();
        
        $this->assertEquals(5, $result);
    }

    public function testLeastWithMixedTypes(): void
    {
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['min' => Db::least(Db::raw('10'), Db::raw('5'), Db::raw('8'))])
            ->getValue();
        
        $this->assertEquals(5, $result);
    }

    public function testModWithNegativeNumbers(): void
    {
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::mod(Db::raw('-10'), Db::raw('3'))])
            ->getValue();
        
        // -10 % 3 = -1
        $this->assertEquals(-1, $result);
    }

    public function testAbsWithNegative(): void
    {
        $result = self::$db->find()
            ->from('test_coverage')
            ->select(['result' => Db::abs(Db::raw('-42.5'))])
            ->getValue();
        
        $this->assertEquals(42.5, $result);
    }

    public function testRoundWithPrecision(): void
    {
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
        self::$db->rawQuery("DELETE FROM test_coverage");
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
        self::$db->rawQuery("DELETE FROM test_coverage");
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
        self::$db->rawQuery("DELETE FROM test_coverage");
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
        self::$db->rawQuery("DELETE FROM test_coverage");
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (5), (15), (25)");
        
        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::notBetween('value', 10, 20))
            ->get();
        
        $this->assertCount(2, $results); // 5 and 25
    }

    public function testInOperator(): void
    {
        self::$db->rawQuery("DELETE FROM test_coverage");
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (1), (2), (3), (4), (5)");
        
        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::in('value', [2, 4]))
            ->get();
        
        $this->assertCount(2, $results);
    }

    public function testNotInOperator(): void
    {
        self::$db->rawQuery("DELETE FROM test_coverage");
        self::$db->rawQuery("INSERT INTO test_coverage (value) VALUES (1), (2), (3)");
        
        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::notIn('value', [2]))
            ->get();
        
        $this->assertCount(2, $results); // 1 and 3
    }

    public function testLikeOperator(): void
    {
        self::$db->rawQuery("DELETE FROM test_coverage");
        self::$db->rawQuery("INSERT INTO test_coverage (name) VALUES ('Hello'), ('World'), ('Help')");
        
        $results = self::$db->find()
            ->from('test_coverage')
            ->where(Db::like('name', 'Hel%'))
            ->get();
        
        $this->assertCount(2, $results); // Hello and Help
    }

    public function testNotOperator(): void
    {
        self::$db->rawQuery("DELETE FROM test_coverage");
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
        self::$db->rawQuery("DELETE FROM test_coverage");
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
        self::$db->rawQuery("DELETE FROM test_coverage");
        
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
        self::$db->rawQuery("DELETE FROM test_coverage");
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
        self::$db->rawQuery("DELETE FROM test_coverage");
        
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
        self::$db->rawQuery("DELETE FROM test_coverage");
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

    /* ---------------- Cleanup ---------------- */

    public static function tearDownAfterClass(): void
    {
        self::$db->disconnect();
    }
}

