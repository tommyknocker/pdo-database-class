<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use tommyknocker\pdodb\query\ConditionBuilder;
use tommyknocker\pdodb\query\ExecutionEngine;
use tommyknocker\pdodb\query\ParameterManager;
use tommyknocker\pdodb\query\RawValueResolver;

/**
 * Tests for IdentifierQuotingTrait.
 */
final class IdentifierQuotingTests extends BaseSharedTestCase
{
    protected function createConditionBuilder(): ConditionBuilder
    {
        $connection = self::$db->connection;
        $parameterManager = new ParameterManager();
        $rawValueResolver = new RawValueResolver($connection, $parameterManager);
        $executionEngine = new ExecutionEngine($connection, $rawValueResolver, $parameterManager);

        return new ConditionBuilder(
            $connection,
            $parameterManager,
            $executionEngine,
            $rawValueResolver
        );
    }

    public function testQuoteQualifiedIdentifierSimple(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($builder, 'table_name');
        $dialect = self::$db->connection->getDialect();
        $expected = $dialect->quoteIdentifier('table_name');
        $this->assertEquals($expected, $result);
    }

    public function testQuoteQualifiedIdentifierQualified(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($builder, 'schema.table_name');
        $dialect = self::$db->connection->getDialect();
        $expected = $dialect->quoteIdentifier('schema') . '.' . $dialect->quoteIdentifier('table_name');
        $this->assertEquals($expected, $result);
    }

    public function testQuoteQualifiedIdentifierTripleQualified(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($builder, 'db.schema.table_name');
        $dialect = self::$db->connection->getDialect();
        $expected = $dialect->quoteIdentifier('db') . '.' . $dialect->quoteIdentifier('schema') . '.' . $dialect->quoteIdentifier('table_name');
        $this->assertEquals($expected, $result);
    }

    public function testQuoteQualifiedIdentifierWithParentheses(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        // Expression with parentheses should pass through
        $result = $method->invoke($builder, 'COUNT(*)');
        $this->assertEquals('COUNT(*)', $result);
    }

    public function testQuoteQualifiedIdentifierWithSpaces(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        // Expression with spaces should pass through
        $result = $method->invoke($builder, 'COUNT(*) AS total');
        $this->assertEquals('COUNT(*) AS total', $result);
    }

    public function testQuoteQualifiedIdentifierWithQuotes(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        // Already quoted identifier should pass through
        $dialect = self::$db->connection->getDialect();
        $quoted = $dialect->quoteIdentifier('table');
        $result = $method->invoke($builder, $quoted);
        $this->assertEquals($quoted, $result);
    }

    public function testQuoteQualifiedIdentifierUnsafeSql(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL expression provided as identifier/expression.');
        $method->invoke($builder, 'table; DROP TABLE users');
    }

    public function testQuoteQualifiedIdentifierUnsafeDrop(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL expression provided as identifier/expression.');
        $method->invoke($builder, 'table DROP TABLE users');
    }

    public function testQuoteQualifiedIdentifierUnsafeDelete(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL expression provided as identifier/expression.');
        $method->invoke($builder, 'table DELETE FROM users');
    }

    public function testQuoteQualifiedIdentifierUnsafeSelect(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL expression provided as identifier/expression.');
        $method->invoke($builder, 'table SELECT * FROM users');
    }

    public function testQuoteQualifiedIdentifierUnsafeUnion(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL expression provided as identifier/expression.');
        $method->invoke($builder, 'table UNION SELECT *');
    }

    public function testQuoteQualifiedIdentifierUnsafeComment(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        // --comment contains -- which is detected by the unsafe check
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe SQL expression provided as identifier/expression.');
        $method->invoke($builder, 'table --comment');
    }

    public function testQuoteQualifiedIdentifierInvalidPart(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier part: 123invalid');
        $method->invoke($builder, '123invalid');
    }

    public function testQuoteQualifiedIdentifierInvalidPartInQualified(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier part: 123invalid');
        $method->invoke($builder, 'schema.123invalid');
    }

    public function testQuoteQualifiedIdentifierWithSpecialCharsInValidContext(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('quoteQualifiedIdentifier');
        $method->setAccessible(true);

        // Valid expression with commas should pass through
        $result = $method->invoke($builder, 'COUNT(*), SUM(amount)');
        $this->assertEquals('COUNT(*), SUM(amount)', $result);
    }
}
