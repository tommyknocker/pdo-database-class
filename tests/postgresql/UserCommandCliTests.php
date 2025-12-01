<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\cli\Application;

final class UserCommandCliTests extends BasePostgreSQLTestCase
{
    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testUserCreateCommandWithAllOptions(): void
    {
        putenv('PDODB_DRIVER=pgsql');
        putenv('PDODB_HOST=' . self::DB_HOST);
        putenv('PDODB_PORT=' . (string)self::DB_PORT);
        putenv('PDODB_DATABASE=' . self::DB_NAME);
        putenv('PDODB_USERNAME=' . self::DB_USER);
        putenv('PDODB_PASSWORD=' . self::DB_PASSWORD);
        putenv('PDODB_NON_INTERACTIVE=1');

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'user', 'create', 'testuser_cli', '--password', 'testpass123', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

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
    }
}
