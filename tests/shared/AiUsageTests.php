<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\AiTransport;
use tommyknocker\pdodb\ai\AiUsage;
use tommyknocker\pdodb\ai\providers\AnthropicProvider;
use tommyknocker\pdodb\ai\providers\GoogleProvider;
use tommyknocker\pdodb\ai\providers\OllamaProvider;
use tommyknocker\pdodb\ai\providers\OpenAiCompatibleProvider;
use tommyknocker\pdodb\ai\providers\YandexProvider;

final class AiUsageTests extends BaseSharedTestCase
{
    /**
     * @param array<string, mixed> $answer
     */
    private function transport(array $answer): AiTransport
    {
        return new class ($answer) implements AiTransport {
            /** @param array<string, mixed> $answer */
            public function __construct(private array $answer)
            {
            }

            public function post(string $url, array $data, array $headers, int $timeout): array
            {
                return $this->answer;
            }
        };
    }

    private function config(string $provider, array $extra = []): AiConfig
    {
        return new AiConfig(['ai' => ['provider' => $provider, 'api_key' => 'k', 'base_url' => 'https://llm.example.test/v1', 'model' => 'm'] + $extra]);
    }

    public function testEachProviderReadsItsOwnUsageFigures(): void
    {
        $openAi = new OpenAiCompatibleProvider($this->config('openai-compatible'), 'openai-compatible', $this->transport([
            'choices' => [['message' => ['content' => 'hi']]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 4, 'total_tokens' => 15],
        ]));
        $openAi->analyzeQuery('SELECT 1');
        $this->assertSame([11, 4, 15], [$openAi->getLastUsage()?->inputTokens, $openAi->getLastUsage()?->outputTokens, $openAi->getLastUsage()?->totalTokens]);

        $anthropic = new AnthropicProvider($this->config('anthropic'), $this->transport([
            'content' => [['type' => 'text', 'text' => 'hi']],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 5],
        ]));
        $anthropic->analyzeQuery('SELECT 1');
        $this->assertSame([20, 5, 25], array_values((array)$anthropic->getLastUsage()?->toArray()), 'total is the sum when only the parts are given');

        $google = new GoogleProvider($this->config('google'), $this->transport([
            'candidates' => [['content' => ['parts' => [['text' => 'hi']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 3, 'totalTokenCount' => 10],
        ]));
        $google->analyzeQuery('SELECT 1');
        $this->assertSame([7, 3, 10], array_values((array)$google->getLastUsage()?->toArray()));

        $ollama = new OllamaProvider($this->config('ollama'), $this->transport([
            'response' => 'hi',
            'prompt_eval_count' => 30,
            'eval_count' => 9,
        ]));
        $ollama->analyzeQuery('SELECT 1');
        $this->assertSame([30, 9, 39], array_values((array)$ollama->getLastUsage()?->toArray()));

        $yandex = new YandexProvider($this->config('yandex', ['folder_id' => 'f']), $this->transport([
            'output' => [['content' => [['text' => 'hi']]]],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 6, 'total_tokens' => 18],
        ]));
        $yandex->analyzeQuery('SELECT 1');
        $this->assertSame([12, 6, 18], array_values((array)$yandex->getLastUsage()?->toArray()));
    }

    public function testAnAnswerWithoutUsageLeavesItUnknownNotZero(): void
    {
        $provider = new OpenAiCompatibleProvider($this->config('openai-compatible'), 'openai-compatible', $this->transport([
            'choices' => [['message' => ['content' => 'hi']]],
        ]));
        $provider->analyzeQuery('SELECT 1');

        $this->assertNull($provider->getLastUsage());
    }

    public function testTheServiceKeepsTheLastAndTheRunningTotal(): void
    {
        $answers = [
            ['choices' => [['message' => ['content' => 'one']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]],
            ['choices' => [['message' => ['content' => 'two']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]],
        ];
        $transport = new class ($answers) implements AiTransport {
            /** @param list<array<string, mixed>> $answers */
            public function __construct(private array $answers)
            {
            }

            public function post(string $url, array $data, array $headers, int $timeout): array
            {
                return array_shift($this->answers) ?? [];
            }
        };
        $service = new AiAnalysisService(self::$db, $this->config('openai-compatible'), null, $transport);

        $this->assertNull($service->getLastUsage());
        $service->analyzeQueryContext(['sql' => 'SELECT 1'], 'openai-compatible');
        $this->assertSame(12, $service->getLastUsage()?->totalTokens);
        $service->analyzeQueryContext(['sql' => 'SELECT 2'], 'openai-compatible');
        $this->assertSame(6, $service->getLastUsage()?->totalTokens);
        $this->assertSame(['input_tokens' => 15, 'output_tokens' => 3, 'total_tokens' => 18], $service->getTotalUsage()?->toArray());
    }

    public function testSumsSkipFiguresThatAreMissing(): void
    {
        $sum = (new AiUsage(10, null, null))->plus(new AiUsage(5, 2, 7));

        $this->assertSame(['input_tokens' => 15, 'output_tokens' => 2, 'total_tokens' => 7], $sum->toArray());
        $this->assertNull(AiUsage::of(null, 'x'));
    }

    public function testRetriesAreConfigurableAndValidated(): void
    {
        $this->assertSame(0, $this->config('openai-compatible', ['retries' => 0])->getProviderSetting('openai-compatible', 'retries'));
        $this->assertSame(5, $this->config('openai-compatible', ['retries' => '5'])->getProviderSetting('openai-compatible', 'retries'));

        foreach ([-1, 11, 'many'] as $bad) {
            try {
                $this->config('openai-compatible', ['retries' => $bad]);
                $this->fail("retries={$bad} must be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('retries', $e->getMessage());
            }
        }
    }
}
