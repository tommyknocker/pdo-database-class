<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\MigrationGenerator;
use tommyknocker\pdodb\PackageVersion;

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

    public function testUnknownCommandSuggestionsGoToStderr(): void
    {
        $app = new Application();
        $run = CliTestCapture::runApplication($app, ['pdodb', 'migrat']);

        $this->assertSame(1, $run['code']);
        $this->assertStringContainsString('Error: Unknown command: migrat', $run['stderr']);
        $this->assertStringContainsString('Did you mean', $run['stderr']);
        $this->assertStringNotContainsString('Did you mean', $run['stdout']);
    }

    public function testMisspelledDryRunFlagIsRejectedBeforeAnythingRuns(): void
    {
        foreach (['--dryrun', '--dry_run', '--dry-runn'] as $flag) {
            $run = CliTestCapture::runApplication(new Application(), ['pdodb', 'migrate', 'up', $flag]);

            $this->assertSame(2, $run['code'], $flag);
            $this->assertStringContainsString('Did you mean --dry-run?', $run['stderr'], $flag);
        }
    }

    public function testNonInteractiveRunKeepsTheRealExitCode(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $this->assertNotFalse($bin);
        $output = [];
        exec('PDODB_NON_INTERACTIVE=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' no-such-command 2>&1', $output, $code);

        $this->assertSame(1, $code, 'a CI run must see the command failure, not exit code 255');
        $this->assertStringNotContainsString('Uncaught', implode("\n", $output));
    }

    public function testFailingCommandReportsOnceAndExitsWithItsCode(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $this->assertNotFalse($bin);
        $output = [];
        exec('PDODB_NON_INTERACTIVE=1 PDODB_DRIVER=sqlite PDODB_PATH=:memory: ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' seed run no_such_seed 2>&1', $output, $code);
        $text = implode("\n", $output);

        $this->assertSame(1, $code);
        $this->assertSame(1, substr_count($text, 'Error:'), $text);
        $this->assertStringNotContainsString('Uncaught', $text);
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
        ob_start();

        try {
            $codeCreate = $app->run(['pdodb', 'db', 'create', $this->tmpDbFile, '--force']);
            $outCreate = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $codeCreate);
        $this->assertStringContainsString('created successfully', $outCreate);
        $this->assertFileExists($this->tmpDbFile);

        // exists (should print success and return 0)
        ob_start();

        try {
            $codeExists = $app->run(['pdodb', 'db', 'exists', $this->tmpDbFile]);
            $outExists = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $codeExists);
        $this->assertStringContainsString('exists', $outExists);

        // drop
        ob_start();

        try {
            $codeDrop = $app->run(['pdodb', 'db', 'drop', $this->tmpDbFile, '--force']);
            $outDrop = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
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

    public function testMigrateCommandDryRunViaApplication(): void
    {
        // Minimal migration directory
        $migrationDir = sys_get_temp_dir() . '/pdodb_cli_migrations_' . uniqid();
        mkdir($migrationDir, 0755, true);
        putenv('PDODB_MIGRATION_PATH=' . $migrationDir);

        // Create one empty migration file through MigrationGenerator API to keep dependencies minimal
        $genClass = new \ReflectionClass(MigrationGenerator::class);
        $generate = $genClass->getMethod('generate');
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
            str_contains($outDry, 'Migration:') ||
            str_contains($outDry, 'DRY-RUN') ||
            str_contains($outDry, 'Dry-run') ||
            str_contains($outDry, 'No new migrations'),
            'Dry-run output should list migrations or show dry-run mode'
        );

        // migrate down --dry-run (skip confirmation)
        ob_start();

        try {
            $codeDownDry = $app->run(['pdodb', 'migrate', 'down', '--dry-run']);
            $outDownDry = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $codeDownDry);
        $this->assertTrue(
            str_contains($outDownDry, 'DRY-RUN') ||
            str_contains($outDownDry, 'Rollback') ||
            str_contains($outDownDry, 'No migrations to rollback'),
            'Expected dry-run rollback output or a no-migrations message'
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
        $this->assertShowsPackageVersion($output);
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
        $this->assertShowsPackageVersion($output);
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
        $this->assertShowsPackageVersion($output);
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

    /**
     * The banner names the version PackageVersion finds. That is a number where the package has Composer metadata or a
     * git tag to read it from, and "dev" in a bare checkout without tags (a shallow CI clone), so the test cannot
     * demand digits; it demands that the banner and the detector agree and that the detector gives one of the two.
     */
    private function assertShowsPackageVersion(string $output): void
    {
        $version = PackageVersion::detect();

        $this->assertMatchesRegularExpression('/^(\d+\.\d+\.\d+.*|dev)$/', $version);
        $this->assertStringContainsString('PDOdb CLI v' . $version, $output);
    }
}
