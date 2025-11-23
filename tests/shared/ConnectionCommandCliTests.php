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

    public function testConnectionInfoCommandWithYamlFormat(): void
    {
        $app = new Application();

        // Test info command (YAML format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'info', '--format=yaml']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('name:', $out);
        $this->assertStringContainsString('driver:', $out);
    }

    public function testConnectionListCommandWithYamlFormat(): void
    {
        $app = new Application();

        // Test list command (YAML format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'list', '--format=yaml']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('connections:', $out);
    }

    public function testConnectionTestCommandWithConnectionOption(): void
    {
        $app = new Application();

        // Test connection with --connection option
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'test', '--connection=default']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('successful', $out);
    }

    public function testConnectionInfoCommandWithConnectionOption(): void
    {
        $app = new Application();

        // Test info command with --connection option
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'info', '--connection=default', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('driver', $json);
    }

    public function testConnectionInfoCommandWithConnectionNameArgument(): void
    {
        $app = new Application();

        // Test info command with connection name as argument
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'info', 'default', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('driver', $json);
    }

    public function testConnectionPingCommandWithConnectionOption(): void
    {
        $app = new Application();

        // Test ping command with --connection option
        ob_start();

        try {
            $code = $app->run(['pdodb', 'connection', 'ping', '--connection=default']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Ping successful', $out);
    }

    public function testConnectionCommandWithMultiConnectionConfig(): void
    {
        $app = new Application();
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $configDir = $tempDir . '/config';
        mkdir($configDir, 0755, true);
        $configFile = $configDir . '/db.php';

        // Create multi-connection config
        $configContent = <<<'PHP'
<?php
return [
    'default' => 'primary',
    'connections' => [
        'primary' => [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ],
        'secondary' => [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ],
    ],
];
PHP;
        file_put_contents($configFile, $configContent);

        $oldCwd = getcwd();
        chdir($tempDir);
        putenv('PDODB_CONFIG_PATH=' . $configFile);

        try {
            // Test list command with multi-connection config
            ob_start();

            try {
                $code = $app->run(['pdodb', 'connection', 'list', '--format=json']);
                $out = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }
            $this->assertSame(0, $code);
            $json = json_decode($out, true);
            $this->assertIsArray($json);
            $this->assertArrayHasKey('connections', $json);
            $this->assertIsArray($json['connections']);
            $this->assertGreaterThanOrEqual(1, count($json['connections']));

            // Test info command with specific connection
            ob_start();

            try {
                $code = $app->run(['pdodb', 'connection', 'info', 'secondary', '--format=json']);
                $out = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }
            $this->assertSame(0, $code);
            $json = json_decode($out, true);
            $this->assertIsArray($json);
            $this->assertArrayHasKey('driver', $json);
        } finally {
            chdir($oldCwd);
            if (file_exists($configFile)) {
                @unlink($configFile);
            }
            if (is_dir($configDir)) {
                @rmdir($configDir);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
            putenv('PDODB_CONFIG_PATH');
        }
    }

    public function testConnectionCommandWithSingleConnectionConfig(): void
    {
        $app = new Application();
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $configDir = $tempDir . '/config';
        mkdir($configDir, 0755, true);
        $configFile = $configDir . '/db.php';

        // Create single connection config
        $configContent = <<<'PHP'
<?php
return [
    'driver' => 'sqlite',
    'path' => ':memory:',
];
PHP;
        file_put_contents($configFile, $configContent);

        $oldCwd = getcwd();
        chdir($tempDir);
        putenv('PDODB_CONFIG_PATH=' . $configFile);

        try {
            // Test list command with single connection config
            ob_start();

            try {
                $code = $app->run(['pdodb', 'connection', 'list', '--format=json']);
                $out = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }
            $this->assertSame(0, $code);
            $json = json_decode($out, true);
            $this->assertIsArray($json);
            $this->assertArrayHasKey('connections', $json);
            $this->assertIsArray($json['connections']);
            $this->assertCount(1, $json['connections']);
        } finally {
            chdir($oldCwd);
            if (file_exists($configFile)) {
                @unlink($configFile);
            }
            if (is_dir($configDir)) {
                @rmdir($configDir);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
            putenv('PDODB_CONFIG_PATH');
        }
    }

    public function testConnectionInfoCommandWithAllFormats(): void
    {
        $app = new Application();

        $formats = ['table', 'json', 'yaml'];

        foreach ($formats as $format) {
            ob_start();

            try {
                $code = $app->run(['pdodb', 'connection', 'info', '--format=' . $format]);
                $out = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }
            $this->assertSame(0, $code, "Format {$format} should succeed");
            $this->assertNotEmpty($out, "Format {$format} should produce output");
        }
    }

    public function testConnectionListCommandWithAllFormats(): void
    {
        $app = new Application();

        $formats = ['table', 'json', 'yaml'];

        foreach ($formats as $format) {
            ob_start();

            try {
                $code = $app->run(['pdodb', 'connection', 'list', '--format=' . $format]);
                $out = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }
            $this->assertSame(0, $code, "Format {$format} should succeed");
            $this->assertNotEmpty($out, "Format {$format} should produce output");
        }
    }
}
