<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\dialects\oracle\OracleSqlFormatter;

/**
 * Tests for OracleSqlFormatter class.
 */
final class OracleSqlFormatterTests extends BaseOracleTestCase
{
    protected function createFormatter(): OracleSqlFormatter
    {
        return new OracleSqlFormatter(self::$db->connection->getDialect());
    }

    public function testFormatLimitOffsetWithLimitOnly(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatLimitOffset($sql, 10, null);
        $this->assertStringContainsString('FETCH NEXT 10 ROWS ONLY', $result);
    }

    public function testFormatLimitOffsetWithOffsetOnly(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatLimitOffset($sql, null, 20);
        $this->assertStringContainsString('OFFSET 20 ROWS', $result);
    }

    public function testFormatLimitOffsetWithLimitAndOffset(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatLimitOffset($sql, 10, 20);
        $this->assertStringContainsString('OFFSET 20 ROWS', $result);
        $this->assertStringContainsString('FETCH NEXT 10 ROWS ONLY', $result);
    }

    public function testFormatLimitOffsetWithoutLimitAndOffset(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatLimitOffset($sql, null, null);
        $this->assertEquals($sql, $result);
    }

    public function testFormatSelectOptionsWithForUpdate(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatSelectOptions($sql, ['FOR UPDATE']);
        $this->assertStringContainsString('FOR UPDATE', $result);
    }

    public function testFormatSelectOptionsWithForUpdateNowait(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatSelectOptions($sql, ['FOR UPDATE NOWAIT']);
        $this->assertStringContainsString('FOR UPDATE NOWAIT', $result);
    }

    public function testFormatSelectOptionsWithForUpdateWait(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatSelectOptions($sql, ['FOR UPDATE WAIT']);
        $this->assertStringContainsString('FOR UPDATE WAIT', $result);
    }

    public function testFormatSelectOptionsWithInvalidOption(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatSelectOptions($sql, ['INVALID OPTION']);
        $this->assertEquals($sql, $result);
    }

    public function testFormatSelectOptionsWithEmptyOptions(): void
    {
        $formatter = $this->createFormatter();
        $sql = 'SELECT * FROM users';
        $result = $formatter->formatSelectOptions($sql, []);
        $this->assertEquals($sql, $result);
    }

    public function testFormatWindowFunctionWithPartitionBy(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->formatWindowFunction('ROW_NUMBER', [], ['status'], [], null);
        $this->assertStringContainsString('ROW_NUMBER()', $result);
        $this->assertStringContainsString('PARTITION BY', $result);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('STATUS', $result);
    }

    public function testFormatWindowFunctionWithOrderBy(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->formatWindowFunction('ROW_NUMBER', [], [], [['id' => 'ASC']], null);
        $this->assertStringContainsString('ROW_NUMBER()', $result);
        $this->assertStringContainsString('ORDER BY', $result);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('ID', $result);
        $this->assertStringContainsString('ASC', $result);
    }

    public function testFormatWindowFunctionWithPartitionByAndOrderBy(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->formatWindowFunction('ROW_NUMBER', [], ['status'], [['id' => 'DESC']], null);
        $this->assertStringContainsString('PARTITION BY', $result);
        $this->assertStringContainsString('ORDER BY', $result);
        $this->assertStringContainsString('DESC', $result);
    }

    public function testFormatWindowFunctionWithArgs(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->formatWindowFunction('SUM', ['amount'], [], [], null);
        $this->assertStringContainsString('SUM(', $result);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('AMOUNT', $result);
    }

    public function testFormatWindowFunctionWithFrameClause(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->formatWindowFunction('SUM', ['amount'], [], [], 'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW');
        $this->assertStringContainsString('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW', $result);
    }
}
