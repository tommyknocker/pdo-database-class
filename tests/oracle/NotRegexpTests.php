<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\tests\shared\NotRegexpSupport;

final class NotRegexpTests extends BaseOracleTestCase
{
    public function testNegatedRegexpMatchUsesTheDialectSyntax(): void
    {
        $emails = NotRegexpSupport::emailsNotMatching(self::$db, 'CREATE TABLE not_regexp_probe (id NUMBER(10) PRIMARY KEY, email VARCHAR2(100))');

        $this->assertSame(['invalid-email'], $emails);
    }
}
