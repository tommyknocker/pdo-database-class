<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\tests\shared\JsonLengthSupport;

final class JsonLengthTests extends BasePostgreSQLTestCase
{
    public function testLengthCountsArrayElementsAndObjectKeys(): void
    {
        $lengths = JsonLengthSupport::run(self::$db, 'CREATE TABLE json_len_test (id INTEGER PRIMARY KEY, data JSONB)');

        foreach (JsonLengthSupport::documents() as $name => $expected) {
            $this->assertSame(
                ['whole' => $expected['whole'], 'items' => $expected['items'], 'meta' => $expected['meta'], 'n' => $expected['n']],
                $lengths[$name] ?? null,
                $name
            );
        }
    }
}
