<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\QueryBuilder;

/**
 * A scope registered on the connection must hold for every way a query runs, not only get().
 */
final class GlobalScopeCoverageTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS scope_cover');
        self::$db->rawQuery('CREATE TABLE scope_cover (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)');
        foreach ([[1, 1, 'mine'], [2, 2, 'theirs'], [3, 1, 'mine too']] as [$id, $tenant, $name]) {
            self::$db->find()->table('scope_cover')->insert(['id' => $id, 'tenant_id' => $tenant, 'name' => $name]);
        }
        self::$db->addScope('tenant', static fn (QueryBuilder $q): QueryBuilder => $q->where('tenant_id', 1));
    }

    protected function tearDown(): void
    {
        self::$db->removeScope('tenant');
        self::$db->rawQuery('DROP TABLE IF EXISTS scope_cover');
        parent::tearDown();
    }

    private function query(): QueryBuilder
    {
        return self::$db->find()->from('scope_cover');
    }

    public function testReadsSeeOnlyTheScopedTenant(): void
    {
        $this->assertCount(2, $this->query()->get());
        $this->assertSame([1, 3], array_map('intval', array_column(iterator_to_array($this->query()->each(1), false), 'id')));
        $this->assertSame([1, 3], array_map('intval', array_column(array_merge(...iterator_to_array($this->query()->batch(1), false)), 'id')));
        $this->assertSame([1, 3], array_map('intval', array_column(iterator_to_array($this->query()->each(1, 'id'), false), 'id')));
        $this->assertSame([1, 3], array_map('intval', array_column(iterator_to_array($this->query()->stream(), false), 'id')));
        $this->assertTrue($this->query()->where('id', 1)->exists());
        $this->assertFalse($this->query()->where('id', 2)->exists());
        $this->assertTrue($this->query()->where('id', 2)->notExists());
        $this->assertStringContainsString('tenant_id', $this->query()->toSQL()['sql']);
    }

    public function testWritesTouchOnlyTheScopedTenant(): void
    {
        $this->assertSame(2, $this->query()->update(['name' => 'changed']));
        $this->assertSame('theirs', self::$db->rawQueryValue('SELECT name FROM scope_cover WHERE id = 2'));

        $this->assertSame(2, $this->query()->delete());
        $this->assertSame(1, (int)self::$db->rawQueryValue('SELECT COUNT(*) FROM scope_cover'));
    }
}
