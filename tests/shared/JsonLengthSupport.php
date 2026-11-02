<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * Db::jsonLength() counts the elements of an array and the keys of an object, whichever dialect runs it.
 */
final class JsonLengthSupport
{
    /**
     * @return array<string, array{json: string, whole: int, items: int, meta: int, n: int}>
     */
    public static function documents(): array
    {
        return [
            'array' => ['json' => '[1,2,3]', 'whole' => 3, 'items' => 0, 'meta' => 0, 'n' => 0],
            'object' => ['json' => '{"a":1,"b":2}', 'whole' => 2, 'items' => 0, 'meta' => 0, 'n' => 0],
            'empty array' => ['json' => '[]', 'whole' => 0, 'items' => 0, 'meta' => 0, 'n' => 0],
            'empty object' => ['json' => '{}', 'whole' => 0, 'items' => 0, 'meta' => 0, 'n' => 0],
            'scalar member' => ['json' => '{"n":5}', 'whole' => 1, 'items' => 0, 'meta' => 0, 'n' => 1],
            'nested' => ['json' => '{"items":[1,2,3,4],"meta":{"x":1,"y":2,"z":3},"n":"text"}', 'whole' => 3, 'items' => 4, 'meta' => 3, 'n' => 1],
        ];
    }

    /**
     * @param string $createSql CREATE TABLE json_len_test (id integer primary key, data <json column>)
     *
     * @return array<string, array{whole: int, items: int, meta: int, n: int}> Lengths by document name
     */
    public static function run(PdoDb $db, string $createSql): array
    {
        self::drop($db);
        $db->rawQuery($createSql);

        try {
            $ids = [];
            $id = 0;
            foreach (self::documents() as $name => $document) {
                $id++;
                $ids[$name] = $id;
                $db->find()->table('json_len_test')->insert(['id' => $id, 'data' => $document['json']]);
            }

            $rows = $db->find()->from('json_len_test')->select([
                'id',
                'whole' => Db::jsonLength('data'),
                'items' => Db::jsonLength('data', ['items']),
                'meta' => Db::jsonLength('data', ['meta']),
                'n' => Db::jsonLength('data', ['n']),
            ])->orderBy('id')->get();

            $result = [];
            foreach ($rows as $row) {
                $row = array_change_key_case($row, CASE_LOWER);
                $name = array_search((int)$row['id'], $ids, true);
                $result[(string)$name] = ['whole' => (int)$row['whole'], 'items' => (int)$row['items'], 'meta' => (int)$row['meta'], 'n' => (int)$row['n']];
            }

            return $result;
        } finally {
            self::drop($db);
        }
    }

    private static function drop(PdoDb $db): void
    {
        try {
            $db->schema()->dropTableIfExists('json_len_test');
        } catch (\Throwable) {
            // nothing to drop
        }
    }
}
