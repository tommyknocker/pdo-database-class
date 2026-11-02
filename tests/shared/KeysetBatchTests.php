<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;

final class KeysetBatchTests extends BaseSharedTestCase
{
    public function testEveryRowIsVisitedEvenWhenRowsAreDeletedMeanwhile(): void
    {
        $result = KeysetBatchSupport::deleteWhileIterating(self::$db, 'keyset_items');

        $this->assertSame([1, 3, 5, 7, 9], $result['seen']);
        $this->assertSame(5, $result['left']);
        $this->assertSame([3, 2], $result['batches']);
    }

    public function testOwnOrderByIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array(self::$db->find()->from('keyset_items')->orderBy('id', 'DESC')->batch(2, 'id'));
    }

    public function testInvalidBatchSizeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array(self::$db->find()->from('keyset_items')->batch(0, 'id'));
    }
}
