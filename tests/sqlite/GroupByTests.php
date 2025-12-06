<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\helpers\Db;

/**
 * GroupByTests for sqlite.
 */
final class GroupByTests extends BaseSqliteTestCase
{
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
            ['name' => 'Y',  'age' => 30, 'company' => 'G3'],
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
            ->groupBy(Db::raw('age'))
            ->select([Db::raw('age'), 'COUNT(*) AS cnt'])
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
}
