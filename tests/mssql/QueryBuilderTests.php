<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * QueryBuilderTests tests for MSSQL.
 */
final class QueryBuilderTests extends BaseMSSQLTestCase
{
    public function testSelectWithForUpdate(): void
    {
        $db = self::$db;

        $id = $db->find()
        ->table('users')
        ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertEquals(1, $id);

        $rows = $db->find()
        ->table('users')
        ->option('WITH (UPDLOCK)')
        ->get();
        $this->assertCount(1, $rows);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('WITH (UPDLOCK)', $lastQuery);
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
        ->innerJoin('orders o', 'u.[id] = o.[user_id]')
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
        ->select([Db::raw('[name] + \'_\' + CAST([age] AS NVARCHAR) AS name_age')])
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
        ->select([Db::raw('[name] + \'-\' + CAST([age] AS NVARCHAR) AS n_age')])
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
}
