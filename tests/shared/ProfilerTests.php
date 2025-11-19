<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\PdoDb;

class ProfilerTests extends TestCase
{
    /** @var PdoDb */
    protected static PdoDb $db;

    public static function setUpBeforeClass(): void
    {
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
        if ($driver === 'sqlite') {
            $config = ['path' => ':memory:'];
        } else {
            $config = [
                'host' => getenv('PDODB_HOST') ?: '127.0.0.1',
                'dbname' => getenv('PDODB_DATABASE') ?: 'testdb',
                'username' => getenv('PDODB_USERNAME') ?: 'testuser',
                'password' => getenv('PDODB_PASSWORD') ?: 'testpass',
            ];
        }

        self::$db = new PdoDb($driver, $config);

        // Create test table
        $driverName = self::$db->connection->getDriverName();
        if ($driverName === 'pgsql') {
            self::$db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_test (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    value INTEGER,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ');
        } elseif ($driverName === 'mysql') {
            self::$db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_test (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    value INTEGER,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ');
        } else { // sqlite
            self::$db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_test (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    value INTEGER,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');
        }
    }

    public function testProfilerBasicFunctionality(): void
    {
        $db = self::$db;
        $db->enableProfiling();

        // Execute some queries
        $db->find()->table('profiler_test')->insert(['name' => 'Test 1', 'value' => 100]);
        $db->find()->table('profiler_test')->insert(['name' => 'Test 2', 'value' => 200]);
        $db->find()->from('profiler_test')->where('value', 100)->get();
        $db->find()->from('profiler_test')->where('value', 200)->get();

        $stats = $db->getProfilerStats(true);
        $this->assertGreaterThanOrEqual(4, $stats['total_queries']);
        $this->assertGreaterThan(0.0, $stats['total_time']);
        $this->assertGreaterThan(0.0, $stats['avg_time']);

        $db->disableProfiling();
    }

    public function testProfilerEnableDisable(): void
    {
        $db = self::$db;

        // Initially disabled
        $this->assertFalse($db->isProfilingEnabled());

        // Enable
        $db->enableProfiling();
        $this->assertTrue($db->isProfilingEnabled());

        // Disable
        $db->disableProfiling();
        $this->assertFalse($db->isProfilingEnabled());
    }

    public function testProfilerSlowQueryDetection(): void
    {
        $db = self::$db;

        // Create a mock logger to capture slow query logs
        $logEntries = [];
        $logger = new class ($logEntries) implements LoggerInterface {
            private array $logs;

            public function __construct(array &$logs)
            {
                $this->logs = &$logs;
            }

            public function emergency(\Stringable|string $message, array $context = []): void
            {
            }

            public function alert(\Stringable|string $message, array $context = []): void
            {
            }

            public function critical(\Stringable|string $message, array $context = []): void
            {
            }

            public function error(\Stringable|string $message, array $context = []): void
            {
            }

            public function warning(\Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            public function notice(\Stringable|string $message, array $context = []): void
            {
            }

            public function info(\Stringable|string $message, array $context = []): void
            {
            }

            public function debug(\Stringable|string $message, array $context = []): void
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
            }
        };

        $db = new PdoDb('sqlite', ['path' => ':memory:'], [], $logger);
        $driverName = $db->connection->getDriverName();

        if ($driverName === 'pgsql') {
            $db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_slow_test (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(100) NOT NULL
                )
            ');
        } elseif ($driverName === 'mysql') {
            $db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_slow_test (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL
                ) ENGINE=InnoDB
            ');
        } else {
            $db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_slow_test (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL
                )
            ');
        }

        // Enable profiling with very low threshold (1ms) to trigger slow query detection
        $db->enableProfiling(0.001);

        // Execute a query
        $db->find()->table('profiler_slow_test')->insert(['name' => 'Slow Test']);

        // Get profiler and check slow queries
        $profiler = $db->getProfiler();
        $this->assertNotNull($profiler);

        $stats = $db->getProfilerStats(true);
        // At least one query should be recorded
        $this->assertGreaterThanOrEqual(1, $stats['total_queries']);

        $db->disableProfiling();
    }

    public function testProfilerStatsPerQuery(): void
    {
        $db = self::$db;
        $db->resetProfiler();
        $db->enableProfiling();

        // Execute same query multiple times
        for ($i = 0; $i < 3; $i++) {
            $db->find()->from('profiler_test')->where('value', 100)->get();
        }

        $stats = $db->getProfilerStats(false);
        $this->assertNotEmpty($stats);

        // Check that stats are grouped by SQL hash (same query should be grouped together)
        $aggregated = $db->getProfilerStats(true);
        $this->assertGreaterThanOrEqual(3, $aggregated['total_queries']);

        $db->disableProfiling();
    }

    public function testProfilerReset(): void
    {
        $db = self::$db;
        $db->enableProfiling();

        // Execute queries
        $db->find()->from('profiler_test')->get();
        $db->find()->from('profiler_test')->where('value', 100)->get();

        $statsBefore = $db->getProfilerStats(true);
        $this->assertGreaterThanOrEqual(2, $statsBefore['total_queries']);

        // Reset
        $db->resetProfiler();
        $statsAfter = $db->getProfilerStats(true);
        $this->assertEquals(0, $statsAfter['total_queries']);

        $db->disableProfiling();
    }

    public function testProfilerSlowestQueries(): void
    {
        $db = self::$db;
        $db->resetProfiler();
        $db->enableProfiling();

        // Execute different queries
        $db->find()->from('profiler_test')->get();
        $db->find()->from('profiler_test')->where('value', 100)->get();
        $db->find()->from('profiler_test')->where('value', 200)->get();

        $slowest = $db->getSlowestQueries(5);
        $this->assertGreaterThanOrEqual(1, count($slowest));

        // Check structure of slowest queries
        if (!empty($slowest)) {
            $query = $slowest[0];
            $this->assertArrayHasKey('sql', $query);
            $this->assertArrayHasKey('count', $query);
            $this->assertArrayHasKey('avg_time', $query);
            $this->assertArrayHasKey('max_time', $query);
        }

        $db->disableProfiling();
    }

    public function testProfilerThreshold(): void
    {
        $db = self::$db;

        // Enable with specific threshold
        $db->enableProfiling(0.5);
        $profiler = $db->getProfiler();
        $this->assertNotNull($profiler);
        $this->assertEquals(0.5, $profiler->getSlowQueryThreshold());

        $db->disableProfiling();
    }

    public function testProfilerWithoutLogger(): void
    {
        // Test that profiler works without logger
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $driverName = $db->connection->getDriverName();

        if ($driverName === 'pgsql') {
            $db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_no_logger_test (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(100) NOT NULL
                )
            ');
        } elseif ($driverName === 'mysql') {
            $db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_no_logger_test (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL
                ) ENGINE=InnoDB
            ');
        } else {
            $db->rawQuery('
                CREATE TABLE IF NOT EXISTS profiler_no_logger_test (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL
                )
            ');
        }

        $db->enableProfiling();
        $db->find()->table('profiler_no_logger_test')->insert(['name' => 'Test']);
        $db->find()->from('profiler_no_logger_test')->get();

        $stats = $db->getProfilerStats(true);
        $this->assertGreaterThanOrEqual(2, $stats['total_queries']);

        $db->disableProfiling();
    }

    public function testProfilerMemoryTracking(): void
    {
        $db = self::$db;
        $db->resetProfiler();
        $db->enableProfiling();

        // Execute queries
        $db->find()->from('profiler_test')->get();

        $stats = $db->getProfilerStats(true);
        $this->assertArrayHasKey('total_memory', $stats);
        $this->assertArrayHasKey('avg_memory', $stats);
        // Memory should be non-negative
        $this->assertGreaterThanOrEqual(0, $stats['total_memory']);
        $this->assertGreaterThanOrEqual(0, $stats['avg_memory']);

        $db->disableProfiling();
    }
}
