<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

final class CacheInvalidationTests extends TestCase
{
    /**
     * @param array<string, mixed> $cacheConfig
     */
    private function db(array $cacheConfig = []): PdoDb
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:', 'cache' => $cacheConfig], [], null, new ArrayCache());
        $db->rawQuery('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery('CREATE TABLE other (id INTEGER PRIMARY KEY, name TEXT)');
        $db->find()->table('items')->insert(['id' => 1, 'name' => 'a']);

        return $db;
    }

    private function names(PdoDb $db): string
    {
        return implode(',', array_column($db->find()->from('items')->orderBy('id')->cache(600)->get(), 'name'));
    }

    public function testWritesDropCachedReadsOfTheSameTable(): void
    {
        $db = $this->db();
        $this->assertSame('a', $this->names($db));

        $db->find()->table('items')->insert(['id' => 2, 'name' => 'b']);
        $this->assertSame('a,b', $this->names($db));

        $db->find()->table('items')->where('id', 2)->update(['name' => 'B']);
        $this->assertSame('a,B', $this->names($db));

        $db->find()->table('items')->insertMulti([['id' => 3, 'name' => 'c']]);
        $this->assertSame('a,B,c', $this->names($db));

        $db->find()->table('items')->where('id', 3)->delete();
        $this->assertSame('a,B', $this->names($db));

        $db->find()->table('items')->truncate();
        $this->assertSame('', $this->names($db));
    }

    public function testWritesToAnotherTableKeepTheCache(): void
    {
        $db = $this->db();
        $this->assertSame('a', $this->names($db));
        $db->rawQuery("INSERT INTO items (id, name) VALUES (9, 'raw')");

        $db->find()->table('other')->insert(['id' => 1, 'name' => 'x']);

        $this->assertSame('a', $this->names($db), 'a write to another table must not drop this entry');
    }

    public function testAutoInvalidateCanBeSwitchedOff(): void
    {
        $db = $this->db(['auto_invalidate' => false]);
        $this->assertSame('a', $this->names($db));

        $db->find()->table('items')->insert(['id' => 2, 'name' => 'b']);

        $this->assertSame('a', $this->names($db));
        $db->getCacheManager()?->invalidateTable('items');
        $this->assertSame('a,b', $this->names($db));
    }
}
