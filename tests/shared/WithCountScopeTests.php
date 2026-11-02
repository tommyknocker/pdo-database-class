<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\query\QueryBuilder;

final class ScopedOwner extends Model
{
    public static function tableName(): string
    {
        return 'scoped_owners';
    }

    public static function relations(): array
    {
        return ['items' => ['hasMany', 'modelClass' => ScopedItem::class, 'foreignKey' => 'owner_id']];
    }
}

final class ScopedItem extends Model
{
    public static function tableName(): string
    {
        return 'scoped_items';
    }

    public static function globalScopes(): array
    {
        return ['visible' => static fn (QueryBuilder $q): QueryBuilder => $q->where('hidden', 0)];
    }
}

/**
 * withCount() counts what the relation returns: rows hidden by the related model's global scope are not counted.
 */
final class WithCountScopeTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS scoped_items');
        self::$db->rawQuery('DROP TABLE IF EXISTS scoped_owners');
        self::$db->rawQuery('CREATE TABLE scoped_owners (id INTEGER PRIMARY KEY, name TEXT)');
        self::$db->rawQuery('CREATE TABLE scoped_items (id INTEGER PRIMARY KEY, owner_id INTEGER, hidden INTEGER NOT NULL DEFAULT 0)');
        self::$db->find()->table('scoped_owners')->insert(['id' => 1, 'name' => 'a']);
        self::$db->find()->table('scoped_items')->insertMulti([
            ['id' => 1, 'owner_id' => 1, 'hidden' => 0],
            ['id' => 2, 'owner_id' => 1, 'hidden' => 1],
        ]);
        Model::setDb(self::$db);
    }

    protected function tearDown(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS scoped_items');
        self::$db->rawQuery('DROP TABLE IF EXISTS scoped_owners');
        parent::tearDown();
    }

    public function testCountMatchesTheRelationAndSkipsHiddenRows(): void
    {
        $owner = ScopedOwner::find()->withCount('items')->with('items')->where('id', 1)->one();

        $this->assertNotNull($owner);
        $this->assertCount(1, $owner->items);
        $this->assertSame(1, (int)$owner->items_count);
    }

    public function testTheScopeCanBeSwitchedOffForTheCount(): void
    {
        $owner = ScopedOwner::find()->withCount(['items' => static function ($query): void {
            $query->withoutGlobalScope('visible');
        }])->where('id', 1)->one();

        $this->assertSame(2, (int)$owner->items_count);
    }

    public function testScopedQueryBuilderAppliesTheModelScope(): void
    {
        $rows = ScopedItem::find()->getScopedQueryBuilder()->get();

        $this->assertCount(1, $rows);
    }
}
