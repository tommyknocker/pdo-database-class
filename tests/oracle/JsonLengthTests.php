<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\tests\shared\JsonLengthSupport;

final class JsonLengthTests extends BaseOracleTestCase
{
    public function testLengthCountsArrayElementsAndObjectKeys(): void
    {
        $lengths = JsonLengthSupport::run(self::$db, 'CREATE TABLE json_len_test (id NUMBER(10) PRIMARY KEY, data VARCHAR2(500) CHECK (data IS JSON))');

        foreach (JsonLengthSupport::documents() as $name => $expected) {
            $this->assertSame(
                ['whole' => $expected['whole'], 'items' => $expected['items'], 'meta' => $expected['meta']],
                $lengths[$name] ?? null,
                $name
            );
        }
    }
}
