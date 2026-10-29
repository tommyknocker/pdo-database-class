<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiLiveRunnerTests extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        $this->envPath = tempnam(sys_get_temp_dir(), 'pdodb-live-env-');
        chmod($this->envPath, 0600);
    }

    protected function tearDown(): void
    {
        unlink($this->envPath);
    }

    private function configure(string $baseUrl, string $model = 'test/model', string $reasoning = ''): void
    {
        file_put_contents($this->envPath, implode("\n", [
            'PDODB_AI_PROVIDER=openrouter',
            'PDODB_AI_OPENROUTER_KEY=fake-secret-do-not-print',
            'PDODB_AI_OPENROUTER_MODEL=' . $model,
            'PDODB_AI_OPENROUTER_BASE_URL=' . $baseUrl,
            'PDODB_AI_LIVE_MAX_TOKENS=9999',
            'PDODB_AI_LIVE_TIMEOUT=9999',
            'PDODB_AI_LIVE_REASONING_EFFORT=' . $reasoning,
        ]));
    }

    /** @return array{int, string, string} */
    private function runScript(array $arguments, bool $ci = false): array
    {
        $environment = array_filter(getenv(), static fn ($key) => !str_starts_with($key, 'PDODB_AI_')
            && !in_array($key, ['CI', 'GITHUB_ACTIONS'], true), ARRAY_FILTER_USE_KEY);
        if ($ci) {
            $environment['CI'] = '1';
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/test-ai-live.php', '--env=' . $this->envPath, ...$arguments],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 20);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $errors];
    }

    public function testDefaultOnlyChecksConfigurationAndRedactsCredentials(): void
    {
        // No server listens here. A successful check proves no HTTP call was attempted.
        $this->configure('http://127.0.0.1:1/v1');
        [$status, $output, $errors] = $this->runScript([]);
        self::assertSame(0, $status, $errors);
        self::assertStringContainsString('no API requests were made', $output);
        self::assertStringContainsString('up to 512 output tokens each, 90s timeout', $output);
        self::assertStringNotContainsString('fake-secret-do-not-print', $output . $errors);
    }

    public function testExplicitLiveRequestsAreBlockedInCi(): void
    {
        $this->configure('http://127.0.0.1:1/v1');
        [$status, $output, $errors] = $this->runScript(['--run'], true);
        self::assertSame(2, $status);
        self::assertStringContainsString('disabled in CI', $errors);
        self::assertStringNotContainsString('fake-secret-do-not-print', $output . $errors);
    }

    public static function liveCases(): array
    {
        return [
            'text response' => ['test/model', '', true],
            'reasoning consumed budget' => ['test/reasoning-budget', '', false],
            'reasoning explicitly disabled' => ['test/reasoning-budget', 'none', true],
        ];
    }

    #[DataProvider('liveCases')]
    public function testLiveWorkflowAgainstFakeLocalGateway(string $model, string $reasoning, bool $success): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../fixtures/ai-http-server.php', '3'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        try {
            stream_set_timeout($pipes[1], 5);
            $address = trim((string)fgets($pipes[1]));
            self::assertStringStartsWith('127.0.0.1:', $address);
            $this->configure('http://' . $address . '/v1', $model, $reasoning);
            [$status, $output, $errors] = $this->runScript(['--run']);
            self::assertSame($success ? 0 : 1, $status, $errors);
            if ($success) {
                self::assertSame(3, substr_count($output, 'PASS '));
                self::assertStringContainsString('in-memory SQLite', $output);
            } else {
                self::assertStringContainsString('Output limit exhausted before visible text', $errors);
                self::assertStringContainsString('PDODB_AI_LIVE_REASONING_EFFORT', $errors);
            }
            // The fake server echoes request headers inside model content. The runner must not log it.
            self::assertStringNotContainsString('fake-secret-do-not-print', $output . $errors);
            self::assertStringNotContainsString('Authorization', $output . $errors);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_terminate($process);
            proc_close($process);
        }
    }
}
