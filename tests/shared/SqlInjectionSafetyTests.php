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
}
