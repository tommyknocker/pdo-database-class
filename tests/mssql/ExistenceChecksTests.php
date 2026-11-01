<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\tests\shared\ExistenceChecksSupport;

final class ExistenceChecksTests extends BaseMSSQLTestCase
{
    public function testCatalogExistenceChecks(): void
    {
        $this->assertSame(
            ExistenceChecksSupport::expected(true),
            ExistenceChecksSupport::run(self::$db, true)
        );
    }
}
