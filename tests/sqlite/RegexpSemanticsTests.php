<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use PHPUnit\Framework\Attributes\DataProvider;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\shared\RegexpSemanticsSupport;

final class RegexpSemanticsTests extends BaseSqliteTestCase
{
    public function testRegexpMatchSelectsTheSameRowsAsPhp(): void
    {
        foreach (RegexpSemanticsSupport::run(self::$db, 'CREATE TABLE regexp_semantics (id INTEGER PRIMARY KEY, txt TEXT)') as $pattern => $rows) {
            $this->assertSame($rows['expected'], $rows['actual'], "pattern: {$pattern}");
        }
    }

    public function testASlashIsLiteralWhetherOrNotItIsEscaped(): void
    {
        $match = static fn (string $subject, string $pattern): int => (int)self::$db->rawQueryValue('SELECT ? REGEXP ?', [$subject, $pattern]);

        $this->assertSame(1, $match('a/b', 'a/b'), 'unescaped slash');
        $this->assertSame(1, $match('a/b', 'a\\/b'), 'already escaped slash');
        $this->assertSame(1, $match('a\\/b', 'a\\\\/b'), 'escaped backslash, then a slash');
        $this->assertSame(0, $match('a-b', 'a/b'));
    }

    /**
     * @return array<string, array{0: callable(PdoDb): mixed}>
     */
    public static function invalidPatternOperations(): array
    {
        return [
            'REGEXP' => [static fn ($db) => $db->find()->from('regexp_invalid')->where(Db::regexpMatch('txt', '('))->get()],
            'NOT REGEXP' => [static fn ($db) => $db->find()->from('regexp_invalid')->where(Db::not(Db::regexpMatch('txt', 'a[')))->get()],
            'regexp_replace' => [static fn ($db) => $db->find()->from('regexp_invalid')->select(['v' => Db::regexpReplace('txt', '*a', 'x')])->get()],
            'regexp_extract' => [static fn ($db) => $db->find()->from('regexp_invalid')->select(['v' => Db::regexpExtract('txt', '(')])->get()],
        ];
    }

    #[DataProvider('invalidPatternOperations')]
    public function testAnInvalidPatternThrowsLikeOnTheOtherDatabases(callable $operation): void
    {
        self::$db->rawQuery('CREATE TABLE regexp_invalid (id INTEGER PRIMARY KEY, txt TEXT)');
        self::$db->find()->table('regexp_invalid')->insert(['txt' => 'abc']);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches("/^Invalid regular expression '.+': /");
            $operation(self::$db);
        } finally {
            restore_error_handler();
            $this->assertSame([], $warnings, 'no PHP warning escapes');
            self::$db->rawQuery('DROP TABLE regexp_invalid');
        }
    }

    public function testAnInvalidPatternDoesNotWipeTheColumnItIsAppliedTo(): void
    {
        self::$db->rawQuery('CREATE TABLE regexp_invalid (id INTEGER PRIMARY KEY, txt TEXT)');
        self::$db->find()->table('regexp_invalid')->insert(['txt' => 'abc']);

        try {
            self::$db->rawQuery("UPDATE regexp_invalid SET txt = regexp_replace(txt, '(', 'x')");
            $this->fail('the invalid pattern has to throw');
        } catch (QueryException) {
            $this->assertSame('abc', self::$db->rawQueryValue('SELECT txt FROM regexp_invalid'));
        } finally {
            self::$db->rawQuery('DROP TABLE regexp_invalid');
        }
    }

    public function testReplaceAndExtractWorkOnFixedText(): void
    {
        foreach (RegexpSemanticsSupport::runTextOperations(self::$db, 'CREATE TABLE regexp_semantics (id INTEGER PRIMARY KEY, txt TEXT)') as $case => $values) {
            $this->assertSame($values['expected'], $values['actual'], $case);
        }
    }
}
