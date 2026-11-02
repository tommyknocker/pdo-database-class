<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\tests\shared\WithCountSupport;

final class WithCountTests extends BaseMySQLTestCase
{
    public function testRelationCountsAreTheSameOnThisDatabase(): void
    {
        $this->assertEquals(WithCountSupport::expected(), WithCountSupport::run(self::$db));
    }
}
