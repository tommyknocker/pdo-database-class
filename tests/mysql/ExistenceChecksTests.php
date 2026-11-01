<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\tests\shared\ExistenceChecksSupport;

final class ExistenceChecksTests extends BaseMySQLTestCase
{
    public function testCatalogExistenceChecks(): void
    {
        $this->assertSame(
            ExistenceChecksSupport::expected(true),
            ExistenceChecksSupport::run(self::$db, true)
        );
    }
}
