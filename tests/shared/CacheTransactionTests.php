<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

/**
 * The cache is shared by connections, so it must never hold data that only an open transaction can see.
 */
final class CacheTransactionTests extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'pdodb-cache-tx-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function connect(ArrayCache $cache): PdoDb
    {
        return new PdoDb('sqlite', ['path' => $this->file, 'cache' => ['enabled' => true]], [], null, $cache);
    }

    private function names(PdoDb $db): string
    {
        return implode(',', array_column($db->find()->from('items')->orderBy('id')->cache(600)->get(), 'name'));
    }

    public function testUncommittedRowsNeverReachTheSharedCache(): void
    {
        $cache = new ArrayCache();
        $writer = $this->connect($cache);
        $reader = $this->connect($cache);
        $writer->rawQuery('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $writer->find()->table('items')->insert(['id' => 1, 'name' => 'committed']);

        $writer->startTransaction();
        $writer->find()->table('items')->insert(['id' => 2, 'name' => 'uncommitted']);
        $this->assertSame('committed,uncommitted', $this->names($writer), 'the transaction sees its own rows');
        $this->assertSame('committed', $this->names($reader), 'another connection sees only committed rows, also through the cache');
        $writer->rollback();

        $this->assertSame('committed', $this->names($writer), 'nothing from the rolled back transaction stayed cached');
        $this->assertSame('committed', $this->names($reader));
    }

    public function testCachingWorksAgainAfterTheTransactionEnds(): void
    {
        $cache = new ArrayCache();
        $db = $this->connect($cache);
        $db->rawQuery('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->find()->table('items')->insert(['id' => 1, 'name' => 'a']);

        $db->transaction(function () use ($db): void {
            $this->names($db);
        });
        $this->assertSame('a', $this->names($db));
        $db->rawQuery("UPDATE items SET name = 'changed' WHERE id = 1");

        $this->assertSame('a', $this->names($db), 'a read outside a transaction is cached');
    }
}
