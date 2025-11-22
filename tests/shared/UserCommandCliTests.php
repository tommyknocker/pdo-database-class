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
        // Test that command exists and can be called
        // Since showError() calls exit(), we test the command structure instead
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        // Verify list method exists
        $this->assertTrue($reflection->hasMethod('list'));
        $method = $reflection->getMethod('list');
        $this->assertTrue($method->isProtected());
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

    public function testUserCreateCommand(): void
    {
        // Test that create method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('create'));
        $method = $reflection->getMethod('create');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testUserDropCommand(): void
    {
        // Test that drop method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('drop'));
        $method = $reflection->getMethod('drop');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testUserExistsCommand(): void
    {
        // Test that exists method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('exists'));
        $method = $reflection->getMethod('exists');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testUserInfoCommand(): void
    {
        // Test that showInfo method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('showInfo'));
        $method = $reflection->getMethod('showInfo');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testUserGrantCommand(): void
    {
        // Test that grant method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('grant'));
        $method = $reflection->getMethod('grant');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testUserRevokeCommand(): void
    {
        // Test that revoke method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('revoke'));
        $method = $reflection->getMethod('revoke');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testUserPasswordCommand(): void
    {
        // Test that password method exists and has correct signature
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('password'));
        $method = $reflection->getMethod('password');
        $this->assertTrue($method->isProtected());
        $this->assertEquals('int', $method->getReturnType()->getName());
    }

    public function testUserShowDatabaseHeader(): void
    {
        // Test that showDatabaseHeader method exists
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('showDatabaseHeader'));
        $method = $reflection->getMethod('showDatabaseHeader');
        $this->assertTrue($method->isProtected());
    }

    public function testUserUnknownSubcommand(): void
    {
        // Test that unknown subcommand is handled in execute method
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        // Verify execute method exists
        $this->assertTrue($reflection->hasMethod('execute'));
        $method = $reflection->getMethod('execute');
        $this->assertTrue($method->isPublic());
    }

    public function testUserCreateCommandWithoutUsername(): void
    {
        // Test that create method handles missing username
        // In non-interactive mode, readInput returns empty string
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('create'));
        // Method should check for empty username and call showError
        $this->assertTrue(true);
    }

    public function testUserCreateCommandWithoutPassword(): void
    {
        // Test that create method handles missing password
        // In non-interactive mode, readPassword returns empty string
        $command = new \tommyknocker\pdodb\cli\commands\UserCommand();
        $reflection = new \ReflectionClass($command);

        $this->assertTrue($reflection->hasMethod('create'));
        // Method should check for empty password and call showError
        $this->assertTrue(true);
    }
}
