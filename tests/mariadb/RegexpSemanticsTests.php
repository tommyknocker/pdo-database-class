<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\tests\shared\RegexpSemanticsSupport;

final class RegexpSemanticsTests extends BaseMariaDBTestCase
{
    public function testRegexpMatchSelectsTheSameRowsAsPhp(): void
    {
        foreach (RegexpSemanticsSupport::run(self::$db, 'CREATE TABLE regexp_semantics (id INT PRIMARY KEY, txt VARCHAR(100))') as $pattern => $rows) {
            $this->assertSame($rows['expected'], $rows['actual'], "pattern: {$pattern}");
        }
    }

    public function testReplaceAndExtractWorkOnFixedText(): void
    {
        foreach (RegexpSemanticsSupport::runTextOperations(self::$db, 'CREATE TABLE regexp_semantics (id INT PRIMARY KEY, txt VARCHAR(100))') as $case => $values) {
            $this->assertSame($values['expected'], $values['actual'], $case);
        }
    }
}
