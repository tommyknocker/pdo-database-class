<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\MigrationGenerator;

final class ApplicationAndCliCommandsTests extends TestCase
{
    protected string $tmpDbFile;

    protected function setUp(): void
    {
        parent::setUp();
        // Default to sqlite and non-interactive for CLI flows
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_NON_INTERACTIVE=1');
        $this->tmpDbFile = sys_get_temp_dir() . '/pdodb_cli_test_' . uniqid() . '.sqlite';
        if (file_exists($this->tmpDbFile)) {
            @unlink($this->tmpDbFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpDbFile)) {
            @unlink($this->tmpDbFile);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        parent::tearDown();
    }

    public function testApplicationShowsHelpWithNoArgs(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PDOdb CLI', $output);
        $this->assertStringContainsString('Available commands:', $output);
        $this->assertStringContainsString('db', $output);
        $this->assertStringContainsString('migrate', $output);
    }

    public function testDbInfoCommandViaApplication(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'info']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PDOdb Database Management', $output);
        $this->assertStringContainsString('Database Information:', $output);
    }

    public function testDbCreateExistsDropWithForceViaApplication(): void
    {
        $app = new Application();

        // create
        // Temporarily unset PHPUNIT to allow output
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $codeCreate = $app->run(['pdodb', 'db', 'create', $this->tmpDbFile, '--force']);
            $outCreate = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        // Restore PHPUNIT
        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }

        $this->assertSame(0, $codeCreate);
        $this->assertStringContainsString('created successfully', $outCreate);
        $this->assertFileExists($this->tmpDbFile);

        // exists (should print success and return 0)
        // Temporarily unset PHPUNIT to allow output
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $codeExists = $app->run(['pdodb', 'db', 'exists', $this->tmpDbFile]);
            $outExists = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        // Restore PHPUNIT
        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }

        $this->assertSame(0, $codeExists);
        $this->assertStringContainsString('exists', $outExists);

        // drop
        // Temporarily unset PHPUNIT to allow output
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $codeDrop = $app->run(['pdodb', 'db', 'drop', $this->tmpDbFile, '--force']);
            $outDrop = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        // Restore PHPUNIT
        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }

        $this->assertSame(0, $codeDrop);
        $this->assertStringContainsString('dropped successfully', $outDrop);
        $this->assertFileDoesNotExist($this->tmpDbFile);
    }

    public function testGlobalEnvOptionLoadsCustomEnvFile(): void
    {
        $tmpDir = sys_get_temp_dir() . '/pdodb_env_' . uniqid();
        @mkdir($tmpDir, 0777, true);
        $dbPath = $tmpDir . '/test.sqlite';
        file_put_contents($tmpDir . '/.env.custom', "PDODB_DRIVER=sqlite\nPDODB_PATH={$dbPath}\nPDODB_NON_INTERACTIVE=1\n");

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'info', '--env=' . $tmpDir . '/.env.custom']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Database: sqlite', $out);
    }

    public function testGlobalConfigOptionLoadsCustomConfigFile(): void
    {
        $tmpDir = sys_get_temp_dir() . '/pdodb_cfg_' . uniqid();
        @mkdir($tmpDir, 0777, true);
        $dbPath = $tmpDir . '/test.sqlite';
        $configPath = $tmpDir . '/db.php';
        $php = <<<PHP
<?php
return [
    'driver' => 'sqlite',
    'path' => '{$dbPath}',
];
PHP;
        file_put_contents($configPath, $php);

        // Ensure env does not interfere
        putenv('PDODB_DRIVER');
        unset($_ENV['PDODB_DRIVER']);

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'info', '--config=' . $configPath]);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Database: sqlite', $out);
    }

    public function testMigrateCommandDryRunAndPretendViaApplication(): void
    {
        // Minimal migration directory
        $migrationDir = sys_get_temp_dir() . '/pdodb_cli_migrations_' . uniqid();
        mkdir($migrationDir, 0755, true);
        putenv('PDODB_MIGRATION_PATH=' . $migrationDir);

        // Create one empty migration file through MigrationGenerator API to keep dependencies minimal
        $genClass = new \ReflectionClass(MigrationGenerator::class);
        $generate = $genClass->getMethod('generate');
        $generate->setAccessible(true);
        ob_start();

        try {
            $filename = $generate->invoke(null, 'cli_dummy_migration', $migrationDir);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertFileExists($filename);

        $app = new Application();

        // migrate up --dry-run
        ob_start();

        try {
            $codeDry = $app->run(['pdodb', 'migrate', 'up', '--dry-run']);
            $outDry = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $codeDry);
        // In dry-run mode, should show SQL queries or migration info
        $this->assertTrue(
            str_contains($outDry, 'CREATE TABLE') ||
            str_contains($outDry, 'Would execute') ||
            str_contains($outDry, 'Migration:') ||
            str_contains($outDry, 'DRY-RUN'),
            'Dry-run output should contain SQL queries or migration info'
        );

        // migrate down --pretend (skip confirmation with --force)
        ob_start();

        try {
            $codePretend = $app->run(['pdodb', 'migrate', 'down', '--pretend', '--force']);
            $outPretend = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $codePretend);
        $this->assertTrue(
            str_contains($outPretend, 'Rollback') || str_contains($outPretend, 'No migrations to rollback'),
            'Expected rollback output or a no-migrations message'
        );

        // Cleanup
        $files = glob($migrationDir . '/*');
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($migrationDir);
        putenv('PDODB_MIGRATION_PATH');
    }

    public function testUserCommandHelpViaApplication(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'user', 'help']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('User Management', $output);
        $this->assertStringContainsString('Subcommands:', $output);
    }

    public function testVersionCommand(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'version']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PDOdb CLI', $output);
        $this->assertStringContainsString('v', $output);
        // Version should be in format like "2.11.0" or similar
        $this->assertMatchesRegularExpression('/v\d+\.\d+\.\d+/', $output);
    }

    public function testVersionFlag(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', '--version']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PDOdb CLI', $output);
        $this->assertStringContainsString('v', $output);
        // Version should be in format like "2.11.0" or similar
        $this->assertMatchesRegularExpression('/v\d+\.\d+\.\d+/', $output);
    }

    public function testVersionShortFlag(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', '-v']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PDOdb CLI', $output);
        $this->assertStringContainsString('v', $output);
        // Version should be in format like "2.11.0" or similar
        $this->assertMatchesRegularExpression('/v\d+\.\d+\.\d+/', $output);
    }

    public function testBenchmarkCommandHelp(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'benchmark', 'help']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Benchmark and Performance Testing', $output);
        $this->assertStringContainsString('Subcommands:', $output);
        $this->assertStringContainsString('query', $output);
        $this->assertStringContainsString('crud', $output);
        $this->assertStringContainsString('load', $output);
    }

    public function testBenchmarkQueryCommand(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'benchmark', 'query', 'SELECT 1', '--iterations=10']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Query Benchmark Results', $output);
        $this->assertStringContainsString('Iterations:', $output);
        $this->assertStringContainsString('Total time:', $output);
        $this->assertStringContainsString('Queries per second:', $output);
    }

    public function testBenchmarkQueryCommandWithError(): void
    {
        // Run in separate process to avoid exit() terminating PHPUnit
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $this->assertNotFalse($bin, 'pdodb binary should exist');
        $env = 'PDODB_NON_INTERACTIVE=1';
        $cmd = 'cd ' . escapeshellarg(getcwd()) . ' && ' . $env . ' php ' . escapeshellarg($bin) . ' benchmark query 2>&1';
        $output = shell_exec($cmd);

        // Check if command executed and returned output
        $this->assertNotNull($output, 'Command should return output. Command: ' . $cmd);
        $this->assertNotEmpty($output, 'Command output should not be empty');

        // Check for error message (case-insensitive)
        $hasError = str_contains(strtolower($output), 'sql query is required') ||
                    str_contains(strtolower($output), 'required') ||
                    str_contains(strtolower($output), 'error');

        $this->assertTrue($hasError, 'Should show error when SQL query is not provided. Output: ' . $output);
    }

    public function testBenchmarkCrudCommand(): void
    {
        // Run in separate process to avoid exit() terminating PHPUnit
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $this->assertNotFalse($bin, 'pdodb binary should exist');
        $env = 'PDODB_NON_INTERACTIVE=1';
        $cmd = 'cd ' . escapeshellarg(getcwd()) . ' && ' . $env . ' php ' . escapeshellarg($bin) . ' benchmark crud non_existent_table 2>&1';
        $output = shell_exec($cmd);

        // Check if command executed and returned output
        $this->assertNotNull($output, 'Command should return output. Command: ' . $cmd);
        $this->assertNotEmpty($output, 'Command output should not be empty');

        // Check for error message (case-insensitive, various possible messages)
        $hasError = str_contains(strtolower($output), 'does not exist') ||
                    str_contains(strtolower($output), 'not found') ||
                    str_contains(strtolower($output), 'error') ||
                    str_contains(strtolower($output), 'table') && str_contains(strtolower($output), 'exist');

        $this->assertTrue($hasError, 'Should show error for non-existent table. Output: ' . $output);
    }

    public function testBenchmarkCrudCommandWithNonExistentTable(): void
    {
        // Run in separate process to avoid exit() terminating PHPUnit
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $this->assertNotFalse($bin, 'pdodb binary should exist');
        $env = 'PDODB_NON_INTERACTIVE=1';
        $cmd = 'cd ' . escapeshellarg(getcwd()) . ' && ' . $env . ' php ' . escapeshellarg($bin) . ' benchmark crud non_existent_table 2>&1';
        $output = shell_exec($cmd);

        // Check if command executed and returned output
        $this->assertNotNull($output, 'Command should return output. Command: ' . $cmd);
        $this->assertNotEmpty($output, 'Command output should not be empty');

        // Check for error message (case-insensitive, various possible messages)
        $hasError = str_contains(strtolower($output), 'does not exist') ||
                    str_contains(strtolower($output), 'not found') ||
                    str_contains(strtolower($output), 'error') ||
                    str_contains(strtolower($output), 'table') && str_contains(strtolower($output), 'exist');

        $this->assertTrue($hasError, 'Should show error for non-existent table. Output: ' . $output);
    }

    public function testBenchmarkLoadCommand(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'benchmark', 'load', '--connections=5', '--duration=2', '--query=SELECT 1']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Load Testing', $output);
        $this->assertStringContainsString('Connections:', $output);
        $this->assertStringContainsString('Duration:', $output);
        $this->assertStringContainsString('Total queries:', $output);
    }

    public function testBenchmarkProfileCommand(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'benchmark', 'profile', '--query=SELECT 1', '--iterations=10', '--slow-threshold=100ms']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Query Profile Results', $output);
        $this->assertStringContainsString('Aggregated Statistics:', $output);
    }

    public function testBenchmarkProfileCommandWithoutQuery(): void
    {
        // Run in separate process to avoid exit() terminating PHPUnit
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $this->assertNotFalse($bin, 'pdodb binary should exist');
        $env = 'PDODB_NON_INTERACTIVE=1';
        $cmd = 'cd ' . escapeshellarg(getcwd()) . ' && ' . $env . ' php ' . escapeshellarg($bin) . ' benchmark profile 2>&1';
        $output = shell_exec($cmd);

        // Check if command executed and returned output
        $this->assertNotNull($output, 'Command should return output. Command: ' . $cmd);
        $this->assertNotEmpty($output, 'Command output should not be empty');

        // Command may show help or error, both are acceptable (case-insensitive)
        $outputLower = strtolower($output);
        $hasMessage = str_contains($outputLower, 'please specify') ||
                      str_contains($outputLower, 'specify a query') ||
                      str_contains($outputLower, 'query') && (str_contains($outputLower, 'required') || str_contains($outputLower, 'missing')) ||
                      str_contains($outputLower, 'help') ||
                      str_contains($outputLower, 'usage') ||
                      str_contains($outputLower, 'error');

        $this->assertTrue($hasMessage, 'Should show error or help when query is not specified. Output: ' . $output);
    }

    public function testBenchmarkCompareCommand(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'benchmark', 'compare', '--query=SELECT 1', '--iterations=10']);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Benchmark Comparison', $output);
    }

    public function testBenchmarkReportCommand(): void
    {
        $app = new Application();
        $reportFile = sys_get_temp_dir() . '/benchmark-report-' . uniqid() . '.html';

        ob_start();

        try {
            $code = $app->run(['pdodb', 'benchmark', 'report', '--query=SELECT 1', '--iterations=10', '--output=' . $reportFile]);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('saved to:', $output);
        $this->assertFileExists($reportFile);

        // Check report content
        $content = file_get_contents($reportFile);
        $this->assertStringContainsString('PDOdb Benchmark Report', $content);
        $this->assertStringContainsString('SELECT 1', $content);

        @unlink($reportFile);
    }
}
