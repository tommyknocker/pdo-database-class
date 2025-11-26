<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * GroupByTests for Oracle.
 */
final class GroupByTests extends BaseOracleTestCase
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
        $this->assertEquals(10, (int)$rows[0]['AGE']);
        $this->assertEquals(2, (int)$rows[0]['CNT']);

        // 2) groupBy using RawValue (expression)
        $rowsRaw = $db->find()
            ->from('users')
            ->where(['company' => 'G2'])
            ->groupBy(Db::raw('age'))
            ->select([Db::raw('age'), 'COUNT(*) AS cnt'])
            ->get();

        $this->assertCount(1, $rowsRaw);
        $this->assertEquals(20, (int)$rowsRaw[0]['AGE']);
        $this->assertEquals(3, (int)$rowsRaw[0]['CNT']);

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
            $map[$r['NAME']] = (int)$r['CNT'];
            $this->assertEquals(30, (int)$r['AGE']);
        }

        $this->assertEquals(1, $map['X1']);
        $this->assertEquals(1, $map['X2']);
        $this->assertEquals(1, $map['Y']);
    }
}



