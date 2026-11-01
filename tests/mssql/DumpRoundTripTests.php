<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\tests\shared\DumpRoundTripSupport;

final class DumpRoundTripTests extends BaseMSSQLTestCase
{
    public function testDumpAndRestoreKeepAwkwardValuesIntact(): void
    {
        $result = DumpRoundTripSupport::run(self::$db, 'CREATE TABLE dump_roundtrip (id INT PRIMARY KEY, txt NVARCHAR(500), num INT)');

        $this->assertCount(9, $result['before']);
        $this->assertEquals($result['before'], $result['after']);
    }
}
