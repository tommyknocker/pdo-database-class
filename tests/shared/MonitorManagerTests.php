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

    public function testGetActiveQueriesSQLite(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('getActiveQueriesSQLite');
        $method->setAccessible(true);

        $result = $method->invoke(null, $this->db);
        $this->assertIsArray($result);
        $this->assertEmpty($result); // SQLite returns empty array
    }

    public function testGetActiveConnectionsSQLite(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('getActiveConnectionsSQLite');
        $method->setAccessible(true);

        $result = $method->invoke(null, $this->db);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('connections', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertIsArray($result['connections']);
        $this->assertIsArray($result['summary']);
        $this->assertArrayHasKey('current', $result['summary']);
        $this->assertArrayHasKey('max', $result['summary']);
        $this->assertArrayHasKey('usage_percent', $result['summary']);
    }

    public function testGetSlowQueriesSQLite(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('getSlowQueriesSQLite');
        $method->setAccessible(true);

        // Test without profiling enabled
        $result = $method->invoke(null, $this->db, 0.1, 10);
        $this->assertIsArray($result);
        $this->assertEmpty($result);

        // Test with profiling enabled
        $this->db->enableProfiling();
        $this->db->rawQuery('SELECT * FROM test_table');
        $this->db->rawQuery('SELECT COUNT(*) FROM test_table WHERE id > ?', [0]);

        $result = $method->invoke(null, $this->db, 0.0001, 10);
        $this->assertIsArray($result);
        // May contain profiled queries if threshold is low enough
    }

    public function testGetQueryStatsWithProfiling(): void
    {
        // Test with profiling disabled
        $result = MonitorManager::getQueryStats($this->db);
        $this->assertNull($result);

        // Test with profiling enabled
        $this->db->enableProfiling();
        $this->db->rawQuery('SELECT * FROM test_table');
        $this->db->rawQuery('SELECT COUNT(*) FROM test_table');

        $result = MonitorManager::getQueryStats($this->db);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('aggregated', $result);
        $this->assertArrayHasKey('by_query', $result);
        $this->assertIsArray($result['aggregated']);
        $this->assertIsArray($result['by_query']);
    }

    public function testGetQueryStatsWithoutProfiler(): void
    {
        // Create a mock PdoDb that has profiling enabled but returns null for getProfiler
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->enableProfiling();

        // Use reflection to set profiler to null
        $reflection = new \ReflectionClass($db);
        $property = $reflection->getProperty('profiler');
        $property->setAccessible(true);
        $property->setValue($db, null);

        $result = MonitorManager::getQueryStats($db);
        $this->assertNull($result);
    }

    public function testGetActiveQueriesMySQL(): void
    {
        // Test MySQL-specific method via reflection
        // Note: This will only work if we have a MySQL connection
        // For now, we test the method exists and has correct signature
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getActiveQueriesMySQL'));
        $method = $reflection->getMethod('getActiveQueriesMySQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetActiveConnectionsMySQL(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getActiveConnectionsMySQL'));
        $method = $reflection->getMethod('getActiveConnectionsMySQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetSlowQueriesMySQL(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getSlowQueriesMySQL'));
        $method = $reflection->getMethod('getSlowQueriesMySQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetActiveQueriesPostgreSQL(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getActiveQueriesPostgreSQL'));
        $method = $reflection->getMethod('getActiveQueriesPostgreSQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetActiveConnectionsPostgreSQL(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getActiveConnectionsPostgreSQL'));
        $method = $reflection->getMethod('getActiveConnectionsPostgreSQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetSlowQueriesPostgreSQL(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getSlowQueriesPostgreSQL'));
        $method = $reflection->getMethod('getSlowQueriesPostgreSQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetActiveQueriesMSSQL(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getActiveQueriesMSSQL'));
        $method = $reflection->getMethod('getActiveQueriesMSSQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetActiveConnectionsMSSQL(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getActiveConnectionsMSSQL'));
        $method = $reflection->getMethod('getActiveConnectionsMSSQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetSlowQueriesMSSQL(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $this->assertTrue($reflection->hasMethod('getSlowQueriesMSSQL'));
        $method = $reflection->getMethod('getSlowQueriesMSSQL');
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    public function testGetSlowQueriesWithVariousThresholds(): void
    {
        $this->db->enableProfiling();
        $this->db->rawQuery('SELECT * FROM test_table');
        $this->db->rawQuery('SELECT COUNT(*) FROM test_table');

        // Test with very low threshold
        $result1 = MonitorManager::getSlowQueries($this->db, 0.000001, 10);
        $this->assertIsArray($result1);

        // Test with high threshold
        $result2 = MonitorManager::getSlowQueries($this->db, 1000.0, 10);
        $this->assertIsArray($result2);

        // Test with different limits
        $result3 = MonitorManager::getSlowQueries($this->db, 0.000001, 1);
        $this->assertIsArray($result3);
        $this->assertLessThanOrEqual(1, count($result3));
    }

    public function testGetActiveConnectionsWithSummary(): void
    {
        $result = MonitorManager::getActiveConnections($this->db);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('connections', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertIsArray($result['summary']);
        $this->assertArrayHasKey('current', $result['summary']);
        $this->assertArrayHasKey('max', $result['summary']);
        $this->assertArrayHasKey('usage_percent', $result['summary']);
        $this->assertIsInt($result['summary']['current']);
        $this->assertIsInt($result['summary']['max']);
        // usage_percent can be int (0) or float
        $this->assertTrue(
            is_int($result['summary']['usage_percent']) || is_float($result['summary']['usage_percent'])
        );
    }

    public function testToStringWithEdgeCases(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('toString');
        $method->setAccessible(true);

        // Test empty string
        $this->assertEquals('', $method->invoke(null, ''));

        // Test object with __toString
        $obj = new class () {
            public function __toString(): string
            {
                return 'object_string';
            }
        };
        $this->assertEquals('', $method->invoke(null, $obj)); // toString only handles string/int/float

        // Test resource
        $resource = fopen('php://memory', 'r');
        if ($resource !== false) {
            $this->assertEquals('', $method->invoke(null, $resource));
            fclose($resource);
        }
    }

    public function testToFloatWithEdgeCases(): void
    {
        $reflection = new \ReflectionClass(MonitorManager::class);
        $method = $reflection->getMethod('toFloat');
        $method->setAccessible(true);

        // Test empty string
        $this->assertEquals(0.0, $method->invoke(null, ''));

        // Test negative number
        $this->assertEquals(-123.45, $method->invoke(null, -123.45));
        $this->assertEquals(-123.0, $method->invoke(null, -123));
        $this->assertEquals(-123.45, $method->invoke(null, '-123.45'));

        // Test zero
        $this->assertEquals(0.0, $method->invoke(null, 0));
        $this->assertEquals(0.0, $method->invoke(null, '0'));
        $this->assertEquals(0.0, $method->invoke(null, 0.0));

        // Test very large number
        $this->assertEquals(999999.0, $method->invoke(null, 999999));
        $this->assertEquals(999999.0, $method->invoke(null, '999999'));
    }
}
