<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\ExistenceChecksSupport;

final class ExistenceChecksTests extends BaseSqliteTestCase
{
    public function testCatalogExistenceChecks(): void
    {
        $this->assertSame(
            ExistenceChecksSupport::expected(false),
            ExistenceChecksSupport::run(self::$db, false)
        );
    }
}
