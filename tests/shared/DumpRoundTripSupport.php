<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\DumpManager;
use tommyknocker\pdodb\PdoDb;

/**
 * Dump a table with values that are hard to write as SQL, restore it into an empty schema and compare. Newlines,
 * backslashes, semicolons and comment markers inside strings must survive.
 */
final class DumpRoundTripSupport
{
    /** @var list<string> */
    public const VALUES = [
        "line1\nline2\n\nline4",
        'back\\slash and \\\\ double',
        'semi;colon; and more;',
        '-- not a comment',
        '/* nor this */ text',
        "it's a 'quoted' value",
        'unicode: ünï ✓',
        'trailing space ',
    ];

    /**
     * @param string $createSql CREATE TABLE for dump_roundtrip (id integer primary key, txt string, num integer)
     *
     * @return array{before: list<array<string, mixed>>, after: list<array<string, mixed>>}
     */
    public static function run(PdoDb $db, string $createSql): array
    {
        self::drop($db);
        $db->rawQuery($createSql);

        try {
            foreach (self::VALUES as $i => $value) {
                $db->find()->table('dump_roundtrip')->insert(['id' => $i + 1, 'txt' => $value, 'num' => $i * 10]);
            }
            $db->find()->table('dump_roundtrip')->insert(['id' => 100, 'txt' => null, 'num' => null]);

            $before = self::rows($db);
            $script = DumpManager::dump($db, 'dump_roundtrip');
            self::drop($db);

            $file = sys_get_temp_dir() . '/dump_roundtrip_' . bin2hex(random_bytes(4)) . '.sql';
            file_put_contents($file, $script);

            try {
                DumpManager::restore($db, $file);
            } finally {
                unlink($file);
            }

            return ['before' => $before, 'after' => self::rows($db)];
        } finally {
            self::drop($db);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(PdoDb $db): array
    {
        return array_map(
            static fn (array $row): array => array_change_key_case($row, CASE_LOWER),
            $db->find()->from('dump_roundtrip')->orderBy('id')->get()
        );
    }

    private static function drop(PdoDb $db): void
    {
        try {
            $db->schema()->dropTableIfExists('dump_roundtrip');
        } catch (\Throwable) {
            // nothing to drop
        }
    }
}
