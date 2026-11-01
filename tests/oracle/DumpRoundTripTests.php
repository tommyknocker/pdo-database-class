<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\tests\shared\DumpRoundTripSupport;

final class DumpRoundTripTests extends BaseOracleTestCase
{
    public function testDumpAndRestoreKeepAwkwardValuesIntact(): void
    {
        $result = DumpRoundTripSupport::run(self::$db, 'CREATE TABLE dump_roundtrip (id NUMBER(10) PRIMARY KEY, txt VARCHAR2(500), num NUMBER(10))');

        $this->assertCount(9, $result['before']);
        $this->assertEquals($result['before'], $result['after']);
    }
}
