<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;

final class UiCommandCliTests extends TestCase
{
    protected string $dbPath;
    protected PdoDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/pdodb_ui_' . uniqid() . '.sqlite';
        $this->db = new PdoDb('sqlite', ['path' => $this->dbPath]);

        // Create test table
        $this->db->rawQuery('CREATE TABLE test_ui (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->rawQuery('INSERT INTO test_ui (name) VALUES (?)', ['Test 1']);

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

    public function testUiHelpCommand(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'ui', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('TUI Dashboard', $out);
        $this->assertStringContainsString('refresh', $out);
    }

    public function testUiCommandExists(): void
    {
        $app = new Application();
        $command = $app->getCommand('ui');

        $this->assertNotNull($command);
        $this->assertEquals('ui', $command->getName());
    }

    public function testUiCommandInNonInteractiveMode(): void
    {
        // In non-interactive mode, UI command should exit gracefully
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'ui']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        // Should exit gracefully (not crash)
        $this->assertStringContainsString('interactive terminal', $out);
    }

    public function testUiCommandWithRefreshOption(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'ui', '--refresh=5']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        // Should exit gracefully in non-interactive mode
        $this->assertStringContainsString('interactive terminal', $out);
    }
}
