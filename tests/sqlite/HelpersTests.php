<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\helpers\Db;

/**
 * HelpersTests tests for sqlite.
 */
final class HelpersTests extends BaseSqliteTestCase
{
    public function testLikeAndIlikeHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'company' => 'WonderCorp', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'company' => 'wondercorp', 'age' => 35]);

        // LIKE
        $likeResults = $db->find()
        ->from('users')
        ->where(Db::like('company', 'Wonder%'))
        ->get();

        $this->assertCount(2, $likeResults); // Will match both 'WonderCorp' and 'wondercorp' in Sqlite
        $this->assertEquals('Alice', $likeResults[0]['name']);

        // ILIKE
        $ilikeResults = $db->find()
        ->from('users')
        ->where(Db::ilike('company', 'Wonder%'))
        ->get();

        $this->assertCount(2, $ilikeResults);
    }

    public function testNotHelper(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Charlie', 'company' => 'TechX', 'age' => 40]);
        $db->find()->table('users')->insert(['name' => 'Dana', 'company' => 'BizY', 'age' => 45]);

        // NOT LIKE 'Tech%'
        $notLike = Db::not(Db::like('company', 'Tech%'));

        $results = $db->find()
        ->from('users')
        ->where($notLike)
        ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Dana', $results[0]['name']);
    }

    public function testConfigHelper(): void
    {
        self::$db->rawQuery(Db::config('foreign_keys', 'OFF'));
        $this->assertEquals('PRAGMA FOREIGN_KEYS = OFF', self::$db->lastQuery);
    }

    public function testInAndNotInHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Dana', 'company' => 'X', 'age' => 40]);
        $db->find()->table('users')->insert(['name' => 'Eve', 'company' => 'Y', 'age' => 45]);
        $db->find()->table('users')->insert(['name' => 'Frank', 'company' => 'Z', 'age' => 50]);

        $inResults = $db->find()
        ->from('users')
        ->where(Db::in('name', ['Dana', 'Eve']))
        ->get();

        $this->assertCount(2, $inResults);

        $notInResults = $db->find()
        ->from('users')
        ->where(Db::not(Db::in('name', ['Dana', 'Eve'])))
        ->get();

        $this->assertCount(1, $notInResults);
        $this->assertEquals('Frank', $notInResults[0]['name']);

        $notInResults = $db->find()
        ->from('users')
        ->where(Db::notIn('name', ['Dana', 'Eve']))
        ->get();

        $this->assertCount(1, $notInResults);
        $this->assertEquals('Frank', $notInResults[0]['name']);
    }

    public function testIsNullIsNotNullHelpers(): void
    {
        $db = self::$db;

        $idNull = $db->find()->table('users')->insert([
        'name' => 'Grace',
        'company' => 'NullCorp',
        'age' => 33,
        'status' => Db::null(),
        ]);
        $this->assertIsInt($idNull);

        $idNotNull = $db->find()->table('users')->insert([
        'name' => 'Helen',
        'company' => 'LiveCorp',
        'age' => 35,
        'status' => 'active',
        ]);
        $this->assertIsInt($idNotNull);

        $nullResults = $db->find()
        ->from('users')
        ->where(Db::isNull('status'))
        ->get();

        $this->assertNotEmpty($nullResults);
        $this->assertEquals('Grace', $nullResults[0]['name']);

        $notNullResults = $db->find()
        ->from('users')
        ->where(Db::isNotNull('status'))
        ->get();

        $this->assertNotEmpty($notNullResults);
        $this->assertEquals('Helen', $notNullResults[0]['name']);
    }

    public function testDefaultHelper(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert([
        'name' => 'Nina',
        'company' => 'DefaultCorp',
        'age' => 31,
        'status' => 'active',
        ]);

        $db->find()
        ->table('users')
        ->where('id', $userId)
        ->update(['status' => Db::default()]);

        $result = $db->find()
        ->from('users')
        ->where('id', $userId)
        ->getOne();

        $this->assertNull($result['status']);
    }

    public function testCaseInSelect(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'company' => 'A', 'age' => 22]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'company' => 'B', 'age' => 35]);

        $case = Db::case([
        'age < 30' => "'young'",
        'age >= 30' => "'adult'",
        ]);

        $results = $db->find()
        ->select(['category' => $case])
        ->from('users')
        ->orderBy('age ASC')
        ->get();

        $this->assertStringContainsString("CASE WHEN age < 30 THEN 'young' WHEN age >= 30 THEN 'adult' END AS category", $db->lastQuery);

        $this->assertEquals('young', $results[0]['category']);
        $this->assertEquals('adult', $results[1]['category']);
    }

    public function testCaseInWhere(): void
    {
        $db = self::$db;

        // Insert users with different ages
        $db->find()->table('users')->insert(['name' => 'Charlie', 'company' => 'C', 'age' => 28]);
        $db->find()->table('users')->insert(['name' => 'Dana', 'company' => 'D', 'age' => 40]);
        $db->find()->table('users')->insert(['name' => 'Eve', 'company' => 'E', 'age' => null]); // does not match any WHEN

        // CASE with ELSE
        $case = Db::case([
        'age < 30' => '1',
        'age >= 30' => '0',
        ], '2'); // ELSE → 2

        // WHERE CASE = 2 should return Eve
        $results = $db->find()
        ->from('users')
        ->where($case, 2)
        ->get();

        $this->assertStringContainsString(
            'CASE WHEN age < 30 THEN 1 WHEN age >= 30 THEN 0 ELSE 2 END',
            $db->lastQuery
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Eve', $results[0]['name']);
    }

    public function testCaseInOrderBy(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Eve', 'company' => 'E', 'age' => 45]);
        $db->find()->table('users')->insert(['name' => 'Frank', 'company' => 'F', 'age' => 20]);

        $orderCase = Db::case([
        'age < 30' => '1',
        'age >= 30' => '2',
        ]);

        $results = $db->find()
        ->from('users')
        ->orderBy($orderCase, 'ASC')
        ->get();

        $this->assertStringContainsString('CASE WHEN age < 30 THEN 1 WHEN age >= 30 THEN 2', $db->lastQuery);

        $this->assertEquals('Frank', $results[0]['name']);
        $this->assertEquals('Eve', $results[1]['name']);
    }

    public function testCaseInHaving(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Gina', 'company' => 'G', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'Hank', 'company' => 'G', 'age' => 45]);

        $case = Db::case([
        'AVG(age) < 30' => '1',
        'AVG(age) >= 30' => '0',
        ]);

        $results = $db->find()
        ->select(['company', 'avg_age' => 'AVG(age)'])
        ->from('users')
        ->groupBy('company')
        ->having($case, 0)
        ->get();

        $this->assertStringContainsString('CASE WHEN AVG(age) < 30 THEN 1 WHEN AVG(age) >= 30 THEN 0', $db->lastQuery);

        $this->assertEquals('G', $results[0]['company']);
        $this->assertGreaterThanOrEqual(30, $results[0]['avg_age']);
    }

    public function testCaseInUpdate(): void
    {
        $db = self::$db;

        $id1 = $db->find()->table('users')->insert(['name' => 'Ivy', 'company' => 'U', 'age' => 18, 'status' => '']);
        $id2 = $db->find()->table('users')->insert(['name' => 'Jack', 'company' => 'U', 'age' => 50, 'status' => '']);

        $case = Db::case([
        'age < 30' => "'junior'",
        'age >= 30' => "'senior'",
        ]);

        $db->find()
        ->table('users')
        ->where('company', 'U')
        ->update(['status' => $case]);

        $this->assertStringContainsString("CASE WHEN age < 30 THEN 'junior' WHEN age >= 30 THEN 'senior'", $db->lastQuery);

        $user1 = $db->find()->from('users')->where('id', $id1)->getOne();
        $user2 = $db->find()->from('users')->where('id', $id2)->getOne();

        $this->assertEquals('junior', $user1['status']);
        $this->assertEquals('senior', $user2['status']);
    }

    public function testTrueAndFalseHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert([
        'name' => 'TrueUser',
        'company' => 'BoolCorp',
        'age' => 30,
        'is_active' => db::true(),
        ]);

        $db->find()->table('users')->insert([
        'name' => 'FalseUser',
        'company' => 'BoolCorp',
        'age' => 35,
        'is_active' => db::false(),
        ]);

        $trueResults = $db->find()
        ->from('users')
        ->where('is_active', Db::true())
        ->get();

        $this->assertCount(1, $trueResults);
        $this->assertEquals('TrueUser', $trueResults[0]['name']);

        $falseResults = $db->find()
        ->from('users')
        ->where('is_active', Db::false())
        ->get();

        $this->assertCount(1, $falseResults);
        $this->assertEquals('FalseUser', $falseResults[0]['name']);
    }

    public function testConcatHelper(): void
    {
        $db = self::$db;

        // prepare table rows
        $db->find()->table('users')->insert(['name' => 'John', 'company' => 'C1', 'age' => 30, 'status' => 'ok']);
        $db->find()->table('users')->insert(['name' => 'Anna', 'company' => 'C2', 'age' => 25, 'status' => 'ok']);

        // 1) column + literal (space) + column
        $results = $db->find()
        ->select(['full' => Db::concat('name', "' '", 'company')])
        ->from('users')
        ->where(['name' => 'John'])
        ->getOne();

        $this->assertEquals('John C1', $results['full']);

        // 2) RawValue + literal + column
        $raw = Db::raw('UPPER(name)');
        $results = $db->find()
        ->select(['x' => Db::concat($raw, "' - '", 'company')])
        ->from('users')
        ->where(['name' => 'Anna'])
        ->getOne();

        $this->assertEquals('ANNA - C2', $results['x']);

        // 3) numeric literal and column
        $results = $db->find()
        ->select(['s' => Db::concat('name', "' #'", 100)])
        ->from('users')
        ->where(['name' => 'John'])
        ->getOne();

        $this->assertEquals('John #100', $results['s']);

        // 4) single-part concat (should return the part as-is)
        $results = $db->find()
        ->select(['only' => Db::concat('name')])
        ->from('users')
        ->where(['name' => 'Anna'])
        ->getOne();

        $this->assertEquals('Anna', $results['only']);
    }

    public function testTsHelper(): void
    {
        $db = self::$db;

        $db->rawQuery('CREATE TABLE tmp_timestamps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts INTEGER NOT NULL,
    ts_diff INTEGER NOT NULL,
    ts_diff2 INTEGER NOT NULL
    );');

        // Insert using Db::now as timestamp: Db::now(null, true)
        $ts =  time();
        $id = $db->find()
        ->table('tmp_timestamps')
        ->insert([
        'ts' => Db::ts(),
        'ts_diff' => Db::ts('+1 DAY'),
        'ts_diff2' => Db::ts('-1 DAY'),
        ]);

        $this->assertIsInt($id);

        $row = $db->find()
        ->from('tmp_timestamps')
        ->where('id', $id)
        ->getOne();

        $this->assertGreaterThanOrEqual($ts, $row['ts']);
        $this->assertGreaterThanOrEqual($ts + 83600, $row['ts_diff']);
        $this->assertLessThanOrEqual($ts - 83600, $row['ts_diff2']);
    }

    public function testEscape(): void
    {
        $id = self::$db->find()
        ->table('users')
        ->insert([
        'name' => Db::escape("O'Reilly"),
        'age' => 30,
        ]);
        $this->assertIsInt($id);

        $row = self::$db->find()
        ->from('users')
        ->where('id', $id)
        ->getOne();
        $this->assertEquals("O'Reilly", $row['name']);
        $this->assertEquals(30, $row['age']);
    }

    public function testFuncNowIncDec(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert(['name' => 'FuncTest', 'age' => 1]);

        // inc
        $db->find()
        ->from('users')
        ->where('id', $id)
        ->update(['age' => Db::inc()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(2, $row['age']);

        // dec
        $db->find()
        ->from('users')
        ->where('id', $id)
        ->update(['age' => Db::dec()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(1, $row['age']);

        // now() into updated_at
        $db->find()
        ->from('users')
        ->where('id', $id)
        ->update(['updated_at' => Db::now()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertNotEmpty($row['updated_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['updated_at']);
        $this->assertNotEquals('0000-00-00 00:00:00', $row['updated_at']);

        // func MAX(age)
        $row = $db->find()->from('users')->select(['MAX(age) as max_age'])->getOne();
        $this->assertEquals(1, (int)$row['max_age']);
    }

    public function testNewDbHelpers(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_helpers');
        $db->rawQuery('CREATE TABLE t_helpers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER, created_at TEXT, description TEXT)');

        // Test ifNull, coalesce
        $id1 = $db->find()->table('t_helpers')->insert([
        'name' => 'Alice',
        'age' => 30,
        'created_at' => date('Y-m-d H:i:s'),
        'description' => null,
        ]);

        $row = $db->find()->table('t_helpers')
        ->select([
        'name',
        'description_text' => Db::ifNull('description', 'No description'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals('No description', $row['description_text']);

        // Test upper, lower
        $row = $db->find()->table('t_helpers')
        ->select([
        'upper' => Db::upper('name'),
        'lower' => Db::lower('name'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals('ALICE', $row['upper']);
        $this->assertEquals('alice', $row['lower']);

        // Test abs, round
        $id2 = $db->find()->table('t_helpers')->insert([
        'name' => 'Bob',
        'age' => -5,
        'created_at' => date('Y-m-d H:i:s'),
        ]);

        $row = $db->find()->table('t_helpers')
        ->select([
        'abs_age' => Db::abs('age'),
        ])
        ->where('id', $id2)
        ->getOne();
        $this->assertEquals(5, (int)$row['abs_age']);

        // Test mod
        $row = $db->find()->table('t_helpers')
        ->select([
        'mod_result' => Db::mod('age', '3'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals(0, (int)$row['mod_result']);

        // Test greatest, least
        $row = $db->find()->table('t_helpers')
        ->select([
        'max_val' => Db::greatest('10', '5', '20'),
        'min_val' => Db::least('10', '5', '20'),
        ])
        ->getOne();
        $this->assertEquals(20, (int)$row['max_val']);
        $this->assertEquals(5, (int)$row['min_val']);

        // Test substring
        $row = $db->find()->table('t_helpers')
        ->select([
        'substr' => Db::substring('name', 1, 3),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals('Ali', $row['substr']);

        // Test length
        $row = $db->find()->table('t_helpers')
        ->select([
        'len' => Db::length('name'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals(5, (int)$row['len']);

        // Test trim, ltrim, rtrim
        $id3 = $db->find()->table('t_helpers')->insert([
        'name' => '  Charlie  ',
        'age' => 25,
        'created_at' => date('Y-m-d H:i:s'),
        ]);

        $row = $db->find()->table('t_helpers')
        ->select([
        'trimmed' => Db::trim('name'),
        ])
        ->where('id', $id3)
        ->getOne();
        $this->assertEquals('Charlie', $row['trimmed']);

        // Test curDate, curTime
        $row = $db->find()->table('t_helpers')
        ->select([
        'cur_date' => Db::curDate(),
        'cur_time' => Db::curTime(),
        ])
        ->getOne();
        $this->assertNotEmpty($row['cur_date']);
        $this->assertNotEmpty($row['cur_time']);

        // Test year, month, day, hour, minute, second
        $row = $db->find()->table('t_helpers')
        ->select([
        'year' => Db::year('created_at'),
        'month' => Db::month('created_at'),
        'day' => Db::day('created_at'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals((int)date('Y'), (int)$row['year']);
        $this->assertEquals((int)date('m'), (int)$row['month']);
        $this->assertEquals((int)date('d'), (int)$row['day']);

        // Test count, sum, avg, min, max
        $row = $db->find()->table('t_helpers')
        ->select([
        'count' => Db::count(),
        'sum_age' => Db::sum('age'),
        'avg_age' => Db::avg('age'),
        'min_age' => Db::min('age'),
        'max_age' => Db::max('age'),
        ])
        ->getOne();
        $this->assertEquals(3, (int)$row['count']);
        $this->assertEquals(50, (int)$row['sum_age']); // 30 + (-5) + 25
        $this->assertGreaterThan(0, (float)$row['avg_age']);

        // Test cast
        $row = $db->find()->table('t_helpers')
        ->select([
        'age_str' => Db::cast('age', 'TEXT'),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertIsString($row['age_str']);

        // Test concat
        $row = $db->find()->table('t_helpers')
        ->select([
        'full_info' => Db::concat('name', Db::raw("' - '"), Db::cast('age', 'TEXT')),
        ])
        ->where('id', $id1)
        ->getOne();
        $this->assertStringContainsString('Alice', $row['full_info']);
        $this->assertStringContainsString('30', $row['full_info']);
    }

    public function testNewDbHelpersEdgeCases(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_edge');
        $db->rawQuery('CREATE TABLE t_edge (id INTEGER PRIMARY KEY AUTOINCREMENT, val1 TEXT, val2 TEXT, num1 INTEGER, num2 REAL, dt TEXT)');

        // Test replace() function - not tested before
        $id1 = $db->find()->table('t_edge')->insert(['val1' => 'Hello World']);
        $row = $db->find()->table('t_edge')
        ->select(['replaced' => Db::replace('val1', 'World', 'SQLite')])
        ->where('id', $id1)
        ->getOne();
        $this->assertEquals('Hello SQLite', $row['replaced']);

        // Test date() and time() extraction functions
        $id2 = $db->find()->table('t_edge')->insert(['dt' => '2025-10-19 14:30:45']);
        $row = $db->find()->table('t_edge')
        ->select([
        'date_part' => Db::date('dt'),
        'time_part' => Db::time('dt'),
        ])
        ->where('id', $id2)
        ->getOne();
        $this->assertEquals('2025-10-19', $row['date_part']);
        $this->assertEquals('14:30:45', $row['time_part']);

        // Test nullIf - should return NULL when values are equal
        $row = $db->find()->table('t_edge')
        ->select(['null_result' => Db::nullIf('5', '5')])
        ->getOne();
        $this->assertNull($row['null_result']);

        // Test coalesce with all NULLs
        $id3 = $db->find()->table('t_edge')->insert(['val1' => null, 'val2' => null]);
        $row = $db->find()->table('t_edge')
        ->select(['result' => Db::coalesce('val1', 'val2', Db::raw("'fallback'"))])
        ->where('id', $id3)
        ->getOne();
        $this->assertEquals('fallback', $row['result']);

        // Test round() with different precision
        $id4 = $db->find()->table('t_edge')->insert(['num2' => 3.14159]);
        $row = $db->find()->table('t_edge')
        ->select([
        'round0' => Db::round('num2'),
        'round2' => Db::round('num2', 2),
        'round4' => Db::round('num2', 4),
        ])
        ->where('id', $id4)
        ->getOne();
        $this->assertEquals(3, (int)$row['round0']);
        $this->assertEquals(3.14, (float)$row['round2']);
        $this->assertEquals(3.1416, (float)$row['round4']);

        // Test round() with negative numbers
        $id5 = $db->find()->table('t_edge')->insert(['num2' => -3.7]);
        $row = $db->find()->table('t_edge')
        ->select(['rounded' => Db::round('num2')])
        ->where('id', $id5)
        ->getOne();
        $this->assertEquals(-4, (int)$row['rounded']);

        // Test mod() with negative numbers
        $row = $db->find()->table('t_edge')
        ->select(['mod_result' => Db::mod('-10', '3')])
        ->getOne();
        $this->assertEquals(-1, (int)$row['mod_result']);

        // Test substring() with edge cases
        $id6 = $db->find()->table('t_edge')->insert(['val1' => 'Hello']);
        // Index beyond string length
        $row = $db->find()->table('t_edge')
        ->select(['substr_beyond' => Db::substring('val1', 10, 5)])
        ->where('id', $id6)
        ->getOne();
        $this->assertEquals('', $row['substr_beyond']);

        // Test length() with empty string
        $id7 = $db->find()->table('t_edge')->insert(['val1' => '']);
        $row = $db->find()->table('t_edge')
        ->select(['len' => Db::length('val1')])
        ->where('id', $id7)
        ->getOne();
        $this->assertEquals(0, (int)$row['len']);

        // Test upper/lower with UTF-8
        $id8 = $db->find()->table('t_edge')->insert(['val1' => 'Hello 🎉']);
        $row = $db->find()->table('t_edge')
        ->select([
        'upper_utf8' => Db::upper('val1'),
        'lower_utf8' => Db::lower('val1'),
        ])
        ->where('id', $id8)
        ->getOne();
        $this->assertStringContainsString('🎉', $row['upper_utf8']);
        $this->assertStringContainsString('🎉', $row['lower_utf8']);

        // Test concat() with NULL values (using ifNull)
        $id9 = $db->find()->table('t_edge')->insert(['val1' => 'Start', 'val2' => null]);
        $row = $db->find()->table('t_edge')
        ->select([
        'val_or_default' => Db::ifNull('val2', 'DefaultValue'),
        'concat_result' => Db::concat('val1', Db::raw("' - '"), Db::raw("'End'")),
        ])
        ->where('id', $id9)
        ->getOne();
        $this->assertEquals('DefaultValue', $row['val_or_default']);
        $this->assertStringContainsString('Start', $row['concat_result']);
        $this->assertStringContainsString('End', $row['concat_result']);

        // Test cast() in WHERE
        $id10 = $db->find()->table('t_edge')->insert(['val1' => '123']);
        $found = $db->find()->table('t_edge')
        ->where(Db::cast('val1', 'INTEGER'), 123)
        ->where('id', $id10)
        ->exists();
        $this->assertTrue($found);

        // Test cast() in ORDER BY
        $db->find()->table('t_edge')->insert(['val1' => '10']);
        $db->find()->table('t_edge')->insert(['val1' => '2']);
        $db->find()->table('t_edge')->insert(['val1' => '100']);

        $results = $db->find()->table('t_edge')
        ->select(['val1'])
        ->where('val1', ['2', '10', '100'], 'IN')
        ->orderBy(Db::cast('val1', 'INTEGER'), 'ASC')
        ->getColumn();
        $this->assertEquals(['2', '10', '100'], $results);

        // Test multiple helpers in same query (not nested)
        $id11 = $db->find()->table('t_edge')->insert(['val1' => 'hello world']);
        $row = $db->find()->table('t_edge')
        ->select([
        'upper_val' => Db::upper('val1'),
        'substr_val' => Db::substring('val1', 1, 5),
        ])
        ->where('id', $id11)
        ->getOne();
        $this->assertEquals('HELLO WORLD', $row['upper_val']);
        $this->assertEquals('hello', $row['substr_val']);

        // Test greatest/least in WHERE
        $id12 = $db->find()->table('t_edge')->insert(['num1' => 10, 'num2' => 20.5]);
        $found = $db->find()->table('t_edge')
        ->where(Db::greatest('num1', 'num2'), 15, '>')
        ->where('id', $id12)
        ->exists();
        $this->assertTrue($found);

        // Test ORDER BY with ifNull
        $db->find()->table('t_edge')->insert(['val1' => null, 'num1' => 1]);
        $db->find()->table('t_edge')->insert(['val1' => 'B', 'num1' => 2]);
        $db->find()->table('t_edge')->insert(['val1' => 'A', 'num1' => 3]);

        $results = $db->find()->table('t_edge')
        ->select(['num1'])
        ->where('num1', [1, 2, 3], 'IN')
        ->orderBy(Db::ifNull('val1', Db::raw("'ZZZ'")), 'ASC')
        ->getColumn();
        $this->assertEquals([3, 2, 1], $results); // A, B, NULL(->ZZZ)

        // Test year/month/day with hour/minute/second
        $dt = '2025-10-19 14:30:45';
        $id13 = $db->find()->table('t_edge')->insert(['dt' => $dt]);
        $row = $db->find()->table('t_edge')
        ->select([
        'h' => Db::hour('dt'),
        'm' => Db::minute('dt'),
        's' => Db::second('dt'),
        ])
        ->where('id', $id13)
        ->getOne();
        $this->assertEquals(14, (int)$row['h']);
        $this->assertEquals(30, (int)$row['m']);
        $this->assertEquals(45, (int)$row['s']);

        // Test curDate/curTime in SELECT
        $row = $db->find()->table('t_edge')
        ->select([
        'today' => Db::curDate(),
        'now_time' => Db::curTime(),
        ])
        ->getOne();
        $this->assertNotEmpty($row['today']);
        $this->assertNotEmpty($row['now_time']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['today']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $row['now_time']);
    }

    public function testRegexpHelpers(): void
    {
        $db = self::$db;

        // REGEXP functions are automatically registered by ConnectionFactory
        // No need to check availability - they should be available

        // Create test table
        $db->rawQuery('DROP TABLE IF EXISTS t_regexp');
        $db->rawQuery('CREATE TABLE t_regexp (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(255), phone VARCHAR(50))');

        // Insert test data
        $id1 = $db->find()->table('t_regexp')->insert(['email' => 'user@example.com', 'phone' => '+1-555-123-4567']);
        $id2 = $db->find()->table('t_regexp')->insert(['email' => 'admin@test.org', 'phone' => '+44-20-7946-0958']);
        $id3 = $db->find()->table('t_regexp')->insert(['email' => 'invalid-email', 'phone' => '12345']);

        // Test regexpMatch - check if email matches pattern
        $results = $db->find()
            ->from('t_regexp')
            ->where(Db::regexpMatch('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$'))
            ->get();
        $this->assertCount(2, $results);
        $this->assertEquals('user@example.com', $results[0]['email']);
        $this->assertEquals('admin@test.org', $results[1]['email']);

        // Test regexpReplace - replace dashes with spaces in phone
        // Note: regexp_replace requires REGEXP extension with regexp_replace function
        // If not available, the query will fail with PDOException
        $row = $db->find()
            ->from('t_regexp')
            ->select(['phone_formatted' => Db::regexpReplace('phone', '-', ' ')])
            ->where('id', $id1)
            ->getOne();
        $this->assertStringContainsString(' ', $row['phone_formatted']);
        $this->assertStringNotContainsString('-', $row['phone_formatted']);

        // Test regexpExtract - extract domain from email (full match without capture groups)
        $row = $db->find()
            ->from('t_regexp')
            ->select(['domain_match' => Db::regexpExtract('email', '@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}')])
            ->where('id', $id1)
            ->getOne();
        // regexp_extract returns the matched substring (full match)
        $this->assertStringContainsString('@example.com', $row['domain_match']);

        // Test regexpExtract - extract domain from email with capture group
        $row = $db->find()
            ->from('t_regexp')
            ->select(['domain' => Db::regexpExtract('email', '@([a-zA-Z0-9.-]+\\.[a-zA-Z]{2,})', 1)])
            ->where('id', $id1)
            ->getOne();
        $this->assertNotNull($row['domain']);
        $this->assertEquals('example.com', $row['domain']);

        // Test regexpExtract with full match (groupIndex = 0 or null)
        $row = $db->find()
            ->from('t_regexp')
            ->select(['full_match' => Db::regexpExtract('email', '^[a-zA-Z0-9._%+-]+@')])
            ->where('id', $id1)
            ->getOne();
        $this->assertStringContainsString('user@', $row['full_match']);

        // Test regexpMatch in WHERE clause with negation
        $results = $db->find()
            ->from('t_regexp')
            ->where(Db::not(Db::regexpMatch('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$')))
            ->get();
        $this->assertCount(1, $results);
        $this->assertEquals('invalid-email', $results[0]['email']);
    }
}
