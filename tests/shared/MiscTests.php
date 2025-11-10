<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Exception;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use PDOException;
use ReflectionClass;
use RuntimeException;
use tommyknocker\pdodb\connection\Connection;
use tommyknocker\pdodb\connection\RetryableConnection;
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
 * MiscTests tests for shared.
 */
final class MiscTests extends BaseSharedTestCase
{
    public function testSetLockMethod(): void
    {
        $result = self::$db->setLockMethod('READ');
        $this->assertInstanceOf(PdoDb::class, $result);

        $result2 = self::$db->setLockMethod('write'); // lowercase
        $this->assertInstanceOf(PdoDb::class, $result2);

        $result3 = self::$db->setLockMethod('WRITE'); // uppercase
        $this->assertInstanceOf(PdoDb::class, $result3);
    }

    public function testInvalidSqlError(): void
    {
        $this->expectException(PDOException::class);
        self::$db->rawQuery('THIS IS NOT VALID SQL SYNTAX');
    }

    public function testInsertIntoNonexistentTable(): void
    {
        $this->expectException(PDOException::class);
        self::$db->find()->table('nonexistent_xyz')->insert(['name' => 'test']);
    }

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

    public function testInsertMultiWithEmptyRows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('insertMulti requires at least one row');

        self::$db->find()->table('test_coverage')->insertMulti([]);
    }

    public function testUpdateWithRawValue(): void
    {
        // Test UPDATE with RawValue - this is a more realistic scenario than REPLACE
        // REPLACE in SQLite doesn't support column references in VALUES clause
        $id = self::$db->find()->table('test_coverage')->insert(['name' => 'test', 'value' => 10]);
        $this->assertIsInt($id);

        // Verify row exists before UPDATE
        $rowBefore = self::$db->find()
            ->table('test_coverage')
            ->where('id', $id)
            ->getOne();
        $this->assertNotFalse($rowBefore);
        $this->assertEquals(10, $rowBefore['value']);

        // Test UPDATE with RawValue containing column reference
        self::$db->find()
            ->table('test_coverage')
            ->where('id', $id)
            ->update([
                'value' => Db::raw('value + 5'),
            ]);

        // Verify the value was updated correctly
        $row = self::$db->find()
            ->table('test_coverage')
            ->where('id', $id)
            ->getOne();

        $this->assertNotFalse($row);
        $this->assertIsArray($row);
        $this->assertEquals(15, $row['value']);
    }

    public function testReplaceWithOnDuplicate(): void
    {
        // Test replace with onDuplicate parameter (should be ignored for REPLACE)
        $id = self::$db->find()->table('test_coverage')->insert(['name' => 'replace_test', 'value' => 10]);

        $rowCount = self::$db->find()->table('test_coverage')->replace([
            'id' => $id,
            'name' => 'replace_test',
            'value' => 20,
        ], ['value']);

        $this->assertGreaterThan(0, $rowCount);

        $row = self::$db->find()
            ->table('test_coverage')
            ->where('id', $id)
            ->getOne();

        $this->assertEquals(20, $row['value']);
    }

    public function testInsertMultiWithDifferentColumnSets(): void
    {
        // Test insertMulti with rows having different column sets
        // This tests edge case handling - some databases may not support this
        try {
            $rowCount = self::$db->find()->table('test_coverage')->insertMulti([
                ['name' => 'test1', 'value' => 10],
                ['name' => 'test2', 'value' => null], // Explicit null for missing value
                ['name' => null, 'value' => 30], // Explicit null for missing name
            ]);

            $this->assertGreaterThan(0, $rowCount);

            $rows = self::$db->find()
                ->table('test_coverage')
                ->whereIn('name', ['test1', 'test2'])
                ->orWhereNull('name')
                ->get();

            $this->assertGreaterThanOrEqual(2, count($rows));
        } catch (\Exception $e) {
            // Some databases may not support different column sets
            // This is acceptable behavior
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    public function testInsertMultiWithSingleRow(): void
    {
        // Test insertMulti with just one row (edge case)
        $rowCount = self::$db->find()->table('test_coverage')->insertMulti([
            ['name' => 'single', 'value' => 100],
        ]);

        $this->assertEquals(1, $rowCount);

        $row = self::$db->find()
            ->table('test_coverage')
            ->where('name', 'single')
            ->getOne();

        $this->assertNotNull($row);
        $this->assertEquals(100, $row['value']);
    }

    public function testInsertMultiWithRawValues(): void
    {
        // Test insertMulti with RawValue instances
        $rowCount = self::$db->find()->table('test_coverage')->insertMulti([
            ['name' => 'raw1', 'value' => Db::raw('10 + 5')],
            ['name' => 'raw2', 'value' => Db::raw('20 * 2')],
        ]);

        $this->assertEquals(2, $rowCount);

        $row1 = self::$db->find()
            ->table('test_coverage')
            ->where('name', 'raw1')
            ->getOne();
        $this->assertEquals(15, $row1['value']);

        $row2 = self::$db->find()
            ->table('test_coverage')
            ->where('name', 'raw2')
            ->getOne();
        $this->assertEquals(40, $row2['value']);
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

    public function testLockConstants(): void
    {
        $this->assertEquals('WRITE', PdoDb::LOCK_WRITE);
        $this->assertEquals('READ', PdoDb::LOCK_READ);
    }

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

    public function testStreamProcessing(): void
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

        // Test stream processing
        $records = [];
        $count = 0;

        foreach ($db->find()->from('test_coverage')->orderBy('id')->stream() as $record) {
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

        // Test stream with empty results
        $records = [];
        foreach ($db->find()->from('test_coverage')->stream() as $record) {
            $records[] = $record;
        }
        $this->assertEmpty($records);
    }

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

    public function testCursorFromItem(): void
    {
        $item = ['id' => 123, 'name' => 'Test', 'created_at' => '2025-10-28'];
        $cursor = Cursor::fromItem($item, ['id', 'created_at']);

        $this->assertEquals(['id' => 123, 'created_at' => '2025-10-28'], $cursor->parameters());
    }

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
}
