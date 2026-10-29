<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\examples\QueryAdviceShowcase;

require_once __DIR__ . '/../../examples/07-performance/support/QueryAdviceShowcase.php';

final class AiAdviceShowcaseTests extends TestCase
{
    public function testOfflineShowcaseProvesPlanChangesAndIdenticalResults(): void
    {
        $report = (new QueryAdviceShowcase(10000))->run();
        self::assertFalse($report['ai_enabled']);
        self::assertCount(3, $report['cases']);
        [$page, $join, $existing] = $report['cases'];
        self::assertContains('SCAN orders', $page['before']['plan']);
        self::assertContains('USE TEMP B-TREE FOR ORDER BY', $page['before']['plan']);
        self::assertStringContainsString('idx_orders_status_created', implode(' ', $page['after']['plan']));
        self::assertNotContains('USE TEMP B-TREE FOR ORDER BY', $page['after']['plan']);
        self::assertTrue($page['same_rows']);
        self::assertSame(50, $page['after']['rows_returned']);
        self::assertSame(['main.users', 'main.orders'], $join['context_tables']);
        self::assertContains('SCAN o', $join['before']['plan']);
        self::assertStringContainsString('idx_users_country', implode(' ', $join['after']['plan']));
        self::assertStringContainsString('idx_orders_user_total', implode(' ', $join['after']['plan']));
        self::assertTrue($join['same_rows']);
        self::assertGreaterThan(0, $join['after']['rows_returned']);
        self::assertNull($existing['change_applied']);
        self::assertStringContainsString('idx_orders_user_created', implode(' ', $existing['before']['plan']));
        self::assertNotContains('USE TEMP B-TREE FOR ORDER BY', $existing['before']['plan']);
        foreach ($report['cases'] as $case) {
            self::assertNull($case['ai']);
            self::assertTrue($case['context_complete']);
            self::assertGreaterThanOrEqual(0, $case['before']['median_ms']);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAiModeMakesExactlyThreeBoundedCallsWithBothJoinSchemas(): void
    {
        require __DIR__ . '/../fixtures/ai-http-functions.php';
        $showcase = new QueryAdviceShowcase(10000, ['provider' => 'openrouter', 'openrouter_key' => 'example-key',
            'providers' => ['openrouter' => ['model' => 'test/model']]]);
        $report = $showcase->run(true, 9999, 9999);
        self::assertCount(3, $GLOBALS['ai_test_requests']);
        foreach ($GLOBALS['ai_test_requests'] as $request) {
            $body = json_decode($request['options']['http']['content'], true);
            self::assertSame(512, $body['max_tokens']);
            self::assertSame(90, $request['options']['http']['timeout']);
            self::assertStringNotContainsString('example-key', $body['messages'][1]['content']);
        }
        $join = json_decode($GLOBALS['ai_test_requests'][1]['options']['http']['content'], true)['messages'][1]['content'];
        self::assertStringContainsString('main.users', $join);
        self::assertStringContainsString('main.orders', $join);
        self::assertSame('Test analysis', $report['cases'][0]['ai']['text']);
        self::assertSame('test/model', $report['cases'][0]['ai']['model']);
    }

    public function testCliDefaultsToOfflineAndRejectsInvalidRowCounts(): void
    {
        $script = dirname(__DIR__, 2) . '/examples/07-performance/05-ai-query-advice.php';
        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --rows=10000 --json', $output, $code);
        self::assertSame(0, $code);
        $report = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($report['ai_enabled']);
        self::assertSame(10000, $report['environment']['fixture']['orders']);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --rows=0 2>&1', $output, $code);
        self::assertSame(1, $code);
    }
    public function testPublishedCaptureContainsVerifiablePlansAndResultChecksums(): void
    {
        $path = dirname(__DIR__, 2) . '/documentation/05-advanced-features/assets/ai-query-advice-sqlite.json';
        $capture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100000, $capture['environment']['fixture']['orders']);
        self::assertCount(3, $capture['cases']);
        foreach ($capture['cases'] as $case) {
            self::assertNotEmpty($case['ai']['text']);
            self::assertNotEmpty($case['ai']['model']);
            self::assertTrue($case['context_complete']);
            self::assertContains($case['capture_run'], array_column($capture['capture_runs'], 'id'));
            if (isset($case['after'])) {
                self::assertTrue($case['same_rows']);
                self::assertSame($case['before']['result_sha256'], $case['after']['result_sha256']);
                self::assertSame($case['before']['rows_returned'], $case['after']['rows_returned']);
            }
        }
    }

    public function testCliBlocksLiveCallsInCiBeforeLoadingPrivateSettings(): void
    {
        $script = dirname(__DIR__, 2) . '/examples/07-performance/05-ai-query-advice.php';
        $process = proc_open(
            [PHP_BINARY, $script, '--ai', '--env=/does/not/exist'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            ['CI' => '1']
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $output);
        self::assertStringContainsString('disabled in CI', $errors);
    }
}
