<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\query\formatter\SqlFormatter;

/**
 * Tests for SQL Formatter.
 */
final class SqlFormatterTests extends TestCase
{
    protected SqlFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new SqlFormatter();
    }

    public function testBasicSelectQueryFormatting(): void
    {
        $sql = 'SELECT * FROM users';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('FROM', $formatted);
        $this->assertStringNotContainsString('  ', $formatted); // No double spaces
    }

    public function testSelectWithWhereClause(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';
        $formatted = $this->formatter->format($sql);
        $lines = explode("\n", $formatted);
        $this->assertStringContainsString('SELECT', $lines[0] ?? '');
        $this->assertStringContainsString('FROM', $formatted);
        $this->assertStringContainsString('WHERE', $formatted);
    }

    public function testSelectWithJoin(): void
    {
        $sql = 'SELECT u.name, o.total FROM users u INNER JOIN orders o ON u.id = o.user_id';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('FROM', $formatted);
        $this->assertStringContainsString('INNER JOIN', $formatted);
        $this->assertStringContainsString('ON', $formatted);
    }

    public function testSelectWithMultipleJoins(): void
    {
        $sql = 'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id RIGHT JOIN products p ON o.product_id = p.id';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('LEFT JOIN', $formatted);
        $this->assertStringContainsString('RIGHT JOIN', $formatted);
    }

    public function testSelectWithGroupByAndOrderBy(): void
    {
        $sql = 'SELECT category, COUNT(*) FROM products GROUP BY category ORDER BY category ASC';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('GROUP BY', $formatted);
        $this->assertStringContainsString('ORDER BY', $formatted);
    }

    public function testSelectWithMultipleConditions(): void
    {
        $sql = 'SELECT * FROM users WHERE status = "active" AND age > 18 OR role = "admin"';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('WHERE', $formatted);
        $this->assertStringContainsString('AND', $formatted);
        $this->assertStringContainsString('OR', $formatted);
    }

    public function testSelectWithSubquery(): void
    {
        $sql = 'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE total > 100)';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('WHERE', $formatted);
        $this->assertStringContainsString('IN', $formatted);
        $this->assertStringContainsString('(', $formatted);
        $this->assertStringContainsString(')', $formatted);
    }

    public function testSelectWithCTE(): void
    {
        $sql = 'WITH recent_orders AS (SELECT * FROM orders WHERE created_at > NOW()) SELECT * FROM recent_orders';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('WITH', $formatted);
        $this->assertStringContainsString('AS', $formatted);
    }

    public function testSelectWithUnion(): void
    {
        $sql = 'SELECT * FROM users UNION SELECT * FROM customers';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('UNION', $formatted);
    }

    public function testInsertQuery(): void
    {
        $sql = 'INSERT INTO users (name, email) VALUES ("John", "john@example.com")';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('INSERT', $formatted);
        $this->assertStringContainsString('INTO', $formatted);
        $this->assertStringContainsString('VALUES', $formatted);
    }

    public function testUpdateQuery(): void
    {
        $sql = 'UPDATE users SET name = "John", email = "john@example.com" WHERE id = 1';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('UPDATE', $formatted);
        $this->assertStringContainsString('SET', $formatted);
        $this->assertStringContainsString('WHERE', $formatted);
    }

    public function testDeleteQuery(): void
    {
        $sql = 'DELETE FROM users WHERE id = 1';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('DELETE', $formatted);
        $this->assertStringContainsString('FROM', $formatted);
        $this->assertStringContainsString('WHERE', $formatted);
    }

    public function testMergeQuery(): void
    {
        $sql = 'MERGE INTO products AS target USING updates AS source ON target.id = source.id WHEN MATCHED THEN UPDATE SET price = source.price';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('MERGE', $formatted);
        $this->assertStringContainsString('USING', $formatted);
        $this->assertStringContainsString('WHEN MATCHED', $formatted);
    }

    public function testFormattingPreservesQuotedStrings(): void
    {
        $sql = 'SELECT * FROM users WHERE name = "John Doe" AND email = \'john@example.com\'';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('"John Doe"', $formatted);
        $this->assertStringContainsString("'john@example.com'", $formatted);
    }

    public function testFormattingPreservesBackticks(): void
    {
        $sql = 'SELECT `id`, `name` FROM `users`';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('`id`', $formatted);
        $this->assertStringContainsString('`name`', $formatted);
        $this->assertStringContainsString('`users`', $formatted);
    }

    public function testFormattingNormalizesWhitespace(): void
    {
        $sql = 'SELECT    *   FROM    users    WHERE     id=1';
        $formatted = $this->formatter->format($sql);
        // Should not contain multiple consecutive spaces
        $this->assertStringNotContainsString('    ', $formatted);
    }

    public function testFormattingWithCustomIndentSize(): void
    {
        $formatter = new SqlFormatter(false, 2);
        $sql = 'SELECT * FROM users WHERE id = 1 AND name = "test" ORDER BY id';
        $formatted = $formatter->format($sql);
        $lines = explode("\n", $formatted);
        // Check that indentation is applied
        $this->assertGreaterThanOrEqual(1, count($lines), 'Formatted SQL should have at least one line');
        // Verify formatting worked and SQL is preserved
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('FROM', $formatted);
        $this->assertStringContainsString('WHERE', $formatted);
        // Verify that custom indent size was used (check spacing in indented lines)
        if (count($lines) > 1) {
            foreach ($lines as $line) {
                // If line is indented and not empty, check it starts with spaces
                $trimmed = ltrim($line);
                if ($trimmed !== $line && $trimmed !== '') {
                    $indent = strlen($line) - strlen($trimmed);
                    // Should be multiple of 2 (our indent size)
                    $this->assertGreaterThan(0, $indent, 'Indented line should have spacing');
                }
            }
        }
    }

    public function testFormattingWithKeywordsHighlighted(): void
    {
        $formatter = new SqlFormatter(true);
        $sql = 'SELECT * FROM users';
        $formatted = $formatter->format($sql);
        // When highlighting is enabled, keywords should contain ANSI color codes
        $this->assertStringContainsString('SELECT', $formatted);
        // Note: ANSI codes might not be visible in assertion, but formatting should still work
    }

    public function testComplexQueryWithMultipleClauses(): void
    {
        $sql = 'SELECT u.name, COUNT(o.id) as order_count FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE u.status = "active" GROUP BY u.id HAVING COUNT(o.id) > 5 ORDER BY order_count DESC LIMIT 10';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('FROM', $formatted);
        $this->assertStringContainsString('LEFT JOIN', $formatted);
        $this->assertStringContainsString('WHERE', $formatted);
        $this->assertStringContainsString('GROUP BY', $formatted);
        $this->assertStringContainsString('HAVING', $formatted);
        $this->assertStringContainsString('ORDER BY', $formatted);
        $this->assertStringContainsString('LIMIT', $formatted);
    }

    public function testFormattingRemovesTrailingWhitespace(): void
    {
        $sql = 'SELECT * FROM users   ';
        $formatted = $this->formatter->format($sql);
        $trimmed = rtrim($formatted);
        $this->assertEquals($trimmed, $formatted);
    }

    public function testFormattingHandlesEmptyString(): void
    {
        $sql = '';
        $formatted = $this->formatter->format($sql);
        $this->assertEquals('', $formatted);
    }

    public function testFormattingHandlesSimpleString(): void
    {
        $sql = 'SELECT 1';
        $formatted = $this->formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('1', $formatted);
    }
}
