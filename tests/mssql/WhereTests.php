<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * WhereTests for MSSQL.
 */
final class WhereTests extends BaseMSSQLTestCase
{
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

    public function testWhereSyntax(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'D', 'age' => 50]);

        $row = $db->find()->from('users')->where(['age' => 25])->getOne();
        $this->assertNotFalse($row);
        $this->assertEquals('B', $row['name']);

        $row = $db->find()->from('users')->where(['age' => ':age'], ['age' => 30])->getOne();
        $this->assertNotFalse($row);
        $this->assertEquals('C', $row['name']);

        $row = $db->find()->from('users')->where('age = 50')->getOne();
        $this->assertNotFalse($row);
        $this->assertEquals('D', $row['name']);
    }

    public function testWhereIn(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'D', 'age' => 40]);

        $rows = $db->find()
            ->from('users')
            ->whereIn('age', [10, 30])
            ->get();
        $this->assertCount(2, $rows);

        $rows = $db->find()
            ->from('users')
            ->whereIn('age', [])
            ->get();
        $this->assertCount(0, $rows);
    }

    public function testWhereNotIn(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        $rows = $db->find()
            ->from('users')
            ->whereNotIn('age', [10, 30])
            ->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('B', $rows[0]['name']);
    }

    public function testWhereBetween(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'D', 'age' => 40]);

        $rows = $db->find()
            ->from('users')
            ->whereBetween('age', 15, 35)
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereNotBetween(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        $rows = $db->find()
            ->from('users')
            ->whereNotBetween('age', 15, 25)
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereLike(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'Charlie', 'age' => 35]);

        $rows = $db->find()
            ->from('users')
            ->where(Db::like('name', 'A%'))
            ->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('Alice', $rows[0]['name']);

        // MSSQL LIKE is case-insensitive by default, so '%b%' should match 'Bob' and 'Charlie'
        $rows = $db->find()
            ->from('users')
            ->where(Db::like('name', '%b%'))
            ->get();
        // Should match 'Bob' and 'Charlie' (case-insensitive)
        $this->assertGreaterThanOrEqual(1, count($rows));
        $names = array_column($rows, 'name');
        $this->assertContains('Bob', $names);
    }

    public function testWhereNotLike(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'Charlie', 'age' => 35]);

        $rows = $db->find()
            ->from('users')
            ->where(Db::not(Db::like('name', 'A%')))
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereIsNull(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10, 'status' => null]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30, 'status' => null]);

        $rows = $db->find()
            ->from('users')
            ->whereNull('status')
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereIsNotNull(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10, 'status' => null]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20, 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30, 'status' => null]);

        $rows = $db->find()
            ->from('users')
            ->whereNotNull('status')
            ->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('B', $rows[0]['name']);
    }

    public function testWhereGreaterThan(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        $rows = $db->find()
            ->from('users')
            ->where('age', 15, '>')
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereLessThan(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        $rows = $db->find()
            ->from('users')
            ->where('age', 25, '<')
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereGreaterThanOrEqual(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        $rows = $db->find()
            ->from('users')
            ->where('age', 20, '>=')
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereLessThanOrEqual(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        $rows = $db->find()
            ->from('users')
            ->where('age', 20, '<=')
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereNot(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        $rows = $db->find()
            ->from('users')
            ->where(Db::not(Db::in('age', [20])))
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereRaw(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        // Use named parameters for whereRaw (whereRaw expects associative array)
        $rows = $db->find()
            ->from('users')
            ->whereRaw('[age] = :age', [':age' => 20])
            ->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('B', $rows[0]['name']);
    }

    public function testWhereGroup(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 20]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);

        // Use where with closure for grouping
        $rows = $db->find()
            ->from('users')
            ->where('id', function ($q) {
                $q->from('users')
                    ->select('id')
                    ->where('age', 10)
                    ->orWhere('age', 30);
            }, 'IN')
            ->get();
        $this->assertCount(2, $rows);
    }
}

