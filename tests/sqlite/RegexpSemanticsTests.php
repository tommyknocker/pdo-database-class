<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\RegexpSemanticsSupport;

final class RegexpSemanticsTests extends BaseSqliteTestCase
{
    public function testRegexpMatchSelectsTheSameRowsAsPhp(): void
    {
        foreach (RegexpSemanticsSupport::run(self::$db, 'CREATE TABLE regexp_semantics (id INTEGER PRIMARY KEY, txt TEXT)') as $pattern => $rows) {
            $this->assertSame($rows['expected'], $rows['actual'], "pattern: {$pattern}");
        }
    }

    public function testReplaceAndExtractWorkOnFixedText(): void
    {
        foreach (RegexpSemanticsSupport::runTextOperations(self::$db, 'CREATE TABLE regexp_semantics (id INTEGER PRIMARY KEY, txt TEXT)') as $case => $values) {
            $this->assertSame($values['expected'], $values['actual'], $case);
        }
    }
}
