<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\DumpRoundTripSupport;

final class DumpRoundTripTests extends BaseSqliteTestCase
{
    public function testDumpAndRestoreKeepAwkwardValuesIntact(): void
    {
        $result = DumpRoundTripSupport::run(self::$db, 'CREATE TABLE dump_roundtrip (id INTEGER PRIMARY KEY, txt TEXT, num INTEGER)');

        $this->assertCount(9, $result['before']);
        $this->assertEquals($result['before'], $result['after']);
    }
}
