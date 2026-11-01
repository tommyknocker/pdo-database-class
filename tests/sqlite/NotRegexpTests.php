<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\NotRegexpSupport;

final class NotRegexpTests extends BaseSqliteTestCase
{
    public function testNegatedRegexpMatchUsesTheDialectSyntax(): void
    {
        $emails = NotRegexpSupport::emailsNotMatching(self::$db, 'CREATE TABLE not_regexp_probe (id INTEGER PRIMARY KEY, email TEXT)');

        $this->assertSame(['invalid-email'], $emails);
    }
}
