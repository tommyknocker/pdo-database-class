<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\migrations\SqlQueryCollector;

/**
 * Tests for SqlQueryCollector.
 */
final class SqlQueryCollectorTests extends TestCase
{
    protected SqlQueryCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new SqlQueryCollector();
    }

    /**
     * Test getQueries returns empty array initially.
     */
    public function testGetQueriesInitiallyEmpty(): void
    {
        $queries = $this->collector->getQueries();
        $this->assertIsArray($queries);
        $this->assertEmpty($queries);
    }

    /**
     * Test handleQueryExecuted collects queries.
     */
    public function testHandleQueryExecuted(): void
    {
        $event = new QueryExecutedEvent('SELECT * FROM users', [], 0.0, 0, 'sqlite', false);
        $this->collector->handleQueryExecuted($event);

        $queries = $this->collector->getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('SELECT * FROM users', $queries[0]);
    }

    /**
     * Test handleQueryExecuted with parameters.
     */
    public function testHandleQueryExecutedWithParams(): void
    {
        $event = new QueryExecutedEvent('SELECT * FROM users WHERE id = ?', [1], 0.0, 0, 'sqlite', false);
        $this->collector->handleQueryExecuted($event);

        $queries = $this->collector->getQueries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('SELECT * FROM users WHERE id =', $queries[0]);
        $this->assertStringContainsString('1', $queries[0]);
    }

    /**
     * Test handleQueryExecuted with multiple queries.
     */
    public function testHandleQueryExecutedMultipleQueries(): void
    {
        $event1 = new QueryExecutedEvent('SELECT * FROM users', [], 0.0, 0, 'sqlite', false);
        $event2 = new QueryExecutedEvent('SELECT * FROM posts', [], 0.0, 0, 'sqlite', false);
        $this->collector->handleQueryExecuted($event1);
        $this->collector->handleQueryExecuted($event2);

        $queries = $this->collector->getQueries();
        $this->assertCount(2, $queries);
        $this->assertEquals('SELECT * FROM users', $queries[0]);
        $this->assertEquals('SELECT * FROM posts', $queries[1]);
    }

    /**
     * Test clear removes all queries.
     */
    public function testClear(): void
    {
        $event = new QueryExecutedEvent('SELECT * FROM users', [], 0.0, 0, 'sqlite', false);
        $this->collector->handleQueryExecuted($event);

        $this->assertCount(1, $this->collector->getQueries());

        $this->collector->clear();

        $this->assertEmpty($this->collector->getQueries());
    }

    /**
     * Test formatSqlWithParams with integer parameter.
     */
    public function testFormatSqlWithParamsInteger(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatSqlWithParams');
        $method->setAccessible(true);

        $result = $method->invoke($this->collector, 'SELECT * FROM users WHERE id = ?', [123]);
        $this->assertStringContainsString('123', $result);
    }

    /**
     * Test formatSqlWithParams with string parameter.
     */
    public function testFormatSqlWithParamsString(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatSqlWithParams');
        $method->setAccessible(true);

        $result = $method->invoke($this->collector, 'SELECT * FROM users WHERE name = ?', ['John']);
        $this->assertStringContainsString("'John'", $result);
    }

    /**
     * Test formatSqlWithParams with null parameter.
     */
    public function testFormatSqlWithParamsNull(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatSqlWithParams');
        $method->setAccessible(true);

        $result = $method->invoke($this->collector, 'SELECT * FROM users WHERE deleted_at IS ?', [null]);
        $this->assertStringContainsString('NULL', $result);
    }

    /**
     * Test formatSqlWithParams with boolean parameter.
     */
    public function testFormatSqlWithParamsBoolean(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatSqlWithParams');
        $method->setAccessible(true);

        $result = $method->invoke($this->collector, 'SELECT * FROM users WHERE active = ?', [true]);
        $this->assertStringContainsString('1', $result);

        $result = $method->invoke($this->collector, 'SELECT * FROM users WHERE active = ?', [false]);
        $this->assertStringContainsString('0', $result);
    }

    /**
     * Test formatSqlWithParams with float parameter.
     */
    public function testFormatSqlWithParamsFloat(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatSqlWithParams');
        $method->setAccessible(true);

        $result = $method->invoke($this->collector, 'SELECT * FROM products WHERE price = ?', [19.99]);
        $this->assertStringContainsString('19.99', $result);
    }

    /**
     * Test formatSqlWithParams with array parameter.
     */
    public function testFormatSqlWithParamsArray(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatSqlWithParams');
        $method->setAccessible(true);

        $result = $method->invoke($this->collector, 'SELECT * FROM users WHERE tags = ?', [['tag1', 'tag2']]);
        $this->assertStringContainsString("'", $result);
    }

    /**
     * Test formatSqlWithParams with named parameters.
     */
    public function testFormatSqlWithParamsNamed(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatSqlWithParams');
        $method->setAccessible(true);

        $result = $method->invoke($this->collector, 'SELECT * FROM users WHERE id = :id', ['id' => 123]);
        $this->assertStringContainsString('123', $result);
    }

    /**
     * Test formatValue with various types.
     */
    public function testFormatValue(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatValue');
        $method->setAccessible(true);

        $this->assertEquals('NULL', $method->invoke($this->collector, null));
        $this->assertEquals('1', $method->invoke($this->collector, true));
        $this->assertEquals('0', $method->invoke($this->collector, false));
        $this->assertEquals('123', $method->invoke($this->collector, 123));
        $this->assertEquals('19.99', $method->invoke($this->collector, 19.99));
        $this->assertStringContainsString("'test'", $method->invoke($this->collector, 'test'));
    }

    /**
     * Test formatValue with string containing quotes.
     */
    public function testFormatValueWithQuotes(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatValue');
        $method->setAccessible(true);

        $result = $method->invoke($this->collector, "John's name");
        $this->assertStringContainsString("John\\'s", $result);
    }

    /**
     * Test formatValue with object.
     */
    public function testFormatValueWithObject(): void
    {
        $reflection = new \ReflectionClass(SqlQueryCollector::class);
        $method = $reflection->getMethod('formatValue');
        $method->setAccessible(true);

        $object = new class () {
            public function __toString(): string
            {
                return 'test';
            }
        };

        $result = $method->invoke($this->collector, $object);
        $this->assertStringContainsString("'test'", $result);
    }

    /**
     * Test getListenersForEvent returns listener for QueryExecutedEvent.
     */
    public function testGetListenersForEvent(): void
    {
        $event = new QueryExecutedEvent('SELECT 1', [], 0.0, 0, 'sqlite', false);
        $listeners = $this->collector->getListenersForEvent($event);

        $this->assertIsIterable($listeners);
        $listenersArray = iterator_to_array($listeners);
        $this->assertNotEmpty($listenersArray);
        $this->assertIsArray($listenersArray[0]);
        $this->assertCount(2, $listenersArray[0]);
        $this->assertEquals($this->collector, $listenersArray[0][0]);
        $this->assertEquals('handleQueryExecuted', $listenersArray[0][1]);
    }

    /**
     * Test getListenersForEvent returns empty for non-QueryExecutedEvent.
     */
    public function testGetListenersForEventNonQueryEvent(): void
    {
        $event = new \stdClass();
        $listeners = $this->collector->getListenersForEvent($event);

        $this->assertIsIterable($listeners);
        $listenersArray = iterator_to_array($listeners);
        $this->assertEmpty($listenersArray);
    }
}
