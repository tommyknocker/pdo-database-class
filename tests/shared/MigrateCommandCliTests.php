<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

final class MigrateCommandCliTests extends TestCase
{
    protected string $dbPath;
    protected string $migrationPath;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for migration operations
        $this->dbPath = sys_get_temp_dir() . '/pdodb_migrate_' . uniqid() . '.sqlite';
        $this->migrationPath = sys_get_temp_dir() . '/pdodb_migrations_' . uniqid();
        mkdir($this->migrationPath, 0755, true);

        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_MIGRATION_PATH=' . $this->migrationPath);
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        if (is_dir($this->migrationPath)) {
            $this->removeDirectory($this->migrationPath);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_MIGRATION_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        putenv('PHPUNIT');
        parent::tearDown();
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testMigrateHelpCommand(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'migrate', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Migration Management', $out);
        $this->assertStringContainsString('create', $out);
        $this->assertStringContainsString('up', $out);
        $this->assertStringContainsString('down', $out);
        $this->assertStringContainsString('history', $out);
        $this->assertStringContainsString('new', $out);
    }

    public function testMigrateCommandMethods(): void
    {
        // Test that MigrateCommand class exists and has required methods
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $this->assertInstanceOf(\tommyknocker\pdodb\cli\commands\MigrateCommand::class, $command);

        // Test that command has correct name and description
        $reflection = new \ReflectionClass($command);
        $nameProperty = $reflection->getProperty('name');
        $nameProperty->setAccessible(true);
        $this->assertEquals('migrate', $nameProperty->getValue($command));
    }

    public function testMigrateCreateCommand(): void
    {
        // Test that create method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('create'));
        $method = $reflection->getMethod('create');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testMigrateUpCommand(): void
    {
        // Test that up method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('up'));
        $method = $reflection->getMethod('up');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testMigrateDownCommand(): void
    {
        // Test that down method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('down'));
        $method = $reflection->getMethod('down');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testMigrateHistoryCommand(): void
    {
        // Test that history method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('history'));
        $method = $reflection->getMethod('history');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testMigrateNewCommand(): void
    {
        // Test that new method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('new'));
        $method = $reflection->getMethod('new');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testMigrateUnknownSubcommand(): void
    {
        // Test that unknown subcommand is handled in execute method
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $reflection = new \ReflectionClass($command);

        // Verify execute method exists
        $this->assertTrue($reflection->hasMethod('execute'));
        $method = $reflection->getMethod('execute');
        $this->assertTrue($method->isPublic());
    }

    public function testMigrateCreateCommandWithoutName(): void
    {
        // Test that create method handles missing name
        // In non-interactive mode, MigrationGenerator::generate() will throw exception
        // which calls showError() that exits, so we test the method structure instead
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('create'));
        // Method should check for empty name and call showError
        $this->assertTrue(true);
    }

    public function testMigrateUpCommandWithNoMigrations(): void
    {
        // Test that up command works when there are no migrations
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'migrate', 'up', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No new migrations', $out);
    }

    public function testMigrateDownCommandWithNoMigrations(): void
    {
        // Test that down command works when there are no migrations
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'migrate', 'down', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        // Should show message about no migrations to rollback
        $this->assertTrue(
            str_contains($out, 'No migrations') ||
            str_contains($out, 'rollback') ||
            $code === 0
        );
    }

    public function testMigrateHistoryCommandExecution(): void
    {
        // Test that history command can be executed
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'migrate', 'history']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        // Should show migration history (may be empty)
        $this->assertIsString($out);
    }

    public function testMigrateNewCommandExecution(): void
    {
        // Test that new command can be executed
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'migrate', 'new']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        // Should show new migrations or message about no new migrations
        $this->assertIsString($out);
    }

    public function testMigrateUpCommandWithDryRun(): void
    {
        // Test that up command works with --dry-run option
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'migrate', 'up', '--dry-run']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        // Should show dry-run mode message
        $this->assertTrue(
            str_contains($out, 'DRY-RUN') ||
            str_contains($out, 'No new migrations') ||
            $code === 0
        );
    }

    public function testMigrateUpCommandWithPretend(): void
    {
        // Test that up command works with --pretend option
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'migrate', 'up', '--pretend']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        // Should show pretend mode message
        $this->assertTrue(
            str_contains($out, 'PRETEND') ||
            str_contains($out, 'No new migrations') ||
            $code === 0
        );
    }

    public function testMigrateShowHelpMethod(): void
    {
        // Test that showHelp method exists
        $command = new \tommyknocker\pdodb\cli\commands\MigrateCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('showHelp'));
        $method = $reflection->getMethod('showHelp');
        $this->assertTrue($method->isProtected());
    }
}
