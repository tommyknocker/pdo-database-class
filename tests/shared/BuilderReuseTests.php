<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\AssertionFailedError;
use tommyknocker\pdodb\query\pagination\Cursor;

final class BuilderReuseTests extends BaseSharedTestCase
{
    public function testABuilderCanRunMoreThanOnce(): void
    {
        $this->assertSame(BuilderReuseSupport::expected(), BuilderReuseSupport::run(self::$db));
    }

    public function testRunningABuilderAgainDoesNotGrowItsParameters(): void
    {
        $query = self::$db->find()->from('test_coverage')->where('value', 1, '>')->andWhere('name', 'x%', 'LIKE');
        $store = (new \ReflectionProperty($query, 'parameterManager'))->getValue($query);
        $params = new \ReflectionProperty($store, 'params');

        $before = count($params->getValue($store));
        for ($i = 0; $i < 50; $i++) {
            $query->get();
            $query->getOne();
            $query->limit(2)->offset($i % 3)->get();
        }

        $this->assertSame($before, count($params->getValue($store)));
    }

    public function testCursorPaginationLeavesTheBuildersConditionsAlone(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS cursor_reuse');
        self::$db->rawQuery('CREATE TABLE cursor_reuse (id INTEGER PRIMARY KEY, v INTEGER)');
        for ($i = 1; $i <= 9; $i++) {
            self::$db->find()->table('cursor_reuse')->insert(['v' => $i]);
        }

        try {
            $query = self::$db->find()->from('cursor_reuse')->where('v', 0, '>')->orderBy('id');
            $first = $query->cursorPaginate(3);
            $second = $query->cursorPaginate(3, $first->nextCursor());
            $third = $query->cursorPaginate(3, $second->nextCursor());
            // an earlier cursor on the same builder gives the same page again
            $again = $query->cursorPaginate(3, $first->nextCursor());

            $ids = static fn ($page): array => array_map('intval', array_column($page->items(), 'id'));
            $this->assertSame([1, 2, 3], $ids($first));
            $this->assertSame([4, 5, 6], $ids($second));
            $this->assertSame([7, 8, 9], $ids($third));
            $this->assertSame([4, 5, 6], $ids($again));

            $conditions = (new \ReflectionProperty($query, 'selectQueryBuilder'))->getValue($query);
            $where = (new \ReflectionProperty($conditions, 'conditionBuilder'))->getValue($conditions)->getWhere();
            $this->assertCount(1, $where, 'only the caller\'s own condition remains');
            $this->assertCount(9, $query->get(), 'a plain get() is not narrowed by the last cursor');
        } finally {
            self::$db->rawQuery('DROP TABLE cursor_reuse');
        }
    }

    public function testAFailingPaginationLeavesTheBuildersLimitAndOffsetAlone(): void
    {
        foreach (['simplePaginate', 'paginate', 'cursorPaginate'] as $method) {
            $query = self::$db->find()->from('absent_pagination_table')->orderBy('id')->limit(7)->offset(1);
            $before = $query->toSQL();

            try {
                $method === 'cursorPaginate' ? $query->cursorPaginate(1) : $query->$method(1, 1);
                $this->fail("{$method}() on a missing table must throw");
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(AssertionFailedError::class, $e);
            }

            $this->assertEquals($before, $query->toSQL(), "{$method}() changed the builder's SQL after failing");
        }
    }

    public function testCursorPaginationRestoresTheSqlAndLeavesAllRowsReachable(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS cursor_scenario');
        self::$db->rawQuery('CREATE TABLE cursor_scenario (id INTEGER PRIMARY KEY)');
        self::$db->rawQuery('INSERT INTO cursor_scenario (id) VALUES (1), (2), (3), (4)');

        try {
            $query = self::$db->find()->from('cursor_scenario')->orderBy('id');
            $before = $query->toSQL();
            $page = $query->cursorPaginate(1, Cursor::fromItem(['id' => 2], ['id']));

            $this->assertSame([3], array_map('intval', array_column($page->items(), 'id')));
            $this->assertEquals($before, $query->toSQL());
            $this->assertSame([1, 2, 3, 4], array_map('intval', array_column($query->get(), 'id')));
        } finally {
            self::$db->rawQuery('DROP TABLE cursor_scenario');
        }
    }

    public function testPaginationCountsRowsWhenResultsAreObjects(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS object_pages');
        self::$db->rawQuery('CREATE TABLE object_pages (id INTEGER PRIMARY KEY, n INTEGER)');
        for ($i = 1; $i <= 7; $i++) {
            self::$db->find()->table('object_pages')->insert(['n' => $i]);
        }

        $page = self::$db->find()->from('object_pages')->orderBy('id')->asObject()->paginate(3, 2);

        $this->assertSame(7, $page->total());
        $this->assertCount(3, $page->items());
        $this->assertSame(4, (int)$page->items()[0]->id);
    }

    public function testALimitSurvivesAUnionThatFailsToBuild(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS union_limit');
        self::$db->rawQuery('CREATE TABLE union_limit (id INTEGER PRIMARY KEY)');
        for ($i = 1; $i <= 6; $i++) {
            self::$db->find()->table('union_limit')->insert(['id' => $i]);
        }

        $attempts = 0;
        $query = self::$db->find()->from('union_limit')->select(['id'])->limit(3)->union(function ($q) use (&$attempts): void {
            if (++$attempts === 1) {
                throw new \RuntimeException('cannot build the union yet');
            }
            $q->from('union_limit')->select(['id'])->where('id', 100);
        });

        try {
            $query->get();
            $this->fail('The first run must throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(3, $query->get(), 'LIMIT 3 must still apply after the failed build');
    }

    public function testANegativeLimitOrOffsetIsRejectedBeforeAnyQueryRuns(): void
    {
        foreach ([
            'limit' => static fn () => self::$db->find()->from('test_coverage')->limit(-1),
            'offset' => static fn () => self::$db->find()->from('test_coverage')->offset(-5),
        ] as $name => $build) {
            try {
                $build();
                $this->fail("{$name}(-n) must throw.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($name . '()', $e->getMessage());
            }
        }

        $this->assertIsArray(self::$db->find()->from('test_coverage')->limit(0)->offset(0)->get(), 'zero stays valid');
    }
}
