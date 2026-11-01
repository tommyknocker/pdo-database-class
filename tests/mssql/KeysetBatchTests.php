<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\tests\shared\KeysetBatchSupport;

final class KeysetBatchTests extends BaseMSSQLTestCase
{
    public function testEveryRowIsVisitedEvenWhenRowsAreDeletedMeanwhile(): void
    {
        $result = KeysetBatchSupport::deleteWhileIterating(self::$db, 'keyset_items');

        $this->assertSame([1, 3, 5, 7, 9], $result['seen']);
        $this->assertSame(5, $result['left']);
        $this->assertSame([3, 2], $result['batches']);
    }
}
