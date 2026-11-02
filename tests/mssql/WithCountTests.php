<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\tests\shared\WithCountSupport;

final class WithCountTests extends BaseMSSQLTestCase
{
    public function testRelationCountsAreTheSameOnThisDatabase(): void
    {
        $this->assertEquals(WithCountSupport::expected(), WithCountSupport::run(self::$db));
    }
}
