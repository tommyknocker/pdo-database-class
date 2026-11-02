<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\ColumnUniqueSupport;

final class ColumnUniqueTests extends BaseSqliteTestCase
{
    public function testUniqueColumnRejectsADuplicateValue(): void
    {
        $result = ColumnUniqueSupport::run(self::$db);

        $this->assertTrue($result['second']);
        $this->assertTrue($result['duplicateRejected'], 'the duplicate email must be refused');
        $this->assertSame(2, $result['rows']);
    }

    public function testAnAddedUniqueColumnRejectsADuplicateValue(): void
    {
        $this->assertTrue(ColumnUniqueSupport::addUniqueColumn(self::$db), 'SQLite adds the column, then a unique index');
    }

    public function testATextColumnIsEitherEnforcedOrRefusedWithAHint(): void
    {
        $this->assertSame('enforced', ColumnUniqueSupport::textUnique(self::$db));
    }
}
