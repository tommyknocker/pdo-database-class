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
        $this->assertStringContainsString('Usage: pdodb query test', $out);
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
}
