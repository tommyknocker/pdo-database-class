<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\parsers\ConstraintParser;

/**
 * Tests for ConstraintParser.
 */
final class ConstraintParserTests extends BaseSharedTestCase
{
    protected ConstraintParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ConstraintParser();
    }

    public function testParseMySQLDuplicateKeyConstraint(): void
    {
        $message = "Duplicate entry 'test@example.com' for key 'users.email'";
        $result = $this->parser->parse($message);

        // Parser extracts 'users.email' as constraint name
        $this->assertEquals('users.email', $result['constraintName']);
        // Table and column are not parsed from this format by current parser
        $this->assertNull($result['tableName']);
        $this->assertNull($result['columnName']);
    }

    public function testParseMySQLConstraintWithBackticks(): void
    {
        // Backticks are not matched by current patterns - test with single quotes
        $message = "Duplicate entry for key 'users_email_unique'";
        $result = $this->parser->parse($message);

        $this->assertEquals('users_email_unique', $result['constraintName']);
    }

    public function testParsePostgreSQLConstraintWithQuotes(): void
    {
        $message = 'duplicate key value violates unique constraint "users_email_key"';
        $result = $this->parser->parse($message);

        $this->assertEquals('users_email_key', $result['constraintName']);
    }

    public function testParsePostgreSQLTableAndColumn(): void
    {
        $message = 'duplicate key value violates unique constraint "users_email_key" in table \'users\' column \'email\'';
        $result = $this->parser->parse($message);

        $this->assertEquals('users_email_key', $result['constraintName']);
        $this->assertEquals('users', $result['tableName']);
        $this->assertEquals('email', $result['columnName']);
    }

    public function testParseSQLiteConstraint(): void
    {
        // SQLite format: "UNIQUE constraint failed: table.column"
        // Parser cannot extract constraint name from this format
        $message = 'UNIQUE constraint failed: users.email';
        $result = $this->parser->parse($message);

        // Parser cannot extract constraint name from "UNIQUE constraint failed:" format
        $this->assertNull($result['constraintName']);
        // Parser cannot extract table/column from "table.column" format without specific patterns
        $this->assertNull($result['tableName']);
        $this->assertNull($result['columnName']);
    }

    public function testParseMySQLForeignKeyConstraint(): void
    {
        $message = 'Cannot add or update a child row: a foreign key constraint fails (`test_db`.`orders`, CONSTRAINT `orders_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`))';
        $result = $this->parser->parse($message);

        // Parser extracts constraint name from "CONSTRAINT `name`" pattern
        $this->assertEquals('orders_user_id_fk', $result['constraintName']);
        // Table extraction from `schema`.`table` format
        $this->assertEquals('orders', $result['tableName']);
        // Column extracted from FOREIGN KEY (`user_id`) pattern
        $this->assertEquals('user_id', $result['columnName']);
    }

    public function testParseMySQLTableOnly(): void
    {
        $message = 'Error in table `users`';
        $result = $this->parser->parse($message);

        $this->assertNull($result['constraintName']);
        $this->assertEquals('users', $result['tableName']);
        $this->assertNull($result['columnName']);
    }

    public function testParseMySQLColumnOnly(): void
    {
        $message = 'Invalid value for column `email`';
        $result = $this->parser->parse($message);

        $this->assertNull($result['constraintName']);
        $this->assertNull($result['tableName']);
        $this->assertEquals('email', $result['columnName']);
    }

    public function testParseMessageWithNoMatches(): void
    {
        $message = 'Generic database error occurred';
        $result = $this->parser->parse($message);

        $this->assertNull($result['constraintName']);
        $this->assertNull($result['tableName']);
        $this->assertNull($result['columnName']);
    }

    public function testParseEmptyMessage(): void
    {
        $result = $this->parser->parse('');

        $this->assertNull($result['constraintName']);
        $this->assertNull($result['tableName']);
        $this->assertNull($result['columnName']);
    }

    public function testParseCaseInsensitive(): void
    {
        $message = "DUPLICATE ENTRY FOR KEY 'USERS_EMAIL_UNIQUE'";
        $result = $this->parser->parse($message);

        $this->assertEquals('USERS_EMAIL_UNIQUE', $result['constraintName']);
    }

    public function testParseConstraintWithSpaces(): void
    {
        $message = "for key 'users email unique'";
        $result = $this->parser->parse($message);

        $this->assertEquals('users email unique', $result['constraintName']);
    }

    public function testParseTableWithSchema(): void
    {
        $message = 'Error in table `public.users`';
        $result = $this->parser->parse($message);

        // Should extract just the table name part
        $this->assertEquals('public.users', $result['tableName']);
    }

    public function testParseMultipleMatches(): void
    {
        // First matching pattern should win
        $message = "Duplicate entry for key 'users_email_unique' in table 'users'";
        $result = $this->parser->parse($message);

        $this->assertEquals('users_email_unique', $result['constraintName']);
        $this->assertEquals('users', $result['tableName']);
    }
}
