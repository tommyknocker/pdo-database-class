<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

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
}
