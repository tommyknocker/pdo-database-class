<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

final class ContextOwner extends Model
{
    public static function tableName(): string
    {
        return 'owners';
    }
    public static function relations(): array
    {
        return [
            'items' => ['hasMany', 'modelClass' => ContextItem::class, 'foreignKey' => 'owner_id', 'localKey' => 'id'],
            'firstItem' => ['hasOne', 'modelClass' => ContextItem::class, 'foreignKey' => 'owner_id', 'localKey' => 'id'],
            'tags' => ['hasManyThrough', 'modelClass' => ContextTag::class, 'viaTable' => 'owner_tag', 'link' => ['id' => 'owner_id'], 'viaLink' => ['tag_id' => 'id']],
            'notes' => ['hasManyThrough', 'modelClass' => ContextNote::class, 'via' => 'items', 'viaLink' => ['item_id' => 'id']],
        ];
    }
}
final class ContextItem extends Model
{
    public static function tableName(): string
    {
        return 'items';
    }
    public static function globalScopes(): array
    {
        return ['visible' => static fn ($q) => $q->where('visible', 1)];
    }
    public static function relations(): array
    {
        return ['owner' => ['belongsTo', 'modelClass' => ContextOwner::class, 'foreignKey' => 'owner_id', 'ownerKey' => 'id']];
    }
}
final class ContextTag extends Model
{
    public static function tableName(): string
    {
        return 'tags';
    }
    public static function globalScopes(): array
    {
        return ['visible' => static fn ($q) => $q->where('visible', 1)];
    }
}
final class ContextNote extends Model
{
    public static function tableName(): string
    {
        return 'notes';
    }
}

final class ModelConnectionContextTests extends TestCase
{
    private function database(string $label): PdoDb
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE owners (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery('CREATE TABLE items (id INTEGER PRIMARY KEY, owner_id INTEGER, name TEXT, visible INTEGER)');
        $db->rawQuery('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT, visible INTEGER)');
        $db->rawQuery('CREATE TABLE owner_tag (owner_id INTEGER, tag_id INTEGER)');
        $db->rawQuery('CREATE TABLE notes (id INTEGER PRIMARY KEY, item_id INTEGER, body TEXT)');
        $db->find()->table('owners')->insert(['id' => 1, 'name' => $label]);
        $db->find()->table('items')->insert(['id' => 1, 'owner_id' => 1, 'name' => $label . '-visible', 'visible' => 1]);
        $db->find()->table('items')->insert(['id' => 2, 'owner_id' => 1, 'name' => $label . '-hidden', 'visible' => 0]);
        $db->find()->table('tags')->insert(['id' => 1, 'name' => $label . '-tag', 'visible' => 1]);
        $db->find()->table('tags')->insert(['id' => 2, 'name' => $label . '-hidden-tag', 'visible' => 0]);
        $db->find()->table('owner_tag')->insert(['owner_id' => 1, 'tag_id' => 1]);
        $db->find()->table('owner_tag')->insert(['owner_id' => 1, 'tag_id' => 2]);
        $db->find()->table('notes')->insert(['id' => 1, 'item_id' => 1, 'body' => $label . '-visible-note']);
        $db->find()->table('notes')->insert(['id' => 2, 'item_id' => 2, 'body' => $label . '-hidden-note']);
        return $db;
    }

    public function testTwoExplicitContextsKeepReadsWritesAndRelationsIsolated(): void
    {
        $a = $this->database('A');
        $b = $this->database('B');
        ContextOwner::setDb(null);
        ContextItem::setDb(null);
        ContextTag::setDb(null);
        $first = ContextOwner::on($a)->where('id', 1)->one();
        $second = ContextOwner::on($b)->where('id', 1)->one();
        self::assertSame('A', $first->name);
        self::assertSame('B', $second->name);
        self::assertSame('A-visible', $first->items[0]->name);
        self::assertCount(1, $first->items);
        self::assertSame('B-visible', $second->firstItem->name);
        self::assertSame('B', $second->firstItem->owner->name);
        self::assertCount(1, $second->tags);
        self::assertSame('B-tag', $second->tags[0]->name);
        $second->name = 'B-updated';
        self::assertTrue($second->save());
        self::assertTrue($second->refresh());
        self::assertSame('B-updated', $second->name);
        self::assertSame('A', $a->find()->from('owners')->getOne()['name']);
        self::assertSame('B-updated', $b->find()->from('owners')->getOne()['name']);
    }

    public function testEagerLoadingUsesExplicitContextAndRelatedGlobalScopes(): void
    {
        $a = $this->database('A');
        $b = $this->database('B');
        ContextOwner::setDb($a);
        ContextItem::setDb($a);
        $page = ContextOwner::on($b)->with('items')->paginateModels(10, 1);
        $owner = $page->items()[0];
        self::assertSame('B', $owner->name);
        self::assertCount(1, $owner->items);
        self::assertSame('B-visible', $owner->items[0]->name);
        $items = $owner->items;
        $items[0]->name = 'B-item-updated';
        self::assertTrue($items[0]->save());
        self::assertSame('A-visible', $a->find()->from('items')->where('id', 1)->getOne()['name']);
        self::assertSame('B-item-updated', $b->find()->from('items')->where('id', 1)->getOne()['name']);
    }

    public function testLoadedDefaultModelKeepsOriginalConnectionAfterStaticChange(): void
    {
        $a = $this->database('A');
        $b = $this->database('B');
        ContextOwner::setDb($a);
        $model = ContextOwner::findOne(1);
        ContextOwner::setDb($b);
        $model->name = 'A-updated';
        self::assertTrue($model->save());
        self::assertTrue($model->refresh());
        self::assertSame('A-updated', $model->name);
        self::assertSame('B', $b->find()->from('owners')->getOne()['name']);
    }

    public function testEagerThroughUsesExplicitConnection(): void
    {
        $a = $this->database('A');
        $b = $this->database('B');
        ContextOwner::setDb($a);
        ContextTag::setDb($a);
        $owner = ContextOwner::on($b)->with('tags')->where('id', 1)->one();
        self::assertCount(1, $owner->tags);
        self::assertSame('B-tag', $owner->tags[0]->name);
        $tags = $owner->tags;
        $tags[0]->name = 'B-tag-updated';
        self::assertTrue($tags[0]->save());
        self::assertSame('A-tag', $a->find()->from('tags')->getOne()['name']);
        self::assertSame('B-tag-updated', $b->find()->from('tags')->getOne()['name']);
    }

    public function testViaRelationRetainsIntermediateScopeAndConnection(): void
    {
        $a = $this->database('A');
        $b = $this->database('B');
        ContextOwner::setDb($a);
        ContextItem::setDb($a);
        ContextNote::setDb($a);
        $owner = ContextOwner::on($b)->where('id', 1)->one();
        self::assertCount(1, $owner->notes);
        self::assertSame('B-visible-note', $owner->notes[0]->body);
        $query = $owner->notes();
        $queryNotes = $query->all();
        self::assertCount(1, $queryNotes);
        self::assertSame('B-visible-note', $queryNotes[0]->body);
        $eager = ContextOwner::on($b)->with('notes')->where('id', 1)->one();
        self::assertCount(1, $eager->notes);
        self::assertSame('B-visible-note', $eager->notes[0]->body);
    }

    public function testLoadedModelCannotBeRebound(): void
    {
        $a = $this->database('A');
        $b = $this->database('B');
        $model = ContextOwner::on($a)->where('id', 1)->one();
        $this->expectException(RuntimeException::class);
        $model->bindConnection($b);
    }

    public function testChangingActiveConnectionIsDetectedBeforeModelWrite(): void
    {
        $pool = new PdoDb();
        $pool->addConnection('a', ['driver' => 'sqlite', 'path' => ':memory:']);
        $pool->addConnection('b', ['driver' => 'sqlite', 'path' => ':memory:']);
        $pool->connection('a');
        $pool->rawQuery('CREATE TABLE owners (id INTEGER PRIMARY KEY, name TEXT)');
        $pool->rawQuery("INSERT INTO owners VALUES (1, 'A')");
        $model = ContextOwner::on($pool)->where('id', 1)->one();
        $model->name = 'unsafe';
        $pool->connection('b');
        $pool->rawQuery('CREATE TABLE owners (id INTEGER PRIMARY KEY, name TEXT)');
        $pool->rawQuery("INSERT INTO owners VALUES (1, 'B')");

        try {
            $model->save();
            self::fail('A changed connection must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('connection changed', $e->getMessage());
        }
        self::assertSame('B', $pool->find()->from('owners')->getOne()['name']);
    }
}
