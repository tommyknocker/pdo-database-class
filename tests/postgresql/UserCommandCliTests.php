<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\exceptions\AuthenticationException;

final class UserCommandCliTests extends BasePostgreSQLTestCase
{
    public function testUserCreateCommandWithAllOptions(): void
    {
        putenv('PDODB_DRIVER=pgsql');
        putenv('PDODB_HOST=' . self::DB_HOST);
        putenv('PDODB_PORT=' . (string)self::DB_PORT);
        putenv('PDODB_DATABASE=' . self::DB_NAME);
        putenv('PDODB_USERNAME=' . self::DB_USER);
        putenv('PDODB_PASSWORD=' . self::DB_PASSWORD);
        putenv('PDODB_NON_INTERACTIVE=1');
        // The CLI reports a failure as a message and exit code 1; with PDODB_DEBUG the exception propagates, which
        // is how this test tells "the test user may not create users" (skip) from a real failure.
        putenv('PDODB_DEBUG=1');

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'user', 'create', 'testuser_cli', '--password', 'testpass123', '--force']);
            $out = ob_get_clean();
        } catch (AuthenticationException $e) {
            ob_end_clean();
            // Skip test if user doesn't have CREATEROLE privilege
            $this->restoreEnvironment();
            $this->markTestSkipped('User does not have CREATEROLE privilege: ' . $e->getMessage());
            return;
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restoreEnvironment();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('created successfully', $out);

        // Cleanup: drop the user (suppress output)
        ob_start();

        try {
            $app->run(['pdodb', 'user', 'drop', 'testuser_cli', '--force']);
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }
        ob_end_clean();

        $this->restoreEnvironment();
    }

    private function restoreEnvironment(): void
    {
        // Clean up environment variables to avoid affecting subsequent tests
        foreach (['PDODB_DRIVER', 'PDODB_HOST', 'PDODB_PORT', 'PDODB_DATABASE', 'PDODB_USERNAME', 'PDODB_PASSWORD', 'PDODB_NON_INTERACTIVE', 'PDODB_DEBUG'] as $name) {
            putenv($name);
        }
    }
}
