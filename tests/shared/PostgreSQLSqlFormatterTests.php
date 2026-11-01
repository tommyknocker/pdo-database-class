<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLSqlFormatter;

/**
 * SQL text produced by the PostgreSQL formatter; needs no server.
 */
final class PostgreSQLSqlFormatterTests extends TestCase
{
    private PostgreSQLSqlFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new PostgreSQLSqlFormatter(new PostgreSQLDialect());
    }

    public function testLimitAndOffset(): void
    {
        $this->assertSame('SELECT * FROM t LIMIT 10', $this->formatter->formatLimitOffset('SELECT * FROM t', 10, null));
        $this->assertSame('SELECT * FROM t LIMIT 10 OFFSET 5', $this->formatter->formatLimitOffset('SELECT * FROM t', 10, 5));
        $this->assertSame('SELECT * FROM t OFFSET 5', $this->formatter->formatLimitOffset('SELECT * FROM t', null, 5));
        $this->assertSame('SELECT * FROM t', $this->formatter->formatLimitOffset('SELECT * FROM t', null, null));
    }

    public function testJsonAccessUsesArrayPaths(): void
    {
        $this->assertSame('"data" #>> ARRAY[\'a\',\'b\']', $this->formatter->formatJsonGet('data', ['a', 'b'], true));
        $this->assertSame('"data" #> ARRAY[\'a\',\'b\']', $this->formatter->formatJsonGet('data', 'a.b', false));
        $this->assertSame('("data"::jsonb #- \'{a,b}\')::jsonb', $this->formatter->formatJsonRemove('data', ['a', 'b']));
    }

    public function testJsonKeysListsTheKeysOfAnObject(): void
    {
        $this->assertSame(
            'CASE WHEN jsonb_typeof("data"::jsonb) = \'object\' THEN (SELECT jsonb_agg(k) FROM jsonb_object_keys("data"::jsonb) AS k) END',
            $this->formatter->formatJsonKeys('data')
        );
        $this->assertStringContainsString('"data"::jsonb #> ARRAY[\'a\']', $this->formatter->formatJsonKeys('data', ['a']));
    }

    public function testRegexpExtractWrapsThePatternSoTheWholeMatchComesFirst(): void
    {
        $this->assertSame("(regexp_match(s, '(a)'))[1]", $this->formatter->formatRegexpExtract('s', 'a'));
        $this->assertSame("(regexp_match(s, '((a))'))[2]", $this->formatter->formatRegexpExtract('s', '(a)', 1));
        $this->assertSame("(regexp_match(s, '(?i)(a|b)'))[1]", $this->formatter->formatRegexpExtract('s', '(?i)a|b'));
        $this->assertSame("(regexp_match(s, '(it''s)'))[1]", $this->formatter->formatRegexpExtract('s', "it's"));
    }

    public function testStringAndDateHelpers(): void
    {
        $this->assertSame("COALESCE(a, 'x')", $this->formatter->formatIfNull('a', 'x'));
        $this->assertSame('SUBSTRING(name FROM 2 FOR 3)', $this->formatter->formatSubstring('name', 2, 3));
        $this->assertSame('SUBSTRING(name FROM 2)', $this->formatter->formatSubstring('name', 2, null));
        $this->assertSame('EXTRACT(YEAR FROM d)', $this->formatter->formatYear('d'));
        $this->assertSame("d + INTERVAL '1 DAY'", $this->formatter->formatInterval('d', '1', 'DAY', true));
        $this->assertSame("d - INTERVAL '2 MONTH'", $this->formatter->formatInterval('d', '2', 'MONTH', false));
        $this->assertSame("STRING_AGG(DISTINCT name, ', ')", $this->formatter->formatGroupConcat('name', ', ', true));
        $this->assertSame("LPAD(s, 5, '*')", $this->formatter->formatPad('s', 5, '*', true));
        $this->assertSame('GREATEST(a, b)', $this->formatter->formatGreatest(['a', 'b']));
    }

    public function testWindowFunctionQuotesItsColumns(): void
    {
        $this->assertSame(
            'ROW_NUMBER() OVER (PARTITION BY "u" ORDER BY "c" DESC)',
            $this->formatter->formatWindowFunction('ROW_NUMBER', [], ['u'], [['c' => 'DESC']], null)
        );
    }
}
