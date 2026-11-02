<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\tests\shared\RegexpSemanticsSupport;

final class RegexpSemanticsTests extends BaseOracleTestCase
{
    public function testRegexpMatchSelectsTheSameRowsAsPhp(): void
    {
        foreach (RegexpSemanticsSupport::run(self::$db, 'CREATE TABLE regexp_semantics (id NUMBER(10) PRIMARY KEY, txt VARCHAR2(100))') as $pattern => $rows) {
            $this->assertSame($rows['expected'], $rows['actual'], "pattern: {$pattern}");
        }
    }

    public function testReplaceAndExtractWorkOnFixedText(): void
    {
        foreach (RegexpSemanticsSupport::runTextOperations(self::$db, 'CREATE TABLE regexp_semantics (id NUMBER(10) PRIMARY KEY, txt VARCHAR2(100))') as $case => $values) {
            $this->assertSame($values['expected'], $values['actual'], $case);
        }
    }
}
