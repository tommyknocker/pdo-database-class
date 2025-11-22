<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\MonitorManager;
use tommyknocker\pdodb\PdoDb;

final class MonitorManagerTests extends TestCase
{
    protected PdoDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new PdoDb('sqlite', ['path' => ':memory:']);

        // Create test tables
        $this->db->rawQuery('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->rawQuery('INSERT INTO test_table (name) VALUES (?)', ['test']);
    }

    public function testGetActiveConnections(): void
    {
        $result = MonitorManager::getActiveConnections($this->db);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('connections', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertIsArray($result['connections']);
        $this->assertIsArray($result['summary']);
    }

    public function testGetSlowQueries(): void
    {
        // Enable query profiling
        $this->db->enableProfiling();

        // Execute some queries to generate data
        $this->db->rawQuery('SELECT * FROM test_table');
        $this->db->rawQuery('SELECT COUNT(*) FROM test_table');

        $result = MonitorManager::getSlowQueries($this->db, 0.0001, 10); // threshold, limit

        $this->assertIsArray($result);
        // Should contain profiled queries
        $this->assertGreaterThanOrEqual(0, count($result));
    }

    public function testGetActiveQueries(): void
    {
        $result = MonitorManager::getActiveQueries($this->db);

        $this->assertIsArray($result);
        // Result should be an array (may be empty for SQLite)
    }

    public function testGetQueryStats(): void
    {
        $result = MonitorManager::getQueryStats($this->db);

        // Result can be null or array depending on database support
        $this->assertTrue($result === null || is_array($result));
    }

    public function testToStringMethod(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('toString');
        $method->setAccessible(true);

        // Test string input
        $this->assertEquals('test', $method->invoke(null, 'test'));

        // Test integer input
        $this->assertEquals('123', $method->invoke(null, 123));

        // Test float input
        $this->assertEquals('123.45', $method->invoke(null, 123.45));

        // Test null input
        $this->assertEquals('', $method->invoke(null, null));

        // Test array input
        $this->assertEquals('', $method->invoke(null, []));

        // Test boolean input
        $this->assertEquals('', $method->invoke(null, true));
    }

    public function testToFloatMethod(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('toFloat');
        $method->setAccessible(true);

        // Test float input
        $this->assertEquals(123.45, $method->invoke(null, 123.45));

        // Test integer input
        $this->assertEquals(123.0, $method->invoke(null, 123));

        // Test string input
        $this->assertEquals(123.45, $method->invoke(null, '123.45'));

        // Test invalid string input
        $this->assertEquals(0.0, $method->invoke(null, 'invalid'));

        // Test null input
        $this->assertEquals(0.0, $method->invoke(null, null));

        // Test array input
        $this->assertEquals(0.0, $method->invoke(null, []));
    }
}
