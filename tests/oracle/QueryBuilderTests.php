<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * QueryBuilderTests tests for Oracle.
 */
final class QueryBuilderTests extends BaseOracleTestCase
{
    public function testSelectWithForUpdate(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertIsInt($id);

        $rows = $db->find()
            ->table('users')
            ->option('FOR UPDATE')
            ->get();
        $this->assertCount(1, $rows);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('FOR UPDATE', $lastQuery);
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
        $this->assertEquals('Alice', $firstTwo[0]['NAME']);

        // (offset = 2)
        $nextTwo = $db->find()
            ->from('users')
            ->orderBy('id', 'ASC')
            ->limit(2)
            ->offset(2)
            ->get();

        $this->assertCount(2, $nextTwo);
        $this->assertEquals('Charlie', $nextTwo[0]['NAME']);

        // Verify Oracle-specific syntax (OFFSET ... ROWS FETCH NEXT ... ROWS ONLY)
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('OFFSET', $lastQuery);
        $this->assertStringContainsString('FETCH NEXT', $lastQuery);
        $this->assertStringContainsString('ROWS ONLY', $lastQuery);
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

    public function testOrderBy(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insertMulti([
            ['name' => 'Zebra', 'age' => 30],
            ['name' => 'Alice', 'age' => 20],
            ['name' => 'Bob', 'age' => 25],
        ]);

        $rows = $db->find()
            ->from('users')
            ->orderBy('name', 'ASC')
            ->get();

        $this->assertCount(3, $rows);
        $this->assertEquals('Alice', $rows[0]['NAME']);
        $this->assertEquals('Bob', $rows[1]['NAME']);
        $this->assertEquals('Zebra', $rows[2]['NAME']);
    }

    public function testGroupBy(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insertMulti([
            ['name' => 'Alice', 'age' => 25, 'company' => 'Corp1'],
            ['name' => 'Bob', 'age' => 30, 'company' => 'Corp1'],
            ['name' => 'Charlie', 'age' => 25, 'company' => 'Corp2'],
        ]);

        $rows = $db->find()
            ->from('users')
            ->select(['company', 'count' => Db::count('*')])
            ->groupBy('company')
            ->get();

        $this->assertCount(2, $rows);
    }

    public function testHaving(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insertMulti([
            ['name' => 'Alice', 'age' => 25, 'company' => 'Corp1'],
            ['name' => 'Bob', 'age' => 30, 'company' => 'Corp1'],
            ['name' => 'Charlie', 'age' => 25, 'company' => 'Corp2'],
        ]);

        $rows = $db->find()
            ->from('users')
            ->select(['company', 'count' => Db::count('*')])
            ->groupBy('company')
            ->having(Db::count('*'), 1, '>')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('Corp1', $rows[0]['COMPANY']);
    }
}
