<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\dialects\mysql\MySQLSqlFormatter;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Tests for MySQLSqlFormatter.
 */
final class MySQLSqlFormatterTests extends BaseMySQLTestCase
{
    private MySQLSqlFormatter $formatter;

    public function setUp(): void
    {
        parent::setUp();
        $connection = self::$db->connection;
        assert($connection !== null);
        $this->formatter = new MySQLSqlFormatter($connection->getDialect());
    }

    public function testFormatLimitOffset(): void
    {
        $sql = 'SELECT * FROM users';

        // Test with limit only
        $result = $this->formatter->formatLimitOffset($sql, 10, null);
        $this->assertStringContainsString('LIMIT 10', $result);
        $this->assertStringNotContainsString('OFFSET', $result);

        // Test with offset only
        $result = $this->formatter->formatLimitOffset($sql, null, 5);
        $this->assertStringContainsString('OFFSET 5', $result);
        $this->assertStringNotContainsString('LIMIT', $result);

        // Test with both
        $result = $this->formatter->formatLimitOffset($sql, 10, 5);
        $this->assertStringContainsString('LIMIT 10', $result);
        $this->assertStringContainsString('OFFSET 5', $result);

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
        $this->assertStringContainsString('`user_id`', $result);
        $this->assertStringContainsString('`created_at`', $result);

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
        // Test without distinct
        $result = $this->formatter->formatGroupConcat('name', ',', false);
        $this->assertStringContainsString('GROUP_CONCAT', $result);
        $this->assertStringContainsString('SEPARATOR', $result);
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
        // Test with array path
        $result = $this->formatter->formatJsonGet('data', ['user', 'name'], true);
        $this->assertStringContainsString('JSON_EXTRACT', $result);
        $this->assertStringContainsString('JSON_UNQUOTE', $result);
        $this->assertStringContainsString('`data`', $result);

        // Test with string path
        $result = $this->formatter->formatJsonGet('data', '$.user.name', false);
        $this->assertStringContainsString('JSON_EXTRACT', $result);
        $this->assertStringNotContainsString('JSON_UNQUOTE', $result);
    }

    public function testFormatJsonContains(): void
    {
        $result = $this->formatter->formatJsonContains('data', ['key' => 'value'], '$.path');
        $this->assertIsArray($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertStringContainsString('JSON_CONTAINS', $result[0]);
        $this->assertIsArray($result[1]);
    }

    public function testFormatIfNull(): void
    {
        $result = $this->formatter->formatIfNull('column', 'default');
        $this->assertStringContainsString('IFNULL', $result);
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
        $this->assertEquals('CURDATE()', $result);
    }

    public function testFormatCurTime(): void
    {
        $result = $this->formatter->formatCurTime();
        $this->assertEquals('CURTIME()', $result);
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
        // Test DATE_ADD
        $result = $this->formatter->formatInterval('created_at', '1', 'DAY', true);
        $this->assertStringContainsString('DATE_ADD', $result);
        $this->assertStringContainsString('INTERVAL', $result);
        $this->assertStringContainsString('DAY', $result);

        // Test DATE_SUB
        $result = $this->formatter->formatInterval('created_at', '1', 'MONTH', false);
        $this->assertStringContainsString('DATE_SUB', $result);
        $this->assertStringContainsString('MONTH', $result);
    }

    public function testFormatTruncate(): void
    {
        $result = $this->formatter->formatTruncate('price', 2);
        $this->assertStringContainsString('TRUNCATE', $result);
        $this->assertStringContainsString('price', $result);
        $this->assertStringContainsString('2', $result);
    }

    public function testFormatPosition(): void
    {
        $result = $this->formatter->formatPosition('substring', 'column');
        $this->assertStringContainsString('POSITION', $result);
        $this->assertStringContainsString('IN', $result);
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
        $result = $this->formatter->formatRepeat('x', 3);
        $this->assertStringContainsString('REPEAT', $result);
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
        // Test LPAD
        $result = $this->formatter->formatPad('name', 10, '0', true);
        $this->assertStringContainsString('LPAD', $result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('10', $result);

        // Test RPAD
        $result = $this->formatter->formatPad('name', 10, '0', false);
        $this->assertStringContainsString('RPAD', $result);
    }

    public function testFormatRegexpMatch(): void
    {
        $result = $this->formatter->formatRegexpMatch('column', 'pattern');
        $this->assertStringContainsString('REGEXP', $result);
        $this->assertStringContainsString('column', $result);
    }

    public function testFormatRegexpReplace(): void
    {
        $result = $this->formatter->formatRegexpReplace('column', 'pattern', 'replacement');
        $this->assertStringContainsString('REGEXP_REPLACE', $result);
        $this->assertStringContainsString('column', $result);
    }

    public function testFormatRegexpExtract(): void
    {
        $result = $this->formatter->formatRegexpExtract('column', 'pattern');
        $this->assertStringContainsString('REGEXP_SUBSTR', $result);
        $this->assertStringContainsString('column', $result);
    }

    public function testFormatFulltextMatch(): void
    {
        // Test with single column
        $result = $this->formatter->formatFulltextMatch('title', 'search term', 'natural', false);
        $this->assertIsArray($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertStringContainsString('MATCH', $result[0]);
        $this->assertStringContainsString('AGAINST', $result[0]);

        // Test with multiple columns
        $result = $this->formatter->formatFulltextMatch(['title', 'content'], 'search', null, true);
        $this->assertStringContainsString('WITH QUERY EXPANSION', $result[0]);
    }

    public function testFormatMaterializedCte(): void
    {
        $cteSql = 'SELECT * FROM users';

        // Test without materialization
        $result = $this->formatter->formatMaterializedCte($cteSql, false);
        $this->assertEquals($cteSql, $result);

        // Test with materialization
        $result = $this->formatter->formatMaterializedCte($cteSql, true);
        $this->assertStringContainsString('MATERIALIZE', $result);
    }
}
