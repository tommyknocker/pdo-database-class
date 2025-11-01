<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

/**
 * Integration tests for SQL formatting in QueryBuilder.
 */
final class QueryBuilderSqlFormatterTests extends BaseSharedTestCase
{
    public function testToSQLWithoutFormattingReturnsNormalSQL(): void
    {
        $sqlData = self::$db->find()
            ->from('test_coverage')
            ->where('id', 1)
            ->toSQL(false);

        $this->assertIsArray($sqlData);
        $this->assertArrayHasKey('sql', $sqlData);
        $this->assertArrayHasKey('params', $sqlData);
        $this->assertIsString($sqlData['sql']);
        // Unformatted SQL should be on one line or minimal formatting
        $this->assertNotEmpty($sqlData['sql']);
    }

    public function testToSQLWithFormattingReturnsFormattedSQL(): void
    {
        $sqlData = self::$db->find()
            ->from('test_coverage')
            ->where('id', 1)
            ->where('name', 'test')
            ->orderBy('id')
            ->toSQL(true);

        $this->assertIsArray($sqlData);
        $this->assertArrayHasKey('sql', $sqlData);
        $this->assertArrayHasKey('params', $sqlData);
        $this->assertIsString($sqlData['sql']);
        // Should contain keywords
        $this->assertStringContainsString('SELECT', $sqlData['sql']);
        $this->assertStringContainsString('FROM', $sqlData['sql']);
        $this->assertStringContainsString('WHERE', $sqlData['sql']);
        $this->assertStringContainsString('ORDER BY', $sqlData['sql']);
        // Formatted SQL should be normalized (no multiple spaces)
        $this->assertStringNotContainsString('  ', $sqlData['sql']);
    }

    public function testToSQLDefaultBehaviorIsUnformatted(): void
    {
        $sqlData1 = self::$db->find()
            ->from('test_coverage')
            ->where('id', 1)
            ->toSQL();

        $sqlData2 = self::$db->find()
            ->from('test_coverage')
            ->where('id', 1)
            ->toSQL(false);

        // Default behavior should be same as explicit false
        $this->assertEquals($sqlData1['sql'], $sqlData2['sql']);
    }

    public function testFormattedSQLContainsKeywords(): void
    {
        $sqlData = self::$db->find()
            ->from('test_coverage')
            ->select(['id', 'name', 'value'])
            ->where('id', 1)
            ->where('name', 'test')
            ->orderBy('id')
            ->toSQL(true);

        $sql = $sqlData['sql'];
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('FROM', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
    }

    public function testFormattedSQLWithJoin(): void
    {
        // Create a second table for join
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_join (
                id INTEGER PRIMARY KEY,
                coverage_id INTEGER,
                value TEXT
            )
        ');

        $sqlData = self::$db->find()
            ->from('test_coverage')
            ->join('test_join', 'test_coverage.id = test_join.coverage_id')
            ->where('test_coverage.id', 1)
            ->toSQL(true);

        $sql = $sqlData['sql'];
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('FROM', $sql);
        $this->assertStringContainsString('JOIN', $sql);
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testFormattedSQLWithGroupBy(): void
    {
        $sqlData = self::$db->find()
            ->from('test_coverage')
            ->select(['name', 'COUNT(*) as count'])
            ->groupBy('name')
            ->having('COUNT(*)', 1, '>')
            ->toSQL(true);

        $sql = $sqlData['sql'];
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('GROUP BY', $sql);
        $this->assertStringContainsString('HAVING', $sql);
    }

    public function testFormattedSQLParametersUnchanged(): void
    {
        $sqlData1 = self::$db->find()
            ->from('test_coverage')
            ->where('id', 1)
            ->where('name', 'test')
            ->toSQL(false);

        $sqlData2 = self::$db->find()
            ->from('test_coverage')
            ->where('id', 1)
            ->where('name', 'test')
            ->toSQL(true);

        // Parameters should be the same regardless of formatting
        $this->assertEquals($sqlData1['params'], $sqlData2['params']);
    }
}
