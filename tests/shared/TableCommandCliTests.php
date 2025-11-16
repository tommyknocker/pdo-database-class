<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

final class TableCommandCliTests extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for DDL operations
        $this->dbPath = sys_get_temp_dir() . '/pdodb_table_' . uniqid() . '.sqlite';
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

    public function testTableCreateInfoExistsDescribeListAndDrop(): void
    {
        $app = new Application();

        // create table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'users', '--columns=id:int,name:string:nullable', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('created successfully', $out);

        // exists
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'exists', 'users']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Table 'users' exists", $out);

        // info
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'info', 'users', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"table": "users"', $out);

        // describe
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'describe', 'users', '--format=table']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Columns:', $out);

        // list
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'list', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"users"', $out);

        // drop
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'drop', 'users', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('dropped successfully', $out);
    }

    public function testColumnsAndIndexesOnSqliteBasicFlow(): void
    {
        $app = new Application();
        // create table first
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'items', '--columns=id:int,name:string', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // add column
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'columns', 'add', 'items', 'price', '--type=float']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString("added to 'items'", $out);

        // list columns
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'columns', 'list', 'items', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('columns', $out);

        // create index
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'indexes', 'add', 'items', 'idx_items_name', '--columns=name']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Index 'idx_items_name' created", $out);

        // list indexes via info
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'info', 'items', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('indexes', $out);

        // drop index
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'indexes', 'drop', 'items', 'idx_items_name', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Index 'idx_items_name' dropped", $out);
    }
}
