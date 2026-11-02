<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * Db::regexpMatch() has to select the same rows on every dialect as PHP's preg_match() does, for the patterns every
 * dialect can express (literals, anchors, ".", ".*", classes, \d).
 */
final class RegexpSemanticsSupport
{
    /** @var list<string> */
    public const VALUES = ['abc', 'xabcx', 'a.c', 'a1c', 'abcabc', 'a-c', '50%_off', 'user@example.com', '2024-05-17', 'plain', 'x[1]'];

    /** @var list<string> */
    public const PATTERNS = [
        'abc', '^abc', 'abc$', '^abc$', 'a.c', '^a.c$', 'a\.c', 'a.*c', '^a.*c$', 'a.+c', '[ab]c', '^[a-x]', '[^a-z]',
        '\d', '^\d\d\d\d-\d\d-\d\d$', '@', 'e.*e', '50%', '_off', '\[1\]', '^$', 'user@.*\.com$',
    ];

    /**
     * @param string $createSql CREATE TABLE regexp_semantics (id integer primary key, txt <string column>)
     *
     * @return array<string, array{expected: list<string>, actual: list<string>}> By pattern
     */
    public static function run(PdoDb $db, string $createSql): array
    {
        self::drop($db);
        $db->rawQuery($createSql);

        try {
            foreach (self::VALUES as $i => $value) {
                $db->find()->table('regexp_semantics')->insert(['id' => $i + 1, 'txt' => $value]);
            }

            $result = [];
            foreach (self::PATTERNS as $pattern) {
                $rows = $db->find()->from('regexp_semantics')->where(Db::regexpMatch('txt', $pattern))->orderBy('id')->get();
                $actual = array_values(array_map(
                    static fn (array $row): string => (string)array_change_key_case($row, CASE_LOWER)['txt'],
                    $rows
                ));
                $expected = array_values(array_filter(
                    self::VALUES,
                    static fn (string $value): bool => preg_match('/' . str_replace('/', '\/', $pattern) . '/', $value) === 1
                ));
                $result[$pattern] = ['expected' => $expected, 'actual' => $actual];
            }

            return $result;
        } finally {
            self::drop($db);
        }
    }

    /**
     * Replace and extract with a fixed text, which every dialect (SQL Server included) can do.
     *
     * @param string $createSql CREATE TABLE regexp_semantics (id integer primary key, txt <string column>)
     *
     * @return array<string, array{expected: string, actual: string}> By "helper pattern on value"
     */
    public static function runTextOperations(PdoDb $db, string $createSql): array
    {
        self::drop($db);
        $db->rawQuery($createSql);

        try {
            $db->find()->table('regexp_semantics')->insert(['id' => 1, 'txt' => 'abcabc']);
            $db->find()->table('regexp_semantics')->insert(['id' => 2, 'txt' => '2024-05-17']);
            $db->find()->table('regexp_semantics')->insert(['id' => 3, 'txt' => "it's 50%"]);

            $cases = [
                'replace b on abcabc' => [1, Db::regexpReplace('txt', 'b', '_'), 'a_ca_c'],
                'replace dash on date' => [2, Db::regexpReplace('txt', '-', ''), '20240517'],
                'remove apostrophe' => [3, Db::regexpReplace('txt', "'", ''), 'its 50%'],
                'replace percent' => [3, Db::regexpReplace('txt', '%', ' percent'), "it's 50 percent"],
                'extract bc' => [1, Db::regexpExtract('txt', 'bc'), 'bc'],
                'extract dash' => [2, Db::regexpExtract('txt', '-'), '-'],
            ];

            $result = [];
            foreach ($cases as $label => [$id, $expression, $expected]) {
                $row = $db->find()->from('regexp_semantics')->select(['v' => $expression])->where('id', $id)->getOne();
                $result[$label] = ['expected' => $expected, 'actual' => (string)(is_array($row) ? array_change_key_case($row, CASE_LOWER)['v'] : '')];
            }

            return $result;
        } finally {
            self::drop($db);
        }
    }

    private static function drop(PdoDb $db): void
    {
        try {
            $db->schema()->dropTableIfExists('regexp_semantics');
        } catch (\Throwable) {
            // nothing to drop
        }
    }
}
