<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;

final class QueryCommandCliTests extends TestCase
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

    public function testQueryHelp(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'query', 'help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Query Testing', $out);
        $this->assertStringContainsString('Usage:', $out);
        $this->assertStringContainsString('query explain', $out);
        $this->assertStringContainsString('query format', $out);
        $this->assertStringContainsString('query validate', $out);
    }

    public function testQueryExecutesSingleSelect(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'query', 'test', 'SELECT 42 AS val']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('val', $out);
        $this->assertStringContainsString('Total rows: 1', $out);
    }

    public function testQueryExplainValidateAndFormat(): void
    {
        $app = new Application();

        // explain
        ob_start();

        try {
            $code1 = $app->run(['pdodb', 'query', 'explain', 'SELECT 1']);
            $out1 = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code1);
        $this->assertStringContainsString('EXPLAIN plan:', $out1);

        // validate ok
        ob_start();

        try {
            $code2 = $app->run(['pdodb', 'query', 'validate', 'SELECT 1']);
            $out2 = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code2);
        $this->assertStringContainsString('SQL is valid', $out2);

        // validate fail
        ob_start();

        try {
            $code3 = $app->run(['pdodb', 'query', 'validate', 'SELEC 1']);
            $out3 = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(1, $code3);
        $this->assertStringContainsString('Invalid SQL', $out3);

        // format
        ob_start();

        try {
            $code4 = $app->run(['pdodb', 'query', 'format', 'select  *  from users where  id=1 order   by  name']);
            $out4 = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code4);
        // formatted output should include uppercase keywords and line breaks before major clauses
        $this->assertStringContainsString('SELECT', $out4);
        $this->assertStringContainsString('FROM', $out4);
        $this->assertStringContainsString('WHERE', $out4);
        $this->assertStringContainsString('ORDER BY', $out4);
        $this->assertTrue(strpos($out4, "\n") !== false, 'Expected formatted SQL to contain line breaks');
    }
}
