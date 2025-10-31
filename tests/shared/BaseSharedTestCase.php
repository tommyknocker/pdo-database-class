<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\PdoDb;

abstract class BaseSharedTestCase extends TestCase
{
    protected static PdoDb $db;

    public static function setUpBeforeClass(): void
    {
        self::$db = new PdoDb('sqlite', ['path' => ':memory:']);

        // Create test table
        self::$db->rawQuery('
            CREATE TABLE test_coverage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                value INTEGER,
                meta TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    /**
     * Clean up test data before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Clear all data from test table before each test
        self::$db->rawQuery('DELETE FROM test_coverage');
        // Reset auto-increment counter (SQLite specific)
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name='test_coverage'");
    }

    /**
     * Create a mock event dispatcher that captures dispatched events.
     */
    protected function createMockDispatcher(array &$events): EventDispatcherInterface
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$events) {
                $events[] = $event;
                return $event;
            });

        return $dispatcher;
    }
}
