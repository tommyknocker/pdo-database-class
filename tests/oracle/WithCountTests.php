<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\tests\shared\WithCountSupport;

final class WithCountTests extends BaseOracleTestCase
{
    public function testRelationCountsAreTheSameOnThisDatabase(): void
    {
        $this->assertEquals(WithCountSupport::expected(), WithCountSupport::run(self::$db));
    }
}
