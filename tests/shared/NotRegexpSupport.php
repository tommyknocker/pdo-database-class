<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * Db::not(Db::regexpMatch()) has to be rendered in the connected dialect's regular expression syntax.
 */
final class NotRegexpSupport
{
    /**
     * @param string $createSql CREATE TABLE statement for a table named not_regexp_probe with id and email columns
     *
     * @return list<string> Emails that do not match the pattern
     */
    public static function emailsNotMatching(PdoDb $db, string $createSql): array
    {
        try {
            $db->rawQuery('DROP TABLE not_regexp_probe');
        } catch (\Throwable) {
            // not there yet
        }
        $db->rawQuery($createSql);
        foreach (['user@example.com', 'invalid-email', 'other@test.org'] as $i => $email) {
            $db->find()->table('not_regexp_probe')->insert(['id' => $i + 1, 'email' => $email]);
        }

        try {
            $rows = $db->find()
                ->from('not_regexp_probe')
                ->where(Db::not(Db::regexpMatch('email', '@')))
                ->get();

            return array_values(array_map(
                static fn (array $row): string => (string) array_change_key_case($row, CASE_LOWER)['email'],
                $rows
            ));
        } finally {
            $db->rawQuery('DROP TABLE not_regexp_probe');
        }
    }
}
