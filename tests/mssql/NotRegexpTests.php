<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\tests\shared\NotRegexpSupport;

final class NotRegexpTests extends BaseMSSQLTestCase
{
    public function testNegatedRegexpMatchUsesTheDialectSyntax(): void
    {
        $emails = NotRegexpSupport::emailsNotMatching(self::$db, 'CREATE TABLE not_regexp_probe (id INT PRIMARY KEY, email NVARCHAR(100))');

        $this->assertSame(['invalid-email'], $emails);
    }
}
