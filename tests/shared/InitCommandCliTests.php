<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

/**
 * Tests for InitCommand CLI.
 */
final class InitCommandCliTests extends TestCase
{
    protected string $tempDir;
    protected string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $this->tempDir = sys_get_temp_dir() . '/pdodb_init_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->originalCwd = getcwd();
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        putenv('PDODB_NON_INTERACTIVE');
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * Recursively remove directory.
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testInitCommandHelp(): void
    {
        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--help']);
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertIsString($out);
        // Help message should contain initialization info
        $this->assertTrue(
            str_contains($out, 'PDOdb Project Initialization') ||
            str_contains($out, 'Initialize PDOdb') ||
            str_contains($out, 'Usage: pdodb init')
        );
    }

    public function testInitCommandWithEnvOnly(): void
    {
        // Set environment variables for non-interactive mode
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=./test.sqlite');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');

        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--env-only', '--skip-connection-test', '--no-structure', '--force']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($this->tempDir . '/.env');
        $this->assertFileDoesNotExist($this->tempDir . '/config/db.php');

        $envContent = file_get_contents($this->tempDir . '/.env');
        $this->assertStringContainsString('PDODB_DRIVER=sqlite', $envContent);
        $this->assertStringContainsString('PDODB_PATH=./test.sqlite', $envContent);
    }

    public function testInitCommandWithConfigOnly(): void
    {
        // Set environment variables for non-interactive mode
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=./test.sqlite');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');

        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--config-only', '--skip-connection-test', '--no-structure', '--force']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertFileDoesNotExist($this->tempDir . '/.env');
        $this->assertFileExists($this->tempDir . '/config/db.php');

        $configContent = file_get_contents($this->tempDir . '/config/db.php');
        $this->assertStringContainsString("'driver' => 'sqlite'", $configContent);
        $this->assertStringContainsString("'path' => './test.sqlite'", $configContent);
    }

    public function testInitCommandCreatesDirectoryStructure(): void
    {
        // Set environment variables for non-interactive mode
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=./test.sqlite');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_MIGRATION_PATH=./migrations');
        putenv('PDODB_MODEL_PATH=./app/Models');
        putenv('PDODB_REPOSITORY_PATH=./app/Repositories');
        putenv('PDODB_SERVICE_PATH=./app/Services');
        putenv('PDODB_SEED_PATH=./database/seeds');

        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--env-only', '--skip-connection-test', '--force']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertDirectoryExists($this->tempDir . '/migrations');
        $this->assertDirectoryExists($this->tempDir . '/app/Models');
        $this->assertDirectoryExists($this->tempDir . '/app/Repositories');
        $this->assertDirectoryExists($this->tempDir . '/app/Services');
        $this->assertDirectoryExists($this->tempDir . '/database/seeds');
    }

    public function testInitCommandNoStructure(): void
    {
        // Set environment variables for non-interactive mode
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=./test.sqlite');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');

        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--env-only', '--skip-connection-test', '--no-structure', '--force']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertDirectoryDoesNotExist($this->tempDir . '/migrations');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/app');
    }

    public function testInitCommandWithExistingFiles(): void
    {
        // Create existing files
        file_put_contents($this->tempDir . '/.env', 'EXISTING_CONTENT');
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/db.php', '<?php return [];');

        // Set environment variables for non-interactive mode
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=./test.sqlite');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');

        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--env-only', '--skip-connection-test', '--no-structure', '--force']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $envContent = file_get_contents($this->tempDir . '/.env');
        $this->assertStringNotContainsString('EXISTING_CONTENT', $envContent);
        $this->assertStringContainsString('PDODB_DRIVER', $envContent);
    }

    public function testInitCommandEnvFileContainsAllSettings(): void
    {
        // Set environment variables for non-interactive mode
        putenv('PDODB_DRIVER=mysql');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=3306');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_CHARSET=utf8mb4');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=filesystem');
        putenv('PDODB_CACHE_DIRECTORY=./storage/cache');

        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--env-only', '--skip-connection-test', '--no-structure', '--force']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $envContent = file_get_contents($this->tempDir . '/.env');
        $this->assertStringContainsString('PDODB_DRIVER=mysql', $envContent);
        $this->assertStringContainsString('PDODB_HOST=localhost', $envContent);
        $this->assertStringContainsString('PDODB_PORT=3306', $envContent);
        $this->assertStringContainsString('PDODB_DATABASE=testdb', $envContent);
        $this->assertStringContainsString('PDODB_USERNAME=testuser', $envContent);
        $this->assertStringContainsString('PDODB_PASSWORD=testpass', $envContent);
        $this->assertStringContainsString('PDODB_CHARSET=utf8mb4', $envContent);
        $this->assertStringContainsString('PDODB_CACHE_ENABLED=true', $envContent);
        $this->assertStringContainsString('PDODB_CACHE_TYPE=filesystem', $envContent);
        $this->assertStringContainsString('PDODB_CACHE_DIRECTORY=./storage/cache', $envContent);
    }

    public function testInitCommandConfigFileContainsAllSettings(): void
    {
        // Set environment variables for non-interactive mode
        putenv('PDODB_DRIVER=mysql');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=3306');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_CHARSET=utf8mb4');

        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--config-only', '--skip-connection-test', '--no-structure', '--force']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $configContent = file_get_contents($this->tempDir . '/config/db.php');
        $this->assertStringContainsString("'driver' => 'mysql'", $configContent);
        $this->assertStringContainsString("'host' => 'localhost'", $configContent);
        $this->assertStringContainsString("'port' => 3306", $configContent);
        $this->assertStringContainsString("'database' => 'testdb'", $configContent);
        $this->assertStringContainsString("'username' => 'testuser'", $configContent);
        $this->assertStringContainsString("'password' => 'testpass'", $configContent);
        $this->assertStringContainsString("'charset' => 'utf8mb4'", $configContent);
    }

    public function testInitCommandPostgresqlRequiresDbname(): void
    {
        // Set environment variables for PostgreSQL (non-interactive mode)
        putenv('PDODB_DRIVER=pgsql');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=5432');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');

        $app = new Application();
        ob_start();
        $code = $app->run(['pdodb', 'init', '--config-only', '--skip-connection-test', '--no-structure', '--force']);
        $configContent = file_get_contents($this->tempDir . '/config/db.php');
        ob_end_clean();

        $this->assertSame(0, $code);
        // PostgreSQL requires 'dbname' parameter for DSN
        $this->assertStringContainsString("'dbname' => 'testdb'", $configContent);
        $this->assertStringContainsString("'database' => 'testdb'", $configContent);
    }
}
