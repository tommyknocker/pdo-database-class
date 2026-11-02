<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\helpers\Db;

final class FulltextSearchTests extends BaseSqliteTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS articles_fts');
        self::$db->rawQuery('CREATE VIRTUAL TABLE articles_fts USING fts5(title, body)');
        self::$db->rawQuery("INSERT INTO articles_fts (rowid, title, body) VALUES (1, 'alpha beta', 'gamma'), (2, 'delta', 'alpha epsilon'), (3, 'zeta', 'eta')");
    }

    public function tearDown(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS articles_fts');
        parent::tearDown();
    }

    /**
     * @param string|list<string> $columns
     *
     * @return list<int>
     */
    private function search(string|array $columns, string $term): array
    {
        $rows = self::$db->find()->from('articles_fts')->select(['rowid'])->where(Db::match($columns, $term))->orderBy('rowid')->get();

        return array_map(static fn (array $row): int => (int)$row['rowid'], $rows);
    }

    public function testASingleColumnSearchesOnlyThatColumn(): void
    {
        $this->assertSame([1], $this->search('title', 'alpha'));
        $this->assertSame([2], $this->search(['body'], 'alpha'));
    }

    public function testSeveralColumnsMatchWhenAnyOfThemDoes(): void
    {
        $this->assertSame([1, 2], $this->search(['title', 'body'], 'alpha'));
        $this->assertSame([], $this->search(['title', 'body'], 'missing'));
    }

    public function testSeveralColumnsCombineWithOtherConditions(): void
    {
        $rows = self::$db->find()->from('articles_fts')->select(['rowid'])
            ->where(Db::match(['title', 'body'], 'alpha'))
            ->andWhere('rowid', 2, '>=')
            ->get();

        $this->assertSame([2], array_map(static fn (array $row): int => (int)$row['rowid'], $rows));
    }
}
