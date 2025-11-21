<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

final class ConnectionCommandCliTests extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for connection tests
        $this->dbPath = sys_get_temp_dir() . '/pdodb_connection_' . uniqid() . '.sqlite';
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
        putenv('PDODB_CONNECTION');
        parent::tearDown();
    }

    public function testConnectionTestCommand(): void
    {
        $app = new Application();

        // Test connection
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'test']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('successful', $out);
    }

    public function testConnectionInfoCommand(): void
    {
        $app = new Application();

        // Test info command (table format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'info', '--format=table']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Connection Information', $out);
        $this->assertStringContainsString('Driver', $out);
        $this->assertStringContainsString('sqlite', $out);

        // Test info command (JSON format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'info', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"driver"', $out);
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('driver', $json);
    }

    public function testConnectionListCommand(): void
    {
        $app = new Application();

        // Test list command (table format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'list', '--format=table']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Available Connections', $out);
        $this->assertStringContainsString('Connection:', $out);

        // Test list command (JSON format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'list', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"connections"', $out);
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('connections', $json);
        $this->assertIsArray($json['connections']);
    }

    public function testConnectionPingCommand(): void
    {
        $app = new Application();

        // Test ping command
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'ping']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Ping successful', $out);
        $this->assertStringContainsString('response time', $out);
    }

    public function testConnectionHelpCommand(): void
    {
        $app = new Application();

        // Test help command
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Connection Management', $out);
        $this->assertStringContainsString('test', $out);
        $this->assertStringContainsString('info', $out);
        $this->assertStringContainsString('list', $out);
        $this->assertStringContainsString('ping', $out);
    }
}
