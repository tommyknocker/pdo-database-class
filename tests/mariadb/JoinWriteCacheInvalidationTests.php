<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

/**
 * A write that joins another table must drop the cached reads of that table too.
 */
final class JoinWriteCacheInvalidationTests extends BaseMariaDBTestCase
{
    private PdoDb $cached;

    public function setUp(): void
    {
        parent::setUp();
        $this->cached = new PdoDb('mariadb', [
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => self::DB_USER,
            'password' => self::DB_PASSWORD,
            'dbname' => self::DB_NAME,
            'charset' => self::DB_CHARSET,
        ], [], null, new ArrayCache());
        $this->cached->rawQuery('DROP TABLE IF EXISTS join_cache_b');
        $this->cached->rawQuery('DROP TABLE IF EXISTS join_cache_a');
        $this->cached->rawQuery('CREATE TABLE join_cache_a (id INT AUTO_INCREMENT PRIMARY KEY, x INT)');
        $this->cached->rawQuery('CREATE TABLE join_cache_b (id INT AUTO_INCREMENT PRIMARY KEY, a_id INT, note VARCHAR(20))');
        $this->cached->find()->table('join_cache_a')->insert(['x' => 1]);
        $this->cached->find()->table('join_cache_b')->insert(['a_id' => 1, 'note' => 'old']);
    }

    public function tearDown(): void
    {
        $this->cached->rawQuery('DROP TABLE IF EXISTS join_cache_b');
        $this->cached->rawQuery('DROP TABLE IF EXISTS join_cache_a');
        parent::tearDown();
    }

    private function cachedNote(): string
    {
        return (string)$this->cached->find()->from('join_cache_b')->cache(60)->select(['note'])->getValue();
    }

    public function testUpdateThroughAJoinDropsTheJoinedTablesCachedReads(): void
    {
        $this->assertSame('old', $this->cachedNote());

        $this->cached->find()->from('join_cache_a')->join('join_cache_b', 'join_cache_b.a_id = join_cache_a.id')
            ->update(['join_cache_b.note' => 'new']);

        $this->assertSame('new', $this->cachedNote());
    }

    public function testDeleteThroughAJoinDropsTheJoinedTablesCachedReads(): void
    {
        $this->assertSame('old', $this->cachedNote());

        $this->cached->find()->from('join_cache_b')->join('join_cache_a', 'join_cache_b.a_id = join_cache_a.id')
            ->where('join_cache_a.x', 1)->delete();

        $this->assertSame('', $this->cachedNote());
    }
}
