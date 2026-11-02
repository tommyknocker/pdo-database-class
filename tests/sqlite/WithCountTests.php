<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\WithCountSupport;

final class WithCountTests extends BaseSqliteTestCase
{
    public function testRelationCountsAreTheSameOnThisDatabase(): void
    {
        $this->assertEquals(WithCountSupport::expected(), WithCountSupport::run(self::$db));
    }
}
