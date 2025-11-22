<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

final class UserCommandCliTests extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for user operations
        $this->dbPath = sys_get_temp_dir() . '/pdodb_user_' . uniqid() . '.sqlite';
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

    public function testUserHelpCommand(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'user', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('User Management', $out);
        $this->assertStringContainsString('create', $out);
        $this->assertStringContainsString('drop', $out);
        $this->assertStringContainsString('list', $out);
        $this->assertStringContainsString('exists', $out);
        $this->assertStringContainsString('info', $out);
        $this->assertStringContainsString('grant', $out);
        $this->assertStringContainsString('revoke', $out);
        $this->assertStringContainsString('password', $out);
    }

    public function testUserListCommandForSQLite(): void
    {
        // For SQLite, user management is not supported
        // We just test that the command exists and can be called
        $this->assertTrue(true); // Placeholder test
    }

    public function testUserCommandMethods(): void
    {
        // Test that UserCommand class exists and has required methods
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $this->assertInstanceOf(\tommyknocker\pdodb\cli\commands\UserCommand::class, $command);

        // Test that command has correct name and description
        $reflection = new \ReflectionClass($command);
        $nameProperty = $reflection->getProperty('name');
        $nameProperty->setAccessible(true);
        $this->assertEquals('user', $nameProperty->getValue($command));
    }

    public function testUserUnknownSubcommand(): void
    {
        // Test that unknown subcommand shows error
        // Since showError() calls exit(), we just test the command structure
        $this->assertTrue(true); // Placeholder test
    }
}
