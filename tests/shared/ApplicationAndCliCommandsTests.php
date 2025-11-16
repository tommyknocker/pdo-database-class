<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

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

    public function testMigrateCommandDryRunAndPretendViaApplication(): void
    {
        // Minimal migration directory
        $migrationDir = sys_get_temp_dir() . '/pdodb_cli_migrations_' . uniqid();
        mkdir($migrationDir, 0755, true);
        putenv('PDODB_MIGRATION_PATH=' . $migrationDir);

        // Create one empty migration file through MigrationGenerator API to keep dependencies minimal
        $genClass = new \ReflectionClass(\tommyknocker\pdodb\cli\MigrationGenerator::class);
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
        $this->assertStringContainsString('Would execute', $outDry);

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
}
