<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\helpers\Db;

/**
 * QueryBuilderTests tests for mariadb.
 */
final class QueryBuilderTests extends BaseMariaDBTestCase
{
    public function testSelectWithQueryOption(): void
    {
        $db = self::$db;

        $id = $db->find()
        ->table('users')
        ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertEquals(1, $id);

        $rows = $db->find()
        ->from('users')
        ->option('SQL_NO_CACHE')
        ->get();
        $this->assertCount(1, $rows);

        $row = $db->find()
        ->from('users')
        ->option('SQL_NO_CACHE')
        ->getOne();
        $this->assertIsArray($row);
        $this->assertEquals('Test', $row['name']);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringStartsWith('SELECT SQL_NO_CACHE * FROM `users`', $lastQuery);
    }

    public function testSelectWithForUpdate(): void
    {
        $db = self::$db;

        $id = $db->find()
        ->table('users')
        ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertEquals(1, $id);

        $rows = $db->find()
        ->table('users')
        ->option('FOR UPDATE')
        ->get();
        $this->assertCount(1, $rows);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringEndsWith('FROM `users` FOR UPDATE', $lastQuery);
    }

    public function testLimitAndOffset(): void
    {
        $db = self::$db;

        $names = ['Alice', 'Bob', 'Charlie', 'Dana', 'Eve'];
        foreach ($names as $i => $name) {
            $db->find()->table('users')->insert([
            'name' => $name,
            'company' => 'TestCorp',
            'age' => 20 + $i,
            ]);
        }

        // first two records (limit = 2)
        $firstTwo = $db->find()
        ->from('users')
        ->orderBy('id', 'ASC')
        ->limit(2)
        ->get();

        $this->assertCount(2, $firstTwo);
        $this->assertEquals('Alice', $firstTwo[0]['name']);
        $this->assertEquals('Bob', $firstTwo[1]['name']);

        // (offset = 2)
        $nextTwo = $db->find()
        ->from('users')
        ->orderBy('id', 'ASC')
        ->limit(2)
        ->offset(2)
        ->get();

        $this->assertCount(2, $nextTwo);
        $this->assertEquals('Charlie', $nextTwo[0]['name']);
        $this->assertEquals('Dana', $nextTwo[1]['name']);
    }

    public function testOrWhere(): void
    {
        $rowCount = self::$db->find()
        ->table('users')
        ->insertMulti([
        ['name' => 'A', 'age' => 10],
        ['name' => 'B', 'age' => 20],
        ]);
        $this->assertEquals(2, $rowCount);

        $rows = self::$db->find()
        ->from('users')
        ->where('age', 10)
        ->orWhere('age', 20)
        ->get();
        $this->assertCount(2, $rows);

        $rows = self::$db->find()
        ->from('users')
        ->where('age', 10)
        ->orWhere('age', 30)
        ->get();
        $this->assertCount(1, $rows);
    }

    public function testAndWhere(): void
    {
        $rowCount = self::$db->find()
        ->table('users')
        ->insertMulti([
        ['name' => 'A', 'age' => 10],
        ['name' => 'B', 'age' => 20],
        ]);
        $this->assertEquals(2, $rowCount);

        $rows = self::$db->find()
        ->from('users')
        ->where('age', 10)
        ->andWhere('name', 'A')
        ->get();
        $this->assertCount(1, $rows);

        $rows = self::$db->find()
        ->from('users')
        ->where('age', 10)
        ->andWhere('name', 'B')
        ->get();
        $this->assertCount(0, $rows);
    }

    public function testInnerJoinAndWhere(): void
    {
        $db = self::$db;

        $uid = $db->find()
        ->table('users')
        ->insert(['name' => 'JoinUser', 'age' => 40]);
        $this->assertEquals(1, $uid);

        self::$db->find()
        ->table('orders')
        ->insert(['user_id' => $uid, 'amount' => 100]);

        $rows = self::$db
        ->find()
        ->from('users u')
        ->innerJoin('orders o', 'u.id = o.user_id')
        ->where('o.amount', 100)
        ->andWhere('o.amount', [100, 200], 'IN')
        ->get();

        $this->assertEquals('JoinUser', $rows[0]['name']);
    }

    public function testComplexWhereAndOrWhere(): void
    {
        $rowCount = self::$db->find()
        ->table('users')
        ->insertMulti([
        ['name' => 'UserA', 'age' => 20],
        ['name' => 'UserB', 'age' => 30],
        ['name' => 'UserC', 'age' => 40],
        ['name' => 'UserD', 'age' => 50],
        ]);
        $this->assertEquals(4, $rowCount);

        // Build query with where + multiple orWhere
        $ages = self::$db
        ->find()
        ->from('users')
        ->select(['age'])
        ->where('age', 20)
        ->orWhere('age', 30)
        ->orWhere('age', 40)
        ->getColumn();

        // Assert that only expected ages are returned
        sort($ages);
        $this->assertEquals([20, 30, 40], $ages);
    }

    public function testGetColumn(): void
    {
        $db = self::$db;

        // prepare data
        $db->find()->table('users')->insert(['id' => 1, 'name' => 'Alice', 'age' => 30]);
        $db->find()->table('users')->insert(['id' => 2, 'name' => 'Bob', 'age' => 25]);

        // 1) simple field
        $columns = $db->find()->from('users')->select(['id'])->getColumn();
        $this->assertIsArray($columns);
        $this->assertCount(2, $columns);
        $this->assertSame([1, 2], array_values($columns));

        // 2) alias support (select expression with alias)
        $columns = $db->find()
        ->from('users')
        ->select(['name AS username'])
        ->getColumn();
        $this->assertIsArray($columns);
        $this->assertCount(2, $columns);
        $this->assertSame(['Alice', 'Bob'], array_values($columns));

        // 3) raw value with alias (must provide alias to be addressable)
        $columns = $db->find()
        ->from('users')
        ->select([Db::raw('CONCAT(name, "_", age) AS name_age')])
        ->getColumn();
        $this->assertIsArray($columns);
        $this->assertCount(2, $columns);
        $this->assertSame(['Alice_30', 'Bob_25'], array_values($columns));
    }

    public function testGetValue(): void
    {
        $db = self::$db;

        // prepare data
        $db->find()->table('users')->insert(['id' => 10, 'name' => 'Carol', 'age' => 40]);

        // 1) simple field -> returns single value from first row
        $val = $db->find()->from('users')->select(['id'])->getValue();
        $this->assertNotFalse($val);
        $this->assertSame(10, $val);

        // 2) alias support
        $val = $db->find()->from('users')->select(['name AS username'])->getValue();
        $this->assertNotFalse($val);
        $this->assertSame('Carol', $val);

        // 3) raw value with alias
        $val = $db->find()
        ->from('users')
        ->select([Db::raw('CONCAT(name, "-", age) AS n_age')])
        ->getValue();
        $this->assertNotFalse($val);
        $this->assertSame('Carol-40', $val);
    }

    public function testGetAsObject(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Olya', 'age' => 22]);

        // objectBuilder for multiple rows
        $rows = $db->find()
        ->from('users')
        ->asObject()
        ->get();
        $this->assertIsObject($rows[0]);
        assert(property_exists($rows[0], 'age'));
        $this->assertEquals(22, $rows[0]->age);

        // objectBuilder for single row
        $row = $db->find()
        ->from('users')
        ->asObject()
        ->getOne();
        $this->assertIsObject($row);
        assert(property_exists($row, 'age'));
        $this->assertEquals(22, $row->age);
    }

    public function testWhereSyntax(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'D', 'age' => 50]);

        $row = $db->find()->from('users')->where(['age' => 25])->getOne();
        $this->assertEquals('B', $row['name']);

        $row = $db->find()->from('users')->where(['age' => ':age'], ['age' => 30])->getOne();
        $this->assertEquals('C', $row['name']);

        $row = $db->find()->from('users')->where('age = 50')->getOne();
        $this->assertEquals('D', $row['name']);

        $row = $db->find()->from('users')->where(Db::between('age', 10, 20))->getOne();
        $this->assertEquals('A', $row['name']);

        $row = $db->find()->from('users')->where('age', [25, 29], 'BETWEEN')->getOne();
        $this->assertEquals('B', $row['name']);
    }

    public function testWhereBetweenAndNotBetween(): void
    {
        $db = self::$db;

        // Prepare data
        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'D', 'age' => 50]);

        // BETWEEN
        $ages = $db->find()
        ->from('users')
        ->select('age')
        ->where('age', [20, 40], 'BETWEEN')
        ->orderBy('age', 'ASC')
        ->getColumn();
        $this->assertEquals([25, 30], $ages);

        // NOT BETWEEN
        $ages = $db->find()
        ->from('users')
        ->select('age')
        ->where('age', [20, 40], 'NOT BETWEEN')
        ->orderBy('age', 'ASC')
        ->getColumn();
        $this->assertEquals([10, 50], $ages);
    }

    public function testBetweenHelper(): void
    {
        $db = self::$db;

        // Prepare data
        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'D', 'age' => 50]);

        // BETWEEN
        $ages = $db->find()
        ->from('users')
        ->select('age')
        ->where(Db::between('age', 20, 40))
        ->orderBy('age', 'ASC')
        ->getColumn();
        $this->assertEquals([25, 30], $ages);

        // NOT BETWEEN
        $ages = $db->find()
        ->from('users')
        ->select('age')
        ->where(Db::notBetween('age', 20, 40))
        ->orderBy('age', 'ASC')
        ->getColumn();
        $this->assertEquals([10, 50], $ages);
    }

    public function testWhereInAndNotIn(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'E', 'age' => 60]);
        $db->find()->table('users')->insert(['name' => 'F', 'age' => 70]);

        // IN
        $rows = $db->find()
        ->from('users')
        ->where('age', [60, 70], 'IN')
        ->get();
        $ages = array_column($rows, 'age');
        sort($ages);
        $this->assertEquals([60, 70], $ages);

        // NOT IN
        $rows = $db->find()
        ->from('users')
        ->where('age', [60, 70], 'NOT IN')
        ->get();
        $ages = array_column($rows, 'age');
        $this->assertNotContains(60, $ages);
        $this->assertNotContains(70, $ages);
    }

    public function testWhereInWithEmptyArray(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'EmptyIn', 'age' => 99]);

        $rows = $db->find()
        ->from('users')
        ->where('age', [], 'IN')
        ->get();

        $this->assertCount(0, $rows, 'IN [] must return no rows');
    }

    public function testWhereNotInWithEmptyArray(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert(['name' => 'EmptyNotIn', 'age' => 100]);

        $rows = $db->find()
        ->from('users')
        ->where('id', [], 'NOT IN')
        ->get();
        $ids = array_column($rows, 'id');

        $this->assertContains($id, $ids, 'NOT IN [] must not filter out any rows');
    }

    public function testWhereStateIsResetBetweenQueries(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['id' => 1, 'name' => 'Alice']);
        $db->find()->table('users')->insert(['id' => 2, 'name' => 'Bob']);

        $row = $db->find()
        ->from('users')
        ->where('id', 1)
        ->getOne();
        $this->assertEquals('Alice', $row['name']);

        $rows = $db->find()
        ->from('users')
        ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereInAndRawValueNotBound(): void
    {
        $db = self::$db;

        // prepare table
        $id1 = $db->find()->table('users')->insert(['name' => 'where_raw_1', 'age' => 1]);
        $id2 = $db->find()->table('users')->insert(['name' => 'where_raw_2', 'age' => 3]);

        // Use RawValue in condition so RHS is inlined and not bound as a parameter
        $rows = $db->find()
        ->from('users')
        ->where('id', [$id1, $id2], 'IN')
        ->where('age', Db::raw('2 + 1'), '=')
        ->get();

        $ids = array_column($rows, 'id');
        $this->assertContains($id2, $ids);
    }

    public function testWhereInNamedPlaceholdersConflict(): void
    {
        $db = self::$db;

        // Insert test data
        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30, 'status' => 'inactive']);
        $db->find()->table('users')->insert(['name' => 'Charlie', 'age' => 35, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'David', 'age' => 40, 'status' => 'pending']);

        // Test multiple whereIn calls with different columns - this should work without conflicts
        $rows = $db->find()
        ->from('users')
        ->where('status', ['active'], 'IN')  // First whereIn with status
        ->where('age', [25, 35], 'IN')       // Second whereIn with age - different column
        ->get();

        // Should return Alice (active, age 25) and Charlie (active, age 35)
        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);

        // Test with OR logic using orWhere
        $rows = $db->find()
        ->from('users')
        ->where('age', [25, 30], 'IN')       // First whereIn with age
        ->orWhere('age', [35, 40], 'IN')     // Second whereIn with same column using OR
        ->get();

        // Should return all users (Alice, Bob, Charlie, David)
        $this->assertCount(4, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('David', $names);
    }

    public function testHavingAndOrHaving(): void
    {
        $db = self::$db;

        $u1 = $db->find()->table('users')->insert(['name' => 'H1']);
        $u2 = $db->find()->table('users')->insert(['name' => 'H2']);
        $u3 = $db->find()->table('users')->insert(['name' => 'H3']);

        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 100]);
        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 200]); // total 300
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 300]);
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 400]); // total 700
        $db->find()->table('orders')->insert(['user_id' => $u3, 'amount' => 500]); // total 500

        $rows = $db->find()
        ->from('orders')
        ->groupBy('user_id')
        ->having(Db::raw('SUM(amount)'), 300, '=')
        ->orHaving(Db::raw('SUM(amount)'), 500, '=')
        ->orHaving(Db::raw('SUM(amount)'), 700, '=')
        ->select(['user_id', 'SUM(amount) AS total'])
        ->get();

        $totals = array_column($rows, 'total');
        sort($totals);
        $this->assertEquals([300, 500, 700], $totals);
    }

    public function testComplexHavingAndOrHaving(): void
    {
        $db = self::$db;

        $u1 = $db->find()->table('users')->insert(['name' => 'User1', 'age' => 20]);
        $u2 = $db->find()->table('users')->insert(['name' => 'User2', 'age' => 30]);
        $u3 = $db->find()->table('users')->insert(['name' => 'User3', 'age' => 40]);

        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 100]);
        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 200]); // total 300
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 300]);
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 400]); // total 700
        $db->find()->table('orders')->insert(['user_id' => $u3, 'amount' => 500]); // total 500

        $rows = $db->find()
        ->from('orders')
        ->select(['user_id', 'SUM(amount) AS total'])
        ->groupBy('user_id')
        ->having(Db::raw('SUM(amount)'), 300, '>=')
        ->orHaving(Db::raw('SUM(amount)'), 500, '=')
        ->orHaving(Db::raw('SUM(amount)'), 700, '=')
        ->get();

        $totals = array_column($rows, 'total');
        sort($totals);
        $this->assertEquals([300, 500, 700], $totals);
    }

    public function testGroupBy(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insertMulti([
        // for string groupBy test (company G1)
        ['name' => 'A', 'age' => 10, 'company' => 'G1'],
        ['name' => 'B', 'age' => 10, 'company' => 'G1'],

        // for RawValue groupBy test (company G2)
        ['name' => 'C', 'age' => 20, 'company' => 'G2'],
        ['name' => 'D', 'age' => 20, 'company' => 'G2'],
        ['name' => 'E', 'age' => 20, 'company' => 'G2'],

        // for array groupBy test (company G3) using unique names
        ['name' => 'X1', 'age' => 30, 'company' => 'G3'],
        ['name' => 'X2', 'age' => 30, 'company' => 'G3'],
        ['name' => 'Y', 'age' => 30, 'company' => 'G3'],
        ]);

        // 1) groupBy using string
        $rows = $db->find()
        ->from('users')
        ->where(['company' => 'G1'])
        ->groupBy('age')
        ->select(['age', 'COUNT(*) AS cnt'])
        ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals(10, $rows[0]['age']);
        $this->assertEquals(2, $rows[0]['cnt']);

        // 2) groupBy using RawValue (expression)
        $rowsRaw = $db->find()
        ->from('users')
        ->where(['company' => 'G2'])
        ->groupBy(\tommyknocker\pdodb\helpers\Db::raw('age'))
        ->select([\tommyknocker\pdodb\helpers\Db::raw('age'), 'COUNT(*) AS cnt'])
        ->get();

        $this->assertCount(1, $rowsRaw);
        $this->assertEquals(20, $rowsRaw[0]['age']);
        $this->assertEquals(3, $rowsRaw[0]['cnt']);

        // 3) groupBy using array (multiple columns)
        $rowsArr = $db->find()
        ->from('users')
        ->where(['company' => 'G3'])
        ->groupBy(['age', 'name'])
        ->select(['age', 'name', 'COUNT(*) AS cnt'])
        ->orderBy('name ASC')
        ->get();

        // Expect three groups for X1, X2 and Y with counts 1,1,1
        $this->assertCount(3, $rowsArr);

        $map = [];
        foreach ($rowsArr as $r) {
            $map[$r['name']] = (int)$r['cnt'];
            $this->assertEquals(30, (int)$r['age']);
        }

        $this->assertEquals(1, $map['X1']);
        $this->assertEquals(1, $map['X2']);
        $this->assertEquals(1, $map['Y']);
    }

    public function testGroupByWithQualifiedNames(): void
    {
        $db = self::$db;

        // Setup test data
        $db->find()->table('users')->insertMulti([
        ['name' => 'GQAlice', 'age' => 30],
        ['name' => 'GQBob', 'age' => 25],
        ['name' => 'GQCarol', 'age' => 30],
        ]);

        $u1 = $db->find()->from('users')->where('name', 'GQAlice')->getOne()['id'];
        $u2 = $db->find()->from('users')->where('name', 'GQBob')->getOne()['id'];
        $u3 = $db->find()->from('users')->where('name', 'GQCarol')->getOne()['id'];

        $db->find()->table('orders')->insertMulti([
        ['user_id' => $u1, 'amount' => 100.00],
        ['user_id' => $u1, 'amount' => 200.00],
        ['user_id' => $u2, 'amount' => 150.00],
        ['user_id' => $u3, 'amount' => 300.00],
        ]);

        // Test groupBy with qualified column names (table.column)
        // This should work WITHOUT Db::raw() wrapping
        $results = $db->find()
        ->from('users AS u')
        ->leftJoin('orders AS o', 'o.user_id = u.id')
        ->select([
        'u.name',
        'total' => Db::sum('o.amount'),
        ])
        ->groupBy(['u.id', 'u.name'])
        ->orderBy('u.id')
        ->get();

        $this->assertCount(3, $results);
        $this->assertEquals('GQAlice', $results[0]['name']);
        $this->assertEquals(300.00, (float)$results[0]['total']);
        $this->assertEquals('GQBob', $results[1]['name']);
        $this->assertEquals(150.00, (float)$results[1]['total']);
        $this->assertEquals('GQCarol', $results[2]['name']);
        $this->assertEquals(300.00, (float)$results[2]['total']);
    }

    public function testLeftJoin(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Left Join User', 'age' => 30]);
        $db->find()->table('orders')->insert(['user_id' => $userId, 'amount' => 150.50]);

        $results = $db->find()
        ->from('users u')
        ->leftJoin('orders o', 'u.id = o.user_id')
        ->select(['u.name', 'o.amount'])
        ->get();

        $this->assertNotEmpty($results);
        $this->assertEquals('Left Join User', $results[0]['name']);
        $this->assertEquals('150.50', $results[0]['amount']);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('LEFT JOIN', $lastQuery);
    }

    public function testRightJoin(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Right Join User', 'age' => 25]);
        $db->find()->table('orders')->insert(['user_id' => $userId, 'amount' => 200.75]);

        $results = $db->find()
        ->from('users u')
        ->rightJoin('orders o', 'u.id = o.user_id')
        ->select(['u.name', 'o.amount'])
        ->get();

        $this->assertNotEmpty($results);
        $this->assertEquals('Right Join User', $results[0]['name']);
        $this->assertEquals('200.75', $results[0]['amount']);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('RIGHT JOIN', $lastQuery);
    }
}
