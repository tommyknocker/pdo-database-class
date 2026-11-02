<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\commands\UserCommand;

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

    public function testPasswordCanComeFromAnEnvironmentVariable(): void
    {
        putenv('PDODB_TEST_USER_PASSWORD=from-env');
        $command = new UserCommand();
        $command->setOptions(['password-env' => 'PDODB_TEST_USER_PASSWORD']);

        try {
            $password = (new \ReflectionMethod($command, 'resolvePassword'))->invoke($command, 'Enter password');
        } finally {
            putenv('PDODB_TEST_USER_PASSWORD');
        }

        $this->assertSame('from-env', $password);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function failingInvocations(): array
    {
        $unsupported = 'User management is not supported for sqlite';

        return [
            'unknown subcommand' => [['bogus'], 'Unknown subcommand: bogus'],
            'create without a username' => [['create'], 'Username is required'],
            'create without a password' => [['create', 'bob'], 'Password is required'],
            'create' => [['create', 'bob', '--password-env=PDODB_TEST_NO_SUCH_VAR'], 'Password is required'],
            'drop' => [['drop', 'bob', '--force'], $unsupported],
            'exists' => [['exists', 'bob'], $unsupported],
            'list' => [['list'], $unsupported],
            'info' => [['info', 'bob'], $unsupported],
            'grant' => [['grant', 'bob', 'SELECT', '--database', 'x'], $unsupported],
            'revoke' => [['revoke', 'bob', 'SELECT', '--database', 'x'], $unsupported],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('failingInvocations')]
    public function testUserSubcommandsReportWhyTheyFailAndExitNonZero(array $arguments, string $message): void
    {
        $result = CliTestCapture::runApplication(new Application(), array_merge(['pdodb', 'user'], $arguments));

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString($message, $result['output']);
    }

    public function testPasswordOnTheCommandLineIsWarnedAbout(): void
    {
        $result = CliTestCapture::runApplication(new Application(), ['pdodb', 'user', 'password', 'bob', '--password=secret']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('--password puts the password in the process list', $result['output']);
        $this->assertStringContainsString('User management is not supported for sqlite', $result['output']);
        $this->assertStringNotContainsString('secret', $result['output'], 'the password is never echoed');
    }

    public function testUserCommandIsRegisteredUnderItsName(): void
    {
        $this->assertSame('user', (new UserCommand())->getName());
    }
}
