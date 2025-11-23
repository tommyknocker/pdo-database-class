<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\dialects\mssql\MSSQLSqlFormatter;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Tests for MSSQLSqlFormatter.
 */
final class MSSQLSqlFormatterTests extends BaseMSSQLTestCase
{
    private MSSQLSqlFormatter $formatter;

    public function setUp(): void
    {
        parent::setUp();
        $connection = self::$db->connection;
        assert($connection !== null);
        $this->formatter = new MSSQLSqlFormatter($connection->getDialect());
    }

    public function testFormatLimitOffset(): void
    {
        $sql = 'SELECT * FROM users';

        // Test with limit only (MSSQL uses TOP)
        $result = $this->formatter->formatLimitOffset($sql, 10, null);
        $this->assertStringContainsString('TOP (10)', $result);
        $this->assertStringNotContainsString('OFFSET', $result);

        // Test with offset only (MSSQL requires ORDER BY for OFFSET)
        $sqlWithOrder = 'SELECT * FROM users ORDER BY id';
        $result = $this->formatter->formatLimitOffset($sqlWithOrder, null, 5);
        $this->assertStringContainsString('OFFSET', $result);
        $this->assertStringContainsString('ROWS', $result);

        // Test with both (MSSQL uses OFFSET...FETCH NEXT)
        $result = $this->formatter->formatLimitOffset($sqlWithOrder, 10, 5);
        $this->assertStringContainsString('OFFSET', $result);
        $this->assertStringContainsString('FETCH NEXT', $result);

        // Test with neither
        $result = $this->formatter->formatLimitOffset($sql, null, null);
        $this->assertEquals($sql, $result);
    }

    public function testFormatWindowFunction(): void
    {
        // Test ROW_NUMBER with partition and order
        $result = $this->formatter->formatWindowFunction(
            'ROW_NUMBER',
            [],
            ['user_id'],
            [['created_at' => 'DESC']],
            null
        );
        $this->assertStringContainsString('ROW_NUMBER()', $result);
        $this->assertStringContainsString('PARTITION BY', $result);
        $this->assertStringContainsString('ORDER BY', $result);
        $this->assertStringContainsString('[user_id]', $result);
        $this->assertStringContainsString('[created_at]', $result);

        // Test with frame clause
        $result = $this->formatter->formatWindowFunction(
            'ROW_NUMBER',
            [],
            [],
            [['id' => 'ASC']],
            'ROWS BETWEEN 1 PRECEDING AND 1 FOLLOWING'
        );
        $this->assertStringContainsString('ROWS BETWEEN', $result);
    }

    public function testFormatGroupConcat(): void
    {
        // Test without distinct (MSSQL uses STRING_AGG)
        $result = $this->formatter->formatGroupConcat('name', ',', false);
        $this->assertStringContainsString('STRING_AGG', $result);
        $this->assertStringNotContainsString('DISTINCT', $result);

        // Test with distinct
        $result = $this->formatter->formatGroupConcat('name', '|', true);
        $this->assertStringContainsString('DISTINCT', $result);

        // Test with RawValue
        $rawValue = new RawValue('CONCAT(first_name, last_name)');
        $result = $this->formatter->formatGroupConcat($rawValue, ',', false);
        $this->assertStringContainsString('CONCAT(first_name, last_name)', $result);
    }

    public function testFormatJsonGet(): void
    {
        // Test with array path (MSSQL uses JSON_VALUE for asText=true)
        $result = $this->formatter->formatJsonGet('data', ['user', 'name'], true);
        $this->assertStringContainsString('JSON_VALUE', $result);
        $this->assertStringContainsString('[data]', $result);

        // Test with string path (MSSQL uses JSON_QUERY for asText=false)
        $result = $this->formatter->formatJsonGet('data', '$.user.name', false);
        $this->assertStringContainsString('JSON_QUERY', $result);
    }

    public function testFormatJsonContains(): void
    {
        // MSSQL uses OPENJSON instead of JSON_CONTAINS
        $result = $this->formatter->formatJsonContains('data', ['key' => 'value'], '$.path');
        $this->assertIsArray($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertStringContainsString('OPENJSON', $result[0]);
        $this->assertIsArray($result[1]);
    }

    public function testFormatIfNull(): void
    {
        // MSSQL uses ISNULL instead of IFNULL
        $result = $this->formatter->formatIfNull('column', 'default');
        $this->assertStringContainsString('ISNULL', $result);
        $this->assertStringContainsString("'default'", $result);

        // Test with numeric default
        $result = $this->formatter->formatIfNull('column', 0);
        $this->assertStringContainsString('0', $result);
    }

    public function testFormatGreatest(): void
    {
        $result = $this->formatter->formatGreatest(['col1', 'col2', 10]);
        $this->assertStringContainsString('GREATEST', $result);
        $this->assertStringContainsString('col1', $result);
        $this->assertStringContainsString('col2', $result);
        $this->assertStringContainsString('10', $result);
    }

    public function testFormatLeast(): void
    {
        $result = $this->formatter->formatLeast(['col1', 'col2', 5]);
        $this->assertStringContainsString('LEAST', $result);
        $this->assertStringContainsString('col1', $result);
        $this->assertStringContainsString('col2', $result);
        $this->assertStringContainsString('5', $result);
    }

    public function testFormatSubstring(): void
    {
        // Test with length
        $result = $this->formatter->formatSubstring('column', 1, 10);
        $this->assertStringContainsString('SUBSTRING', $result);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('10', $result);

        // Test without length
        $result = $this->formatter->formatSubstring('column', 1, null);
        $this->assertStringContainsString('SUBSTRING', $result);
        $this->assertStringNotContainsString(', 10', $result);

        // Test with RawValue
        $rawValue = new RawValue('CONCAT(a, b)');
        $result = $this->formatter->formatSubstring($rawValue, 1, 5);
        $this->assertStringContainsString('CONCAT(a, b)', $result);
    }

    public function testFormatCurDate(): void
    {
        $result = $this->formatter->formatCurDate();
        $this->assertStringContainsString('GETDATE()', $result);
        $this->assertStringContainsString('CAST', $result);
    }

    public function testFormatCurTime(): void
    {
        $result = $this->formatter->formatCurTime();
        $this->assertStringContainsString('GETDATE()', $result);
        $this->assertStringContainsString('CAST', $result);
    }

    public function testFormatYear(): void
    {
        $result = $this->formatter->formatYear('created_at');
        $this->assertStringContainsString('YEAR', $result);
        $this->assertStringContainsString('created_at', $result);
    }

    public function testFormatMonth(): void
    {
        $result = $this->formatter->formatMonth('created_at');
        $this->assertStringContainsString('MONTH', $result);
    }

    public function testFormatDay(): void
    {
        $result = $this->formatter->formatDay('created_at');
        $this->assertStringContainsString('DAY', $result);
    }

    public function testFormatHour(): void
    {
        $result = $this->formatter->formatHour('created_at');
        $this->assertStringContainsString('HOUR', $result);
    }

    public function testFormatMinute(): void
    {
        $result = $this->formatter->formatMinute('created_at');
        $this->assertStringContainsString('MINUTE', $result);
    }

    public function testFormatSecond(): void
    {
        $result = $this->formatter->formatSecond('created_at');
        $this->assertStringContainsString('SECOND', $result);
    }

    public function testFormatDateOnly(): void
    {
        $result = $this->formatter->formatDateOnly('created_at');
        $this->assertStringContainsString('DATE', $result);
        $this->assertStringContainsString('created_at', $result);
    }

    public function testFormatTimeOnly(): void
    {
        $result = $this->formatter->formatTimeOnly('created_at');
        $this->assertStringContainsString('TIME', $result);
        $this->assertStringContainsString('created_at', $result);
    }

    public function testFormatInterval(): void
    {
        // Test DATEADD (MSSQL uses DATEADD instead of DATE_ADD/DATE_SUB)
        $result = $this->formatter->formatInterval('created_at', '1', 'DAY', true);
        $this->assertStringContainsString('DATEADD', $result);
        $this->assertStringContainsString('day', $result);

        // Test DATEADD with subtraction (negative value)
        $result = $this->formatter->formatInterval('created_at', '1', 'MONTH', false);
        $this->assertStringContainsString('DATEADD', $result);
        $this->assertStringContainsString('month', $result);
    }

    public function testFormatTruncate(): void
    {
        // MSSQL uses ROUND with third parameter for truncation
        $result = $this->formatter->formatTruncate('price', 2);
        $this->assertStringContainsString('ROUND', $result);
        $this->assertStringContainsString('price', $result);
        $this->assertStringContainsString('2', $result);
    }

    public function testFormatPosition(): void
    {
        // MSSQL uses CHARINDEX instead of POSITION
        $result = $this->formatter->formatPosition('substring', 'column');
        $this->assertStringContainsString('CHARINDEX', $result);
    }

    public function testFormatLeft(): void
    {
        $result = $this->formatter->formatLeft('name', 5);
        $this->assertStringContainsString('LEFT', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('5', $result);
    }

    public function testFormatRight(): void
    {
        $result = $this->formatter->formatRight('name', 5);
        $this->assertStringContainsString('RIGHT', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('5', $result);
    }

    public function testFormatRepeat(): void
    {
        // MSSQL uses REPLICATE instead of REPEAT
        $result = $this->formatter->formatRepeat('x', 3);
        $this->assertStringContainsString('REPLICATE', $result);
        $this->assertStringContainsString('x', $result);
        $this->assertStringContainsString('3', $result);
    }

    public function testFormatReverse(): void
    {
        $result = $this->formatter->formatReverse('name');
        $this->assertStringContainsString('REVERSE', $result);
        $this->assertStringContainsString('name', $result);
    }

    public function testFormatPad(): void
    {
        // Test LPAD (MSSQL uses REPLICATE + concatenation)
        $result = $this->formatter->formatPad('name', 10, '0', true);
        $this->assertStringContainsString('REPLICATE', $result);
        $this->assertStringContainsString('RIGHT', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('10', $result);

        // Test RPAD (MSSQL uses REPLICATE + concatenation)
        $result = $this->formatter->formatPad('name', 10, '0', false);
        $this->assertStringContainsString('REPLICATE', $result);
        $this->assertStringContainsString('LEFT', $result);
    }

    public function testFormatRegexpMatch(): void
    {
        // MSSQL uses dbo.regexp_match
        $result = $this->formatter->formatRegexpMatch('column', 'pattern');
        $this->assertStringContainsString('regexp_match', $result);
        $this->assertStringContainsString('column', $result);
    }

    public function testFormatRegexpReplace(): void
    {
        // MSSQL uses dbo.regexp_replace
        $result = $this->formatter->formatRegexpReplace('column', 'pattern', 'replacement');
        $this->assertStringContainsString('regexp_replace', $result);
        $this->assertStringContainsString('column', $result);
    }

    public function testFormatRegexpExtract(): void
    {
        // MSSQL uses dbo.regexp_extract
        $result = $this->formatter->formatRegexpExtract('column', 'pattern');
        $this->assertStringContainsString('regexp_extract', $result);
        $this->assertStringContainsString('column', $result);
    }

    public function testFormatFulltextMatch(): void
    {
        // MSSQL uses FREETEXT or CONTAINS
        $result = $this->formatter->formatFulltextMatch('title', 'search term', 'natural', false);
        $this->assertIsArray($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertStringContainsString('FREETEXT', $result[0]);

        // Test with multiple columns
        $result = $this->formatter->formatFulltextMatch(['title', 'content'], 'search', null, true);
        $this->assertStringContainsString('FREETEXT', $result[0]);
    }

    public function testFormatMaterializedCte(): void
    {
        $cteSql = 'SELECT * FROM users';

        // Test without materialization
        $result = $this->formatter->formatMaterializedCte($cteSql, false);
        $this->assertEquals($cteSql, $result);

        // Test with materialization (MSSQL doesn't support materialized CTEs)
        $result = $this->formatter->formatMaterializedCte($cteSql, true);
        $this->assertEquals($cteSql, $result);
    }

    public function testFormatLateralJoin(): void
    {
        // Test CROSS APPLY
        $result = $this->formatter->formatLateralJoin('(SELECT * FROM orders)', 'INNER', '[o]', null);
        $this->assertStringContainsString('CROSS APPLY', $result);
        $this->assertStringContainsString('[o]', $result);

        // Test OUTER APPLY
        $result = $this->formatter->formatLateralJoin('(SELECT * FROM orders)', 'LEFT', '[o]', null);
        $this->assertStringContainsString('OUTER APPLY', $result);
    }

    public function testFormatMod(): void
    {
        $result = $this->formatter->formatMod('dividend', 'divisor');
        $this->assertStringContainsString('%', $result);
        $this->assertStringContainsString('dividend', $result);
        $this->assertStringContainsString('divisor', $result);
    }

    public function testNormalizeRawValue(): void
    {
        // Test LENGTH -> LEN
        $sql = 'SELECT LENGTH(column) FROM table';
        $result = $this->formatter->normalizeRawValue($sql);
        $this->assertStringContainsString('LEN(', $result);
        $this->assertStringNotContainsString('LENGTH(', $result);

        // Test CEIL -> CEILING
        $sql = 'SELECT CEIL(value) FROM table';
        $result = $this->formatter->normalizeRawValue($sql);
        $this->assertStringContainsString('CEILING(', $result);

        // Test TRUE -> 1
        $sql = 'SELECT * FROM table WHERE flag = TRUE';
        $result = $this->formatter->normalizeRawValue($sql);
        $this->assertStringContainsString('= 1', $result);

        // Test FALSE -> 0
        $sql = 'SELECT * FROM table WHERE flag = FALSE';
        $result = $this->formatter->normalizeRawValue($sql);
        $this->assertStringContainsString('= 0', $result);

        // Test quoted identifiers are preserved
        $sql = 'SELECT [LENGTH] FROM table';
        $result = $this->formatter->normalizeRawValue($sql);
        $this->assertStringContainsString('[LENGTH]', $result);
    }
}
