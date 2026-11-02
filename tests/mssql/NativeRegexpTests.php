<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;
use tommyknocker\pdodb\helpers\Db;

/**
 * What only SQL Server 2025 can do: real regular expressions. Skipped on older servers, where the same patterns are
 * rejected (see MSSQLRegexTranslatorTests).
 */
final class NativeRegexpTests extends BaseMSSQLTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $dialect = self::$db->connection->getDialect();
        if (!$dialect instanceof MSSQLDialect || !$dialect->supportsNativeRegexp()) {
            $this->markTestSkipped('Native regular expressions need SQL Server 2025 (set PDODB_MSSQL_TEST_PORT to a 2025 server)');
        }
        self::$db->schema()->dropTableIfExists('native_regexp');
        self::$db->rawQuery('CREATE TABLE native_regexp (id INT PRIMARY KEY, txt NVARCHAR(100))');
        foreach (['user@example.com', 'order-2024-0517', 'aaab', 'ab', 'b', 'Привет мир'] as $i => $value) {
            self::$db->find()->table('native_regexp')->insert(['id' => $i + 1, 'txt' => $value]);
        }
    }

    public function tearDown(): void
    {
        self::$db->schema()->dropTableIfExists('native_regexp');
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function matching(string $pattern): array
    {
        $rows = self::$db->find()->from('native_regexp')->where(Db::regexpMatch('txt', $pattern))->orderBy('id')->get();

        return array_map(static fn (array $row): string => (string)$row['txt'], $rows);
    }

    private function value(int $id, mixed $expression): mixed
    {
        $row = self::$db->find()->from('native_regexp')->select(['v' => $expression])->where('id', $id)->getOne();

        return is_array($row) ? $row['v'] : null;
    }

    public function testQuantifiersAlternationAndGroupsMatch(): void
    {
        $this->assertSame(['aaab', 'ab'], $this->matching('^a+b$'));
        $this->assertSame(['aaab', 'ab', 'b'], $this->matching('^a*b$'));
        $this->assertSame(['ab', 'b'], $this->matching('^(a|)b$'));
        $this->assertSame(['order-2024-0517'], $this->matching('^[a-z]+-\d{4}-\d{4}$'));
        $this->assertSame(['user@example.com'], $this->matching('^[\w.]+@[\w.]+\.[a-z]{2,}$'));
    }

    public function testNonAsciiTextMatches(): void
    {
        $this->assertSame(['Привет мир'], $this->matching('^Привет'));
    }

    public function testReplaceWorksWithPatterns(): void
    {
        $this->assertSame('order--', $this->value(2, Db::regexpReplace('txt', '[0-9]+', '')));
        $this->assertSame('user@example.com', $this->value(1, Db::regexpReplace('txt', '\s+', '')));
    }

    public function testExtractReturnsTheMatchAndTheCaptureGroup(): void
    {
        $this->assertSame('2024-0517', $this->value(2, Db::regexpExtract('txt', '[0-9]+-[0-9]+')));
        $this->assertSame('example.com', $this->value(1, Db::regexpExtract('txt', '@(.+)$', 1)));
        $this->assertSame('2024', $this->value(2, Db::regexpExtract('txt', '([0-9]+)-([0-9]+)', 1)));
        $this->assertSame('0517', $this->value(2, Db::regexpExtract('txt', '([0-9]+)-([0-9]+)', 2)));
    }

    public function testNegationUsesTheNativePredicate(): void
    {
        $rows = self::$db->find()->from('native_regexp')->where(Db::not(Db::regexpMatch('txt', '^a+b$')))->orderBy('id')->get();

        $this->assertSame(['user@example.com', 'order-2024-0517', 'b', 'Привет мир'], array_map(static fn (array $row): string => (string)$row['txt'], $rows));
    }
}
