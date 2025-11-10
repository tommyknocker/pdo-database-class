<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\helpers\values\AsValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * AsValueTests tests for AsValue helper class.
 */
final class AsValueTests extends BaseSharedTestCase
{
    public function testAsValueWithInteger(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (\'test1\', 10)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->select(['level' => Db::as(0, 'level')])
            ->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('level', $results[0]);
        $this->assertEquals(0, $results[0]['level']);
    }

    public function testAsValueWithFloat(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (\'test1\', 10)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->select(['ratio' => Db::as(3.14, 'ratio')])
            ->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('ratio', $results[0]);
        $this->assertEquals(3.14, $results[0]['ratio']);
    }

    public function testAsValueWithString(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (\'test1\', 10)');

        // String values in AsValue are treated as column references, so use RawValue for string literals
        $results = self::$db->find()
            ->from('test_coverage')
            ->select(['status' => Db::as(Db::raw("'active'"), 'status')])
            ->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('status', $results[0]);
        $this->assertEquals('active', $results[0]['status']);
    }

    public function testAsValueWithRawValue(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (\'test1\', 10)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->select(['computed' => Db::as(Db::raw('value * 2'), 'computed')])
            ->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('computed', $results[0]);
        $this->assertEquals(20, $results[0]['computed']);
    }

    public function testAsValueWithColumnReference(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (\'test1\', 10)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->select(['doubled' => Db::as('value', 'doubled')])
            ->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('doubled', $results[0]);
        $this->assertEquals(10, $results[0]['doubled']);
    }

    public function testAsValueWithAddHelper(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (\'test1\', 10)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->select(['incremented' => Db::as(Db::add('value', 1), 'incremented')])
            ->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('incremented', $results[0]);
        $this->assertEquals(11, $results[0]['incremented']);
    }

    public function testAsValueInCte(): void
    {
        self::$db->rawQuery('CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER)');
        self::$db->rawQuery('INSERT INTO categories (id, name, parent_id) VALUES (1, \'Root\', NULL), (2, \'Child\', 1)');

        $results = self::$db->find()
            ->withRecursive('category_tree', function ($q) {
                $q->from('categories')
                    ->select(['id', 'name', 'parent_id', 'level' => Db::as(0, 'level')])
                    ->whereNull('parent_id')
                    ->unionAll(function ($union) {
                        $union->from('categories c')
                            ->join('category_tree ct', 'c.parent_id = ct.id')
                            ->select([
                                'c.id',
                                'c.name',
                                'c.parent_id',
                                'level' => Db::as(Db::add('ct.level', 1), 'level'),
                            ]);
                    });
            }, ['id', 'name', 'parent_id', 'level'])
            ->from('category_tree')
            ->orderBy('level')
            ->get();

        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertArrayHasKey('level', $results[0]);
        $this->assertEquals(0, $results[0]['level']);
        $this->assertEquals(1, $results[1]['level']);

        self::$db->rawQuery('DROP TABLE categories');
    }

    public function testAsValueGetters(): void
    {
        $asValue = Db::as(42, 'answer');
        $this->assertInstanceOf(AsValue::class, $asValue);
        $this->assertEquals(42, $asValue->getValue());
        $this->assertEquals('answer', $asValue->getAlias());

        $asValueRaw = Db::as(Db::raw('1 + 1'), 'sum');
        $this->assertInstanceOf(RawValue::class, $asValueRaw->getValue());
        $this->assertEquals('sum', $asValueRaw->getAlias());
    }

    public function testAsValueWithMultipleSelects(): void
    {
        self::$db->rawQuery('INSERT INTO test_coverage (name, value) VALUES (\'test1\', 10), (\'test2\', 20)');

        $results = self::$db->find()
            ->from('test_coverage')
            ->select([
                'id',
                'name',
                'constant' => Db::as(100, 'constant'),
                'doubled' => Db::as(Db::add('value', 'value'), 'doubled'),
            ])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('constant', $results[0]);
        $this->assertArrayHasKey('doubled', $results[0]);
        $this->assertEquals(100, $results[0]['constant']);
        $this->assertEquals(20, $results[0]['doubled']);
        $this->assertEquals(40, $results[1]['doubled']);
    }
}
