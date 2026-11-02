<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\pagination\Cursor;

final class CursorPaginationTests extends TestCase
{
    private function db(): PdoDb
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE scores (id INTEGER PRIMARY KEY, score INTEGER NOT NULL)');
        // Many rows share a score, so the second ORDER BY column decides inside each group.
        foreach ([[1, 10], [2, 20], [3, 20], [4, 20], [5, 30], [6, 30], [7, 10], [8, 40]] as [$id, $score]) {
            $db->find()->table('scores')->insert(['id' => $id, 'score' => $score]);
        }

        return $db;
    }

    /**
     * @return list<int>
     */
    private function walk(PdoDb $db, string $orderScore, string $orderId, int $perPage): array
    {
        $ids = [];
        $cursor = null;
        for ($guard = 0; $guard < 20; $guard++) {
            $page = $db->find()->from('scores')->orderBy('score', $orderScore)->orderBy('id', $orderId)
                ->cursorPaginate($perPage, $cursor);
            foreach ($page->items() as $row) {
                $ids[] = (int)$row['id'];
            }
            $cursor = $page->nextCursor();
            if ($cursor === null) {
                return $ids;
            }
        }
        $this->fail('Pagination did not terminate.');
    }

    public function testCompositeAscendingKeyVisitsEveryRowOnce(): void
    {
        $this->assertSame([1, 7, 2, 3, 4, 5, 6, 8], $this->walk($this->db(), 'ASC', 'ASC', 3));
    }

    public function testDescendingOrderIsHonored(): void
    {
        $this->assertSame([8, 6, 5, 4, 3, 2, 7, 1], $this->walk($this->db(), 'DESC', 'DESC', 3));
    }

    public function testMixedDirections(): void
    {
        $this->assertSame([8, 5, 6, 2, 3, 4, 1, 7], $this->walk($this->db(), 'DESC', 'ASC', 2));
    }

    public function testCursorForOtherColumnsIsRejected(): void
    {
        $db = $this->db();
        $cursor = new Cursor(['unrelated' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $db->find()->from('scores')->orderBy('score')->orderBy('id')->cursorPaginate(2, $cursor);
    }

    public function testBuilderKeepsItsLimitAndOffsetAfterPagination(): void
    {
        $db = $this->db();

        $query = $db->find()->from('scores')->orderBy('id');
        $query->simplePaginate(2, 2);
        $this->assertCount(8, $query->get(), 'simplePaginate() must not leave LIMIT/OFFSET on the builder');

        $query = $db->find()->from('scores')->orderBy('id');
        $query->cursorPaginate(2);
        $this->assertCount(8, $query->get(), 'cursorPaginate() must not leave LIMIT on the builder');
    }

    public function testColumnsQualifiedWithATableAliasPageThroughEveryRow(): void
    {
        $db = $this->db();

        $ids = [];
        $cursor = null;
        for ($guard = 0; $guard < 20; $guard++) {
            $page = $db->find()->from('scores s')->orderBy('s.score', 'DESC')->orderBy('s.id', 'ASC')->cursorPaginate(3, $cursor);
            foreach ($page->items() as $row) {
                $ids[] = (int)$row['id'];
            }
            $cursor = $page->nextCursor();
            if ($cursor === null) {
                break;
            }
        }

        $this->assertSame([8, 5, 6, 2, 3, 4, 1, 7], $ids);
    }

    public function testQualifiedKeyBatchesVisitEveryRow(): void
    {
        $db = $this->db();

        $ids = [];
        foreach ($db->find()->from('scores s')->batch(3, 's.id') as $batch) {
            foreach ($batch as $row) {
                $ids[] = (int)$row['id'];
            }
        }

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $ids);
    }
}
