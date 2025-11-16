<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;

final class SchemaCommandCliTests extends TestCase
{
    protected PdoDb $db;
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('PDODB_DRIVER=sqlite');
        $this->dbPath = sys_get_temp_dir() . '/sc_cmd_' . uniqid() . '.sqlite';
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
        $this->db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $this->db->rawQuery('CREATE TABLE sc_users (id INTEGER PRIMARY KEY, name TEXT)');
    }

    protected function tearDown(): void
    {
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        if (isset($this->dbPath) && file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testSchemaHelp(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'schema', 'help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Schema Inspection', $out);
        $this->assertStringContainsString('Usage: pdodb schema inspect', $out);
    }

    public function testSchemaInspectAllTables(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'schema', 'inspect']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('sc_users', $out);
    }

    public function testSchemaInspectTableJsonAndYaml(): void
    {
        $app = new Application();
        // JSON
        ob_start();

        try {
            $codeJson = $app->run(['pdodb', 'schema', 'inspect', 'sc_users', '--format=json']);
            $outJson = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $codeJson);
        $decoded = json_decode($outJson, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('sc_users', $decoded['table'] ?? null);

        // YAML (just check key markers exist)
        ob_start();

        try {
            $codeYaml = $app->run(['pdodb', 'schema', 'inspect', 'sc_users', '--format=yaml']);
            $outYaml = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $codeYaml);
        $this->assertStringContainsString('table:', $outYaml);
        $this->assertStringContainsString('columns:', $outYaml);
    }
}
