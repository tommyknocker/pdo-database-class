<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

/**
 * Tests for enhanced WHERE condition methods.
 */
final class WhereConditionMethodsTests extends BaseSharedTestCase
{
    public function testWhereNull(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'WithValue', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'NullValue', 'value' => null]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNull('value')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('NullValue', $rows[0]['name']);
    }

    public function testWhereNotNull(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'WithValue', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'NullValue', 'value' => null]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNotNull('value')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('WithValue', $rows[0]['name']);
    }

    public function testWhereNullWithOrBoolean(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => null]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 20]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->orWhereNull('value')
            ->get();

        $names = array_column($rows, 'name');
        sort($names);
        $this->assertEquals(['A', 'B'], $names);
    }

    public function testWhereNotNullWithOrBoolean(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => null]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 20]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->orWhereNotNull('value')
            ->get();

        $names = array_column($rows, 'name');
        sort($names);
        $this->assertEquals(['A', 'C'], $names);
    }

    public function testWhereBetween(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'D', 'value' => 40]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereBetween('value', 15, 35)
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('B', $rows[0]['name']);
        $this->assertEquals('C', $rows[1]['name']);
    }

    public function testWhereNotBetween(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'D', 'value' => 40]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNotBetween('value', 15, 35)
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('A', $rows[0]['name']);
        $this->assertEquals('D', $rows[1]['name']);
    }

    public function testOrWhereBetween(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->orWhereBetween('value', 25, 35)
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('A', $rows[0]['name']);
        $this->assertEquals('C', $rows[1]['name']);
    }

    public function testOrWhereNotBetween(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->orWhereNotBetween('value', 15, 25)
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('A', $rows[0]['name']);
        $this->assertEquals('C', $rows[1]['name']);
    }

    public function testWhereInWithArray(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'D', 'value' => 40]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereIn('value', [20, 40])
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('B', $rows[0]['name']);
        $this->assertEquals('D', $rows[1]['name']);
    }

    public function testWhereNotInWithArray(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'D', 'value' => 40]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNotIn('value', [20, 40])
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('A', $rows[0]['name']);
        $this->assertEquals('C', $rows[1]['name']);
    }

    public function testWhereInWithSubquery(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereIn('value', function ($q) {
                $q->from('test_coverage')
                    ->select('value')
                    ->where('value', 20, '>');
            })
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('C', $rows[0]['name']);
    }

    public function testWhereNotInWithSubquery(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNotIn('value', function ($q) {
                $q->from('test_coverage')
                    ->select('value')
                    ->where('value', 20, '>');
            })
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('A', $rows[0]['name']);
        $this->assertEquals('B', $rows[1]['name']);
    }

    public function testOrWhereInWithArray(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->orWhereIn('value', [30])
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('A', $rows[0]['name']);
        $this->assertEquals('C', $rows[1]['name']);
    }

    public function testOrWhereNotInWithArray(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->orWhereNotIn('value', [20])
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('A', $rows[0]['name']);
        $this->assertEquals('C', $rows[1]['name']);
    }

    public function testWhereInWithEmptyArray(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereIn('value', [])
            ->get();

        $this->assertCount(0, $rows);
    }

    public function testWhereNotInWithEmptyArray(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNotIn('value', [])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('A', $rows[0]['name']);
    }

    public function testWhereColumn(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 10]);

        // Create a table with two numeric columns
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
        if ($driver === 'sqlite') {
            self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_comparison (id INTEGER PRIMARY KEY, col1 INTEGER, col2 INTEGER)');
        } else {
            self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_comparison (id INTEGER PRIMARY KEY AUTO_INCREMENT, col1 INTEGER, col2 INTEGER)');
        }

        self::$db->find()->table('test_comparison')->insert(['col1' => 10, 'col2' => 10]);
        self::$db->find()->table('test_comparison')->insert(['col1' => 20, 'col2' => 30]);
        self::$db->find()->table('test_comparison')->insert(['col1' => 15, 'col2' => 15]);

        $rows = self::$db->find()
            ->from('test_comparison')
            ->whereColumn('col1', '=', 'col2')
            ->get();

        $this->assertCount(2, $rows);

        $rows = self::$db->find()
            ->from('test_comparison')
            ->whereColumn('col1', '>', 'col2')
            ->get();

        $this->assertCount(0, $rows);

        $rows = self::$db->find()
            ->from('test_comparison')
            ->whereColumn('col1', '<', 'col2')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals(20, $rows[0]['col1']);
    }

    public function testWhereColumnWithOrBoolean(): void
    {
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
        if ($driver === 'sqlite') {
            self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_comparison2 (id INTEGER PRIMARY KEY, col1 INTEGER, col2 INTEGER)');
        } else {
            self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_comparison2 (id INTEGER PRIMARY KEY AUTO_INCREMENT, col1 INTEGER, col2 INTEGER)');
        }

        self::$db->find()->table('test_comparison2')->insert(['col1' => 10, 'col2' => 10]);
        self::$db->find()->table('test_comparison2')->insert(['col1' => 20, 'col2' => 30]);
        self::$db->find()->table('test_comparison2')->insert(['col1' => 15, 'col2' => 15]);

        $rows = self::$db->find()
            ->from('test_comparison2')
            ->where('col1', 10)
            ->orWhereColumn('col1', '=', 'col2')
            ->get();

        $this->assertCount(2, $rows);
    }

    public function testComplexWhereConditions(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => null]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'D', 'value' => 40]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNotNull('value')
            ->whereBetween('value', 15, 45)
            ->whereNotIn('name', ['B'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('D', $rows[0]['name']);
    }

    public function testChainingWhereMethods(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => null]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->whereNull('value')
            ->orWhereNotNull('value')
            ->orWhereBetween('value', 15, 25)
            ->get();

        $this->assertCount(3, $rows);
    }

    public function testAndWhereNull(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => null]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 20]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->andWhereNull('value')
            ->get();

        $this->assertCount(0, $rows);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'B')
            ->andWhereNull('value')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('B', $rows[0]['name']);
    }

    public function testAndWhereNotNull(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => null]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 20]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->andWhereNotNull('value')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('A', $rows[0]['name']);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'B')
            ->andWhereNotNull('value')
            ->get();

        $this->assertCount(0, $rows);
    }

    public function testAndWhereBetween(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        // Test that andWhereBetween works correctly with AND logic
        // WHERE name = 'B' AND value BETWEEN 15 AND 25
        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'B')
            ->andWhereBetween('value', 15, 25)
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('B', $rows[0]['name']);

        // Test that it filters out rows that don't match
        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->andWhereBetween('value', 15, 25)
            ->get();

        $this->assertCount(0, $rows);
    }

    public function testAndWhereNotBetween(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->orWhere('name', 'C')
            ->andWhereNotBetween('value', 15, 25)
            ->orderBy('value', 'ASC')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEquals('A', $rows[0]['name']);
        $this->assertEquals('C', $rows[1]['name']);
    }

    public function testAndWhereIn(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        // Test that andWhereIn works correctly with AND logic
        // WHERE name = 'B' AND value IN (20, 30)
        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'B')
            ->andWhereIn('value', [20, 30])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('B', $rows[0]['name']);

        // Test that it filters out rows that don't match
        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->andWhereIn('value', [20, 30])
            ->get();

        $this->assertCount(0, $rows);
    }

    public function testAndWhereNotIn(): void
    {
        self::$db->find()->table('test_coverage')->insert(['name' => 'A', 'value' => 10]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'B', 'value' => 20]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'C', 'value' => 30]);

        $rows = self::$db->find()
            ->from('test_coverage')
            ->where('name', 'A')
            ->orWhere('name', 'B')
            ->andWhereNotIn('value', [20])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('A', $rows[0]['name']);
    }

    public function testAndWhereColumn(): void
    {
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
        if ($driver === 'sqlite') {
            self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_comparison3 (id INTEGER PRIMARY KEY, col1 INTEGER, col2 INTEGER)');
        } else {
            self::$db->rawQuery('CREATE TABLE IF NOT EXISTS test_comparison3 (id INTEGER PRIMARY KEY AUTO_INCREMENT, col1 INTEGER, col2 INTEGER)');
        }

        self::$db->find()->table('test_comparison3')->insert(['col1' => 10, 'col2' => 10]);
        self::$db->find()->table('test_comparison3')->insert(['col1' => 20, 'col2' => 30]);
        self::$db->find()->table('test_comparison3')->insert(['col1' => 15, 'col2' => 15]);

        $rows = self::$db->find()
            ->from('test_comparison3')
            ->where('col1', 10)
            ->andWhereColumn('col1', '=', 'col2')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals(10, $rows[0]['col1']);

        $rows = self::$db->find()
            ->from('test_comparison3')
            ->where('col1', 20)
            ->andWhereColumn('col1', '=', 'col2')
            ->get();

        $this->assertCount(0, $rows);
    }
}
