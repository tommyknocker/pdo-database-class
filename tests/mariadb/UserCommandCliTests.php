<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\exceptions\AuthenticationException;

final class UserCommandCliTests extends BaseMariaDBTestCase
{
    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testUserCreateCommandWithAllOptions(): void
    {
        putenv('PDODB_DRIVER=mariadb');
        putenv('PDODB_HOST=' . self::DB_HOST);
        putenv('PDODB_PORT=' . (string)self::DB_PORT);
        putenv('PDODB_DATABASE=' . self::DB_NAME);
        putenv('PDODB_USERNAME=' . self::DB_USER);
        putenv('PDODB_PASSWORD=' . self::DB_PASSWORD);
        putenv('PDODB_NON_INTERACTIVE=1');

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'user', 'create', 'testuser_cli', '--password', 'testpass123', '--host', 'localhost', '--force']);
            $out = ob_get_clean();
        } catch (AuthenticationException $e) {
            ob_end_clean();
            // Skip test if user doesn't have CREATE USER privilege
            $this->markTestSkipped('User does not have CREATE USER privilege: ' . $e->getMessage());
            return;
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('created successfully', $out);

        // Cleanup: drop the user (suppress output)
        ob_start();

        try {
            $app->run(['pdodb', 'user', 'drop', 'testuser_cli', '--host', 'localhost', '--force']);
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }
        ob_end_clean();

        // Clean up environment variables to avoid affecting subsequent tests
        putenv('PDODB_DRIVER');
        putenv('PDODB_HOST');
        putenv('PDODB_PORT');
        putenv('PDODB_DATABASE');
        putenv('PDODB_USERNAME');
        putenv('PDODB_PASSWORD');
        putenv('PDODB_NON_INTERACTIVE');
    }
}
