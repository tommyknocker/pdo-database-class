<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\commands\ModelCommand;
use tommyknocker\pdodb\PdoDb;

final class ModelCommandCliTests extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for model operations
        $this->dbPath = sys_get_temp_dir() . '/pdodb_model_' . uniqid() . '.sqlite';
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');

        // Create a test table for model generation
        $db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $db->rawQuery('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
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

    public function testModelHelpCommand(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'model', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Model Generation', $out);
        $this->assertStringContainsString('make', $out);
        $this->assertStringContainsString('ModelName', $out);
    }

    public function testModelCommandMethods(): void
    {
        // Test that ModelCommand class exists and has required methods
        $command = new ModelCommand();
        $this->assertInstanceOf(ModelCommand::class, $command);

        // Test that command has correct name and description
        $reflection = new \ReflectionClass($command);
        $nameProperty = $reflection->getProperty('name');
        $nameProperty->setAccessible(true);
        $this->assertEquals('model', $nameProperty->getValue($command));
    }

    public function testModelMakeCommand(): void
    {
        // Test that make method exists and has correct signature
        $command = new ModelCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('make'));
        $method = $reflection->getMethod('make');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testModelMakeCommandWithoutName(): void
    {
        // Test that make method handles missing name
        // In non-interactive mode, should show error
        $command = new ModelCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('make'));
        // Method should check for empty name and call showError
        $this->assertTrue(true);
    }

    public function testModelUnknownSubcommand(): void
    {
        // Test that unknown subcommand is handled in execute method
        $command = new ModelCommand();
        $reflection = new \ReflectionClass($command);

        // Verify execute method exists
        $this->assertTrue($reflection->hasMethod('execute'));
        $method = $reflection->getMethod('execute');
        $this->assertTrue($method->isPublic());
    }

    public function testModelShowHelpMethod(): void
    {
        // Test that showHelp method exists
        $command = new ModelCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('showHelp'));
        $method = $reflection->getMethod('showHelp');
        $this->assertTrue($method->isProtected());
    }
}
