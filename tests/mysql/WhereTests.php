<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\helpers\Db;

/**
 * WhereTests for mysql.
 */
final class WhereTests extends BaseMySQLTestCase
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
}
