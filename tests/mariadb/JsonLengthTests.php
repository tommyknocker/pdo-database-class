<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\tests\shared\JsonLengthSupport;

final class JsonLengthTests extends BaseMariaDBTestCase
{
    public function testLengthCountsArrayElementsAndObjectKeys(): void
    {
        $lengths = JsonLengthSupport::run(self::$db, 'CREATE TABLE json_len_test (id INT PRIMARY KEY, data JSON)');

        foreach (JsonLengthSupport::documents() as $name => $expected) {
            $this->assertSame(
                ['whole' => $expected['whole'], 'items' => $expected['items'], 'meta' => $expected['meta']],
                $lengths[$name] ?? null,
                $name
            );
        }
    }
}
