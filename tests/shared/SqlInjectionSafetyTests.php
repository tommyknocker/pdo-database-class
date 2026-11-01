<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\mariadb\MariaDBDialect;
use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\dialects\oracle\OracleDialect;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\sqlite\SqliteDialect;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\query\QueryConstants;

/**
 * Identifiers and operators are written into SQL text, so they must never allow breaking out of their token.
 */
final class SqlInjectionSafetyTests extends BaseSharedTestCase
{
    /**
     * @return array<string, array{0: DialectInterface, 1: string}>
     */
    public static function identifierQuoting(): array
    {
        return [
            'sqlite' => [new SqliteDialect(), '"a""b"'],
            'postgresql' => [new PostgreSQLDialect(), '"a""b"'],
            'mysql' => [new MySQLDialect(), '`a``b`'],
            'mariadb' => [new MariaDBDialect(), '`a``b`'],
            'mssql' => [new MSSQLDialect(), '[a]]b]'],
            'oracle' => [new OracleDialect(), '"A""B"'],
        ];
    }

    #[DataProvider('identifierQuoting')]
    public function testQuoteIdentifierDoublesTheClosingQuote(DialectInterface $dialect, string $expected): void
    {
        $quote = $dialect->quoteIdentifier(match (true) {
            $dialect instanceof MySQLDialect, $dialect instanceof MariaDBDialect => 'a`b',
            $dialect instanceof MSSQLDialect => 'a]b',
            default => 'a"b',
        });

        $this->assertSame($expected, $quote);
    }

    #[DataProvider('identifierQuoting')]
    public function testQuotedIdentifierRoundTripsAHostileName(DialectInterface $dialect): void
    {
        [$open, $close] = match (true) {
            $dialect instanceof MySQLDialect, $dialect instanceof MariaDBDialect => ['`', '`'],
            $dialect instanceof MSSQLDialect => ['[', ']'],
            default => ['"', '"'],
        };
        $hostile = $close . '; DROP TABLE users; --' . $close . $close;
        $quoted = $dialect->quoteIdentifier($hostile);

        // Undoing the quoting must give back the original name: nothing escaped the delimiters.
        $this->assertStringStartsWith($open, $quoted);
        $this->assertStringEndsWith($close, $quoted);
        $unescaped = str_replace($close . $close, $close, substr($quoted, 1, -1));
        $this->assertSame($dialect instanceof OracleDialect ? strtoupper($hostile) : $hostile, $unescaped);
    }

    public function testConditionOperatorsAreNormalizedAndRestrictedToAnAllowlist(): void
    {
        $this->assertSame('=', QueryConstants::normalizeConditionOperator(' = '));
        $this->assertSame('NOT IN', QueryConstants::normalizeConditionOperator("not \t in"));
        $this->assertSame('LIKE', QueryConstants::normalizeConditionOperator('like'));
        $this->assertSame('IS NOT', QueryConstants::normalizeConditionOperator('is   not'));

        foreach (['= 1 OR 1=1 --', '=; DROP TABLE t', '', 'LIKE ESCAPE', "= '1'", '== 1'] as $hostile) {
            try {
                QueryConstants::normalizeConditionOperator($hostile);
                $this->fail('Hostile operator was accepted: ' . $hostile);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testQueryBuilderRejectsHostileOperators(): void
    {
        $queries = [
            fn () => self::$db->find()->from('test_coverage')->where('id', 1, '= 1 OR 1=1 --'),
            fn () => self::$db->find()->from('test_coverage')->orWhere('id', 1, '; DROP TABLE x'),
            fn () => self::$db->find()->from('test_coverage')->having('id', 1, '> 0 UNION SELECT 1'),
            fn () => self::$db->find()->from('test_coverage')->whereColumn('id', '= id OR 1=1 --', 'value'),
        ];

        foreach ($queries as $build) {
            try {
                $build();
                $this->fail('Hostile operator reached the query builder.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testQueryBuilderAcceptsLowercaseAndSpacedOperators(): void
    {
        $sql = self::$db->find()->from('test_coverage')->where('id', [1, 2], 'not   in')->where('name', 'a%', 'like')->toSQL()['sql'];

        $this->assertStringContainsString('NOT IN', $sql);
        $this->assertStringContainsString('LIKE', $sql);
    }

    public function testAggregateSeparatorAndPaddingAreWrittenAsLiterals(): void
    {
        self::$db->rawQuery('DELETE FROM test_coverage');
        self::$db->find()->table('test_coverage')->insert(['name' => 'a', 'value' => 1]);
        self::$db->find()->table('test_coverage')->insert(['name' => 'b', 'value' => 2]);
        $dialect = self::$db->connection->getDialect();
        $hostile = "'; DROP TABLE test_coverage; --";

        $joined = self::$db->rawQueryValue(
            'SELECT ' . $dialect->formatGroupConcat('name', $hostile, false) . ' FROM test_coverage'
        );
        $this->assertIsString($joined);
        $this->assertStringContainsString($hostile, $joined);

        $padded = self::$db->rawQueryValue(
            'SELECT ' . $dialect->formatPad("'x'", 40, "'; DROP TABLE test_coverage; --", true) . ' FROM test_coverage LIMIT 1'
        );
        $this->assertIsString($padded);
        $this->assertSame(2, (int) self::$db->rawQueryValue('SELECT COUNT(*) FROM test_coverage'));
    }

    public function testReplaceHelperBindsItsStringArguments(): void
    {
        $raw = Db::replace('name', "a'; DROP TABLE test_coverage; --", 'z');

        $this->assertStringNotContainsString('DROP TABLE', $raw->getValue());
        $this->assertCount(2, $raw->getParams());
    }

    /**
     * @return array<string, array{0: DialectInterface}>
     */
    public static function dialects(): array
    {
        return array_map(static fn (array $row): array => [$row[0]], self::identifierQuoting());
    }

    #[DataProvider('dialects')]
    public function testJsonPathsKeepHostileKeysInsideTheirLiteral(DialectInterface $dialect): void
    {
        $hostile = ["a'b", "x') OR 1=1 --", 'k"q', 'dot.ted'];
        $statements = [
            $dialect->formatJsonGet('data', $hostile),
            $dialect->formatJsonExists('data', $hostile, 1),
            $dialect->formatJsonSet('data', $hostile, 'v')[0],
            $dialect->formatJsonRemove('data', $hostile),
            $dialect->formatJsonReplace('data', $hostile, 'v')[0],
        ];

        foreach ($statements as $sql) {
            // Every quote must belong to a closed literal: doubled quotes cancel out, the rest must pair up.
            $this->assertSame(0, substr_count(str_replace(["\\'", "''"], '', $sql), "'") % 2, $sql);
            $this->assertStringNotContainsString("x') OR 1=1", $sql);
        }
    }

    #[DataProvider('dialects')]
    public function testIntrospectionSqlKeepsHostileTableNamesInsideTheirLiteral(DialectInterface $dialect): void
    {
        $hostile = "users' OR '1'='1";
        foreach ([$dialect->buildTableExistsSql($hostile), $dialect->buildDescribeSql($hostile)] as $sql) {
            $this->assertSame(0, substr_count(str_replace(["\\'", "''"], '', $sql), "'") % 2, $sql);
        }
    }

    public function testNowRejectsIntervalsThatAreNotIntervals(): void
    {
        foreach ([new SqliteDialect(), new PostgreSQLDialect(), new MySQLDialect(), new OracleDialect()] as $dialect) {
            $this->expectExceptionObject(new InvalidArgumentException('Unsupported date interval expression.'));
            $dialect->now("1 day') ; DROP TABLE users; --");
        }
    }

    public function testStringLiteralsEscapeBackslashesWhereTheyAreEscapeCharacters(): void
    {
        $this->assertSame("'a''b'", (new SqliteDialect())->quoteStringLiteral("a'b"));
        $this->assertSame("'a''b'", (new MSSQLDialect())->quoteStringLiteral("a'b"));
        $this->assertSame("'a\\\\''b'", (new MySQLDialect())->quoteStringLiteral("a\\'b"));
        $this->assertSame("E'a\\\\''b'", (new PostgreSQLDialect())->quoteStringLiteral("a\\'b"));
        $this->assertSame("'plain'", (new PostgreSQLDialect())->quoteStringLiteral('plain'));
    }
}
