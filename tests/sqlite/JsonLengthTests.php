<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\JsonLengthSupport;

final class JsonLengthTests extends BaseSqliteTestCase
{
    public function testLengthCountsArrayElementsAndObjectKeys(): void
    {
        $lengths = JsonLengthSupport::run(self::$db, 'CREATE TABLE json_len_test (id INTEGER PRIMARY KEY, data TEXT)');

        foreach (JsonLengthSupport::documents() as $name => $expected) {
            $this->assertSame(
                ['whole' => $expected['whole'], 'items' => $expected['items'], 'meta' => $expected['meta']],
                $lengths[$name] ?? null,
                $name
            );
        }
    }
}
