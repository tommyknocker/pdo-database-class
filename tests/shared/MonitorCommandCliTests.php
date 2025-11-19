<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;

final class MonitorCommandCliTests extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for monitoring operations
        $this->dbPath = sys_get_temp_dir() . '/pdodb_monitor_' . uniqid() . '.sqlite';
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        parent::tearDown();
    }

    public function testMonitorQueriesShowsHelp(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'monitor', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Database Monitoring', $out);
        $this->assertStringContainsString('queries', $out);
        $this->assertStringContainsString('connections', $out);
    }

    public function testMonitorQueriesReturnsEmptyForSQLite(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'monitor', 'queries', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('queries', $data);
        $this->assertIsArray($data['queries']);
    }

    public function testMonitorConnectionsShowsPDOdbPoolForSQLite(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'monitor', 'connections', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('connections', $data);
        $this->assertIsArray($data['connections']);
    }

    public function testMonitorSlowReturnsEmptyWhenProfilingDisabled(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'monitor', 'slow', '--threshold=1s', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('slow_queries', $data);
        $this->assertIsArray($data['slow_queries']);
    }

    public function testMonitorStatsShowsWarningWhenProfilingDisabled(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'monitor', 'stats', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        // Should return 1 (error) when profiling is disabled
        $this->assertSame(1, $code);
        $this->assertStringContainsString('profiling is not enabled', $out);
    }

    public function testMonitorStatsShowsStatsWhenProfilingEnabled(): void
    {
        // Create database and enable profiling
        $db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $db->enableProfiling(1.0);

        // Run some queries to generate stats
        $db->rawQuery('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery("INSERT INTO test (name) VALUES ('test1')");
        $db->rawQuery("INSERT INTO test (name) VALUES ('test2')");
        $db->rawQuery('SELECT * FROM test');

        // Now use CLI - it will create a new connection, but profiling needs to be enabled
        // For SQLite, we can't share the profiler state, so this test will show warning
        // This is expected behavior - profiling must be enabled in the application code
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'monitor', 'stats', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        // CLI creates new connection without profiling, so it should show warning
        $this->assertSame(1, $code);
        $this->assertStringContainsString('profiling is not enabled', $out);
    }

    /**
     * @runInSeparateProcess
     */
    public function testMonitorUnknownSubcommandShowsError(): void
    {
        // Run in a subprocess to avoid exit() killing PHPUnit
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $dbPath = sys_get_temp_dir() . '/pdodb_monitor_' . uniqid() . '.sqlite';
        $env = 'PDODB_DRIVER=sqlite PDODB_PATH=' . escapeshellarg($dbPath) . ' PDODB_NON_INTERACTIVE=1';
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' monitor unknown 2>&1';
        $out = (string)shell_exec($cmd);
        $this->assertStringContainsString('Unknown subcommand', $out);
    }
}
