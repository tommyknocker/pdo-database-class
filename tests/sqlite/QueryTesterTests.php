<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\QueryTester;

final class QueryTesterTests extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_NON_INTERACTIVE=1');
    }

    protected function tearDown(): void
    {
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        parent::tearDown();
    }

    public function testExecuteSelectQueryOnce(): void
    {
        ob_start();
        try {
            QueryTester::test('SELECT 1 AS one');
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $this->assertStringContainsString('PDOdb Query Tester (REPL)', $out);
        $this->assertStringContainsString('Database:', $out);
        $this->assertStringContainsString('one', $out);
        $this->assertStringContainsString('Total rows: 1', $out);
    }

    public function testExecuteNonSelectQuery(): void
    {
        ob_start();
        try {
            QueryTester::test('CREATE TABLE t (id INTEGER)');
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $this->assertStringContainsString('Query executed successfully.', $out);
    }
}


