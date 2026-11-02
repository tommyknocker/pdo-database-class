<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\tests\shared\ColumnUniqueSupport;

final class ColumnUniqueTests extends BaseMariaDBTestCase
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
        $this->assertTrue(ColumnUniqueSupport::addUniqueColumn(self::$db));
    }

    public function testATextColumnIsEitherEnforcedOrRefusedWithAHint(): void
    {
        $this->assertSame('enforced', ColumnUniqueSupport::textUnique(self::$db));
    }
}
