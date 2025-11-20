<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * HelpersTests tests for mssql.
 */
final class HelpersTests extends BaseMSSQLTestCase
{
    public function testLikeAndIlikeHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'company' => 'WonderCorp', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'company' => 'wondercorp', 'age' => 35]);

        // LIKE - MSSQL is case-insensitive by default collation
        $likeResults = $db->find()
            ->from('users')
            ->where(Db::like('company', 'Wonder%'))
            ->get();

        $this->assertCount(2, $likeResults);
        $this->assertEquals('Alice', $likeResults[0]['name']);

        // ILIKE - MSSQL uses COLLATE for case-insensitive
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

        $lastQuery = $db->lastQuery ?? '';
        // MSSQL quotes aliases with square brackets
        $this->assertStringContainsString(
            "CASE WHEN age < 30 THEN 'young' WHEN age >= 30 THEN 'adult' END",
            $lastQuery
        );
        $this->assertStringContainsString('[category]', $lastQuery);

        $this->assertEquals('young', $results[0]['category']);
        $this->assertEquals('adult', $results[1]['category']);
    }

    public function testCaseInWhere(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Charlie', 'company' => 'C', 'age' => 28]);
        $db->find()->table('users')->insert(['name' => 'Dana', 'company' => 'D', 'age' => 40]);
        $db->find()->table('users')->insert([
            'name' => 'Eve',
            'company' => 'E',
            'age' => null,
        ]);

        $case = Db::case([
            'age < 30' => '1',
            'age >= 30' => '0',
        ], '2');

        $results = $db->find()
            ->from('users')
            ->where($case, 2)
            ->get();

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString(
            'CASE WHEN age < 30 THEN 1 WHEN age >= 30 THEN 0 ELSE 2 END',
            $lastQuery
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

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('CASE WHEN age < 30 THEN 1 WHEN age >= 30 THEN 2', $lastQuery);

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

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('CASE WHEN AVG(age) < 30 THEN 1 WHEN AVG(age) >= 30 THEN 0', $lastQuery);

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

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString(
            "CASE WHEN age < 30 THEN 'junior' WHEN age >= 30 THEN 'senior'",
            $lastQuery
        );

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
            'is_active' => 1,
        ]);

        $db->find()->table('users')->insert([
            'name' => 'FalseUser',
            'company' => 'BoolCorp',
            'age' => 35,
            'is_active' => 0,
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

        $db->find()->table('users')->insert(['name' => 'John', 'company' => 'C1', 'age' => 30, 'status' => 'ok']);
        $db->find()->table('users')->insert(['name' => 'Anna', 'company' => 'C2', 'age' => 25, 'status' => 'ok']);

        // Get actual IDs from database
        $connection = $db->connection;
        assert($connection !== null);
        $lastInsertId = $connection->getLastInsertId();
        $this->assertNotFalse($lastInsertId);
        $checkRow = $db->rawQueryOne('SELECT * FROM [users] WHERE [id] = ?', [$lastInsertId]);
        $this->assertNotFalse($checkRow);
        $actualId = $checkRow['id'] ?? $lastInsertId;

        // 1) column + literal (space) + column - MSSQL uses + for concatenation
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

        // Verify MSSQL uses + for concatenation (check previous query, not single-part concat)
        $lastQuery = $db->lastQuery ?? '';
        // Single-part concat doesn't use +, so check a multi-part concat query instead
        $multiPartQuery = $db->find()
            ->select(['test' => Db::concat('name', "' - '", 'company')])
            ->from('users')
            ->where(['name' => 'John'])
            ->toSQL();
        $this->assertStringContainsString('+', $multiPartQuery['sql']);
    }

    public function testTsHelper(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        $connection->query('IF OBJECT_ID(\'tmp_timestamps\', \'U\') IS NOT NULL DROP TABLE tmp_timestamps');
        $connection->query('CREATE TABLE tmp_timestamps (
            id INT IDENTITY(1,1) PRIMARY KEY,
            ts INT NOT NULL,
            ts_diff INT NOT NULL,
            ts_diff2 INT NOT NULL
        )');

        $ts = time();
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

        $now = time();

        // Allow up to 1 second difference between time() calls
        $this->assertGreaterThanOrEqual($ts - 1, $row['ts']);
        $this->assertLessThanOrEqual($now + 1, $row['ts']);
        $this->assertGreaterThanOrEqual($now + 83600 - 1, $row['ts_diff']);
        $this->assertLessThanOrEqual($now - 83600 + 1, $row['ts_diff2']);
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

        // now() into updated_at - MSSQL uses GETDATE()
        $db->find()
            ->from('users')
            ->where('id', $id)
            ->update(['updated_at' => Db::now()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertNotEmpty($row['updated_at']);
        $this->assertNotEquals('1900-01-01 00:00:00.000', $row['updated_at']);

        // func MAX(age)
        $row = $db->find()->from('users')->select(['MAX(age) as max_age'])->getOne();
        $this->assertEquals(1, (int)$row['max_age']);
    }

    public function testNewDbHelpers(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        $connection->query('IF OBJECT_ID(\'t_helpers\', \'U\') IS NOT NULL DROP TABLE t_helpers');
        $connection->query('CREATE TABLE t_helpers (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(255),
            age INT,
            created_at DATETIME2,
            description NVARCHAR(MAX)
        )');

        // Test ifNull - MSSQL uses ISNULL
        $id1 = $db->find()->table('t_helpers')->insert([
            'name' => 'Alice',
            'age' => 30,
            'created_at' => Db::now(),
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
            'created_at' => Db::now(),
        ]);

        $row = $db->find()->table('t_helpers')
            ->select([
                'abs_age' => Db::abs('age'),
            ])
            ->where('id', $id2)
            ->getOne();
        $this->assertEquals(5, (int)$row['abs_age']);

        // Test mod - MSSQL uses % operator
        $row = $db->find()->table('t_helpers')
            ->select([
                'mod_result' => Db::mod('age', '3'),
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals(0, (int)$row['mod_result']);

        // Verify MSSQL uses % for MOD
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('%', $lastQuery);

        // Test greatest, least
        $row = $db->find()->table('t_helpers')
            ->select([
                'max_val' => Db::greatest('10', '5', '20'),
                'min_val' => Db::least('10', '5', '20'),
            ])
            ->getOne();
        $this->assertEquals(20, (int)$row['max_val']);
        $this->assertEquals(5, (int)$row['min_val']);

        // Test substring - MSSQL uses 1-based indexing
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
            'created_at' => Db::now(),
        ]);

        $row = $db->find()->table('t_helpers')
            ->select([
                'trimmed' => Db::trim('name'),
            ])
            ->where('id', $id3)
            ->getOne();
        $this->assertEquals('Charlie', $row['trimmed']);

        // Test curDate, curTime - MSSQL uses CAST(GETDATE() AS DATE/TIME)
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
        $this->assertEquals(50, (int)$row['sum_age']);
        $this->assertGreaterThan(0, (float)$row['avg_age']);

        // Test cast
        $row = $db->find()->table('t_helpers')
            ->select([
                'age_str' => Db::cast('age', 'NVARCHAR'),
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertIsString($row['age_str']);

        // Test concat
        $row = $db->find()->table('t_helpers')
            ->select([
                'full_info' => Db::concat('name', Db::raw("' - '"), Db::cast('age', 'NVARCHAR')),
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertStringContainsString('Alice', $row['full_info']);
        $this->assertStringContainsString('30', $row['full_info']);
    }

    public function testRegexpHelpers(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        $connection->query('IF OBJECT_ID(\'t_regexp\', \'U\') IS NOT NULL DROP TABLE t_regexp');
        $connection->query('CREATE TABLE t_regexp (
            id INT IDENTITY(1,1) PRIMARY KEY,
            email NVARCHAR(255),
            phone NVARCHAR(50)
        )');

        $id1 = $db->find()->table('t_regexp')->insert(['email' => 'user@example.com', 'phone' => '+1-555-123-4567']);
        $id2 = $db->find()->table('t_regexp')->insert(['email' => 'admin@test.org', 'phone' => '+44-20-7946-0958']);
        $id3 = $db->find()->table('t_regexp')->insert(['email' => 'invalid-email', 'phone' => '12345']);

        // Test regexpMatch - find emails containing '@'
        $results = $db->find()
            ->from('t_regexp')
            ->where(Db::regexpMatch('email', '@'))
            ->get();

        $this->assertCount(2, $results);
        $emails = array_column($results, 'email');
        $this->assertContains('user@example.com', $emails);
        $this->assertContains('admin@test.org', $emails);
        $this->assertNotContains('invalid-email', $emails);

        // Test regexpReplace - remove dashes from phone
        $result = $db->find()
            ->from('t_regexp')
            ->where('id', $id1)
            ->select(['clean_phone' => Db::regexpReplace('phone', '-', '')])
            ->getOne();

        $this->assertNotFalse($result);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('clean_phone', $result);
        // Phone should not contain dashes after replacement
        $this->assertStringNotContainsString('-', $result['clean_phone']);

        // Test regexpExtract - extract part after '@' in email
        $result = $db->find()
            ->from('t_regexp')
            ->where('id', $id1)
            ->select(['domain' => Db::regexpExtract('email', '@')])
            ->getOne();

        $this->assertNotFalse($result);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('domain', $result);
        // Should extract something (basic extraction)
        $this->assertNotEmpty($result['domain']);
    }
}
