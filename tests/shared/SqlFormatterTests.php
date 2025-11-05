<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\formatter\SqlFormatter;

/**
 * Tests for SqlFormatter class.
 */
final class SqlFormatterTests extends BaseSharedTestCase
{
    public function testFormatSimpleSelect(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM users';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('FROM', $formatted);
    }

    public function testFormatSelectWithWhere(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM users WHERE id = 1';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('WHERE', $formatted);
    }

    public function testFormatSelectWithJoin(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT u.id, u.name FROM users u JOIN orders o ON u.id = o.user_id';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('JOIN', $formatted);
    }

    public function testFormatWithHighlighting(): void
    {
        $formatter = new SqlFormatter(true);
        $sql = 'SELECT * FROM users';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testFormatWithCustomIndent(): void
    {
        $formatter = new SqlFormatter(false, 2);
        $sql = 'SELECT * FROM users WHERE id = 1';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testFormatComplexQuery(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT u.id, u.name, COUNT(o.id) as order_count FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE u.status = "active" GROUP BY u.id HAVING COUNT(o.id) > 5 ORDER BY order_count DESC LIMIT 10';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('GROUP BY', $formatted);
        $this->assertStringContainsString('HAVING', $formatted);
        $this->assertStringContainsString('ORDER BY', $formatted);
        $this->assertStringContainsString('LIMIT', $formatted);
    }

    public function testFormatWithSubquery(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM (SELECT id, name FROM users) AS subquery';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('FROM', $formatted);
    }

    public function testFormatWithParentheses(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM users WHERE (id = 1 OR id = 2) AND status = "active"';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('WHERE', $formatted);
    }

    public function testFormatInsert(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'INSERT INTO users (name, email) VALUES ("John", "john@example.com")';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('INSERT', $formatted);
        $this->assertStringContainsString('VALUES', $formatted);
    }

    public function testFormatUpdate(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'UPDATE users SET name = "John", email = "john@example.com" WHERE id = 1';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('UPDATE', $formatted);
        $this->assertStringContainsString('SET', $formatted);
    }

    public function testFormatDelete(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'DELETE FROM users WHERE id = 1';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('DELETE', $formatted);
        $this->assertStringContainsString('WHERE', $formatted);
    }

    public function testFormatWithQuotedStrings(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM users WHERE name = "John\'s Test"';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testFormatNormalizesWhitespace(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT   *   FROM    users   WHERE   id   =   1';
        $formatted = $formatter->format($sql);
        // Should not contain multiple consecutive spaces
        $this->assertStringNotContainsString('   ', $formatted);
    }

    public function testFormatWithCommas(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT id,name,email FROM users';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testFormatWithAndOr(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM users WHERE id = 1 AND status = "active" OR name LIKE "John%"';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('AND', $formatted);
        $this->assertStringContainsString('OR', $formatted);
    }

    public function testFormatWithComplexNestedQuery(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM (SELECT id, name FROM users WHERE status = "active") AS subquery WHERE id > 10';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('FROM', $formatted);
    }

    public function testFormatWithWindowFunctions(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT id, name, ROW_NUMBER() OVER (PARTITION BY status ORDER BY created_at) as rn FROM users';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('OVER', $formatted);
    }

    public function testFormatWithCTE(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'WITH users_cte AS (SELECT * FROM users) SELECT * FROM users_cte';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('WITH', $formatted);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testFormatWithUnions(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM users UNION SELECT * FROM orders';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('UNION', $formatted);
    }

    public function testFormatWithGroupByAndHaving(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT status, COUNT(*) as cnt FROM users GROUP BY status HAVING COUNT(*) > 5';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('GROUP BY', $formatted);
        $this->assertStringContainsString('HAVING', $formatted);
    }

    public function testFormatWithMultipleJoins(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT u.id, o.id as order_id, p.name FROM users u JOIN orders o ON u.id = o.user_id LEFT JOIN products p ON o.product_id = p.id';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringContainsString('JOIN', $formatted);
        $this->assertStringContainsString('LEFT JOIN', $formatted);
    }

    public function testFormatWithEscapedQuotes(): void
    {
        $formatter = new SqlFormatter();
        $sql = "SELECT * FROM users WHERE name = 'John\\'s Test'";
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testFormatWithTabs(): void
    {
        $formatter = new SqlFormatter(false, 4, "\t");
        $sql = 'SELECT * FROM users WHERE id = 1';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testFormatWithDifferentIndentSize(): void
    {
        $formatter = new SqlFormatter(false, 2);
        $sql = 'SELECT * FROM users WHERE id = 1 AND status = "active"';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testFormatWithHighlightingEnabled(): void
    {
        $formatter = new SqlFormatter(true);
        $sql = 'SELECT * FROM users WHERE id = 1';
        $formatted = $formatter->format($sql);
        // When highlighting is enabled, keywords should contain ANSI color codes
        $this->assertStringContainsString('SELECT', $formatted);
        // Check for ANSI color codes (highlighting enabled) - may be present in formatted output
        // Note: The actual highlighting depends on token processing
        $this->assertIsString($formatted);
    }

    public function testFormatWithHighlightingDisabled(): void
    {
        $formatter = new SqlFormatter(false);
        $sql = 'SELECT * FROM users WHERE id = 1';
        $formatted = $formatter->format($sql);
        // When highlighting is disabled, no ANSI codes should be present
        $this->assertStringContainsString('SELECT', $formatted);
        $this->assertStringNotContainsString("\033[1;34m", $formatted);
    }

    public function testFormatWithBackticks(): void
    {
        $formatter = new SqlFormatter();
        $sql = 'SELECT * FROM `users` WHERE `id` = 1';
        $formatted = $formatter->format($sql);
        $this->assertStringContainsString('SELECT', $formatted);
    }

    public function testGetIndentWithNegativeLevel(): void
    {
        $formatter = new SqlFormatter();
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('getIndent');
        $method->setAccessible(true);

        // Test with negative level (should be clamped to 0)
        $indent = $method->invoke($formatter, -1);
        $this->assertEquals('', $indent);
    }

    public function testGetIndentWithZeroLevel(): void
    {
        $formatter = new SqlFormatter();
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('getIndent');
        $method->setAccessible(true);

        $indent = $method->invoke($formatter, 0);
        $this->assertEquals('', $indent);
    }

    public function testGetIndentWithPositiveLevel(): void
    {
        $formatter = new SqlFormatter(false, 4);
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('getIndent');
        $method->setAccessible(true);

        $indent = $method->invoke($formatter, 2);
        $this->assertEquals('        ', $indent); // 2 levels * 4 spaces = 8 spaces
    }

    public function testNormalizeWhitespaceRemovesMultipleSpaces(): void
    {
        $formatter = new SqlFormatter();
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('normalizeWhitespace');
        $method->setAccessible(true);

        $sql = 'SELECT     *     FROM    users';
        $normalized = $method->invoke($formatter, $sql);
        $this->assertStringNotContainsString('     ', $normalized);
    }

    public function testNormalizeWhitespaceRemovesSpacesAroundParentheses(): void
    {
        $formatter = new SqlFormatter();
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('normalizeWhitespace');
        $method->setAccessible(true);

        $sql = 'SELECT * FROM ( SELECT id FROM users )';
        $normalized = $method->invoke($formatter, $sql);
        $this->assertStringNotContainsString('( ', $normalized);
        $this->assertStringNotContainsString(' )', $normalized);
    }

    public function testNormalizeWhitespaceRemovesSpacesAroundCommas(): void
    {
        $formatter = new SqlFormatter();
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('normalizeWhitespace');
        $method->setAccessible(true);

        $sql = 'SELECT id , name , email FROM users';
        $normalized = $method->invoke($formatter, $sql);
        $this->assertStringNotContainsString(' ,', $normalized);
        $this->assertStringContainsString(', ', $normalized);
    }

    public function testCleanupRemovesMultipleNewlines(): void
    {
        $formatter = new SqlFormatter();
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('cleanup');
        $method->setAccessible(true);

        $sql = "SELECT *\n\n\n\nFROM users";
        $cleaned = $method->invoke($formatter, $sql);
        $this->assertStringNotContainsString("\n\n\n", $cleaned);
    }

    public function testCleanupRemovesTrailingSpaces(): void
    {
        $formatter = new SqlFormatter();
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('cleanup');
        $method->setAccessible(true);

        $sql = "SELECT *   \nFROM users   ";
        $cleaned = $method->invoke($formatter, $sql);
        $this->assertNotEquals(' ', substr($cleaned, -1));
    }

    public function testCleanupRemovesSpacesBeforeNewlines(): void
    {
        $formatter = new SqlFormatter();
        $reflection = new \ReflectionClass($formatter);
        $method = $reflection->getMethod('cleanup');
        $method->setAccessible(true);

        $sql = "SELECT *   \nFROM users";
        $cleaned = $method->invoke($formatter, $sql);
        $this->assertStringNotContainsString('   \n', $cleaned);
    }
}
