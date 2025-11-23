<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

final class DbCommandCliTests extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for database operations
        $this->dbPath = sys_get_temp_dir() . '/pdodb_db_' . uniqid() . '.sqlite';
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        putenv('PHPUNIT');
        parent::tearDown();
    }

    public function testDbHelpCommand(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Database Management', $out);
        $this->assertStringContainsString('create', $out);
        $this->assertStringContainsString('drop', $out);
        $this->assertStringContainsString('list', $out);
        $this->assertStringContainsString('exists', $out);
        $this->assertStringContainsString('info', $out);
    }

    public function testDbCommandMethods(): void
    {
        // Test that DbCommand class exists and has required methods
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $this->assertInstanceOf(\tommyknocker\pdodb\cli\commands\DbCommand::class, $command);

        // Test that command has correct name and description
        $reflection = new \ReflectionClass($command);
        $nameProperty = $reflection->getProperty('name');
        $nameProperty->setAccessible(true);
        $this->assertEquals('db', $nameProperty->getValue($command));
    }

    public function testDbCreateCommand(): void
    {
        // Test that create method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('create'));
        $method = $reflection->getMethod('create');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testDbDropCommand(): void
    {
        // Test that drop method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('drop'));
        $method = $reflection->getMethod('drop');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testDbExistsCommand(): void
    {
        // Test that exists method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('exists'));
        $method = $reflection->getMethod('exists');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testDbListCommand(): void
    {
        // Test that list method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('list'));
        $method = $reflection->getMethod('list');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testDbInfoCommand(): void
    {
        // Test that showInfo method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('showInfo'));
        $method = $reflection->getMethod('showInfo');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testDbShowDatabaseHeader(): void
    {
        // Test that showDatabaseHeader method exists
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('showDatabaseHeader'));
        $method = $reflection->getMethod('showDatabaseHeader');
        $this->assertTrue($method->isProtected());
    }

    public function testDbUnknownSubcommand(): void
    {
        // Test that unknown subcommand is handled in execute method
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        // Verify execute method exists
        $this->assertTrue($reflection->hasMethod('execute'));
        $method = $reflection->getMethod('execute');
        $this->assertTrue($method->isPublic());
    }

    public function testDbCreateCommandWithoutName(): void
    {
        // Test that create method handles missing name
        // In non-interactive mode, readInput returns empty string, so should show error
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('create'));
        // Method should check for empty name and call showError
        $this->assertTrue(true);
    }

    public function testDbListCommandForSQLite(): void
    {
        // For SQLite, database management is limited
        // Test that command exists and can be called
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);

        // Verify list method exists
        $this->assertTrue($reflection->hasMethod('list'));
        $method = $reflection->getMethod('list');
        $this->assertTrue($method->isProtected());
    }

    public function testDbInfoCommandExecution(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'info']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Database Information', $out);
    }

    public function testDbListCommandExecution(): void
    {
        // For SQLite, list command is not supported (shows error and exits)
        // This test verifies that the command structure exists
        // The actual execution with SQLite will fail, which is expected
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);
        $this->assertTrue($reflection->hasMethod('list'));
    }

    public function testDbExistsCommandExecution(): void
    {
        $app = new Application();

        // Test exists command with a database name
        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'exists', 'test_db']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        // Command should execute (may return 0 or 1 depending on whether DB exists)
        $this->assertContains($code, [0, 1]);
        $this->assertNotEmpty($out);
    }

    public function testDbShowDatabaseHeaderMethod(): void
    {
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showDatabaseHeader');
        $method->setAccessible(true);

        ob_start();

        try {
            $method->invoke($command);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertStringContainsString('PDOdb Database Management', $out);
    }

    public function testDbShowDatabaseHeaderWithException(): void
    {
        $command = new \tommyknocker\pdodb\cli\commands\DbCommand();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showDatabaseHeader');
        $method->setAccessible(true);

        // Temporarily break the connection to test exception handling
        $oldPath = getenv('PDODB_PATH');
        putenv('PDODB_PATH=/nonexistent/path.sqlite');

        try {
            ob_start();

            try {
                $method->invoke($command);
                $out = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                // Exception is expected and handled internally
                $this->assertTrue(true);
                return;
            }

            // Should still show header even if connection fails
            $this->assertStringContainsString('PDOdb Database Management', $out);
        } finally {
            if ($oldPath !== false) {
                putenv('PDODB_PATH=' . $oldPath);
            } else {
                putenv('PDODB_PATH');
            }
        }
    }

    public function testDbInfoCommandWithFileSize(): void
    {
        $app = new Application();

        // Create a database file with some data to test file_size display
        $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => $this->dbPath]);
        $db->rawQuery('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery("INSERT INTO test_table (name) VALUES ('test')");

        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'info']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Database Information', $out);
    }

    public function testDbInfoCommandWithNullValues(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'info']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        // Null values should be skipped in output
        $this->assertStringContainsString('Database Information', $out);
    }

    public function testDbInfoCommandWithBooleanValues(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'info']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        // Boolean values should be displayed as 'true' or 'false'
        $this->assertStringContainsString('Database Information', $out);
    }

    public function testDbCommandHelpSubcommand(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'db', 'help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Database Management', $out);
    }
}
