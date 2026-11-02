<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\AiTransport;
use tommyknocker\pdodb\ai\providers\OllamaProvider;
use tommyknocker\pdodb\ai\providers\OpenAiCompatibleProvider;
use tommyknocker\pdodb\ai\RetryingAiTransport;
use tommyknocker\pdodb\ai\StreamAiTransport;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * How a request reaches the AI provider is replaceable: providers only build the request and read the answer.
 */
final class AiTransportTests extends BaseSharedTestCase
{
    private function recordingTransport(array $answer): object
    {
        return new class ($answer) implements AiTransport {
            /** @var list<array{url: string, data: array<string, mixed>, headers: array<string, string>, timeout: int}> */
            public array $requests = [];

            /** @param array<string, mixed> $answer */
            public function __construct(private array $answer)
            {
            }

            public function post(string $url, array $data, array $headers, int $timeout): array
            {
                $this->requests[] = ['url' => $url, 'data' => $data, 'headers' => $headers, 'timeout' => $timeout];

                return $this->answer;
            }
        };
    }

    private function openAiConfig(): AiConfig
    {
        return new AiConfig(['ai' => [
            'provider' => 'openai-compatible',
            'api_key' => 'test-key',
            'base_url' => 'https://llm.example.test/v1',
            'model' => 'test/model',
            'timeout' => 7,
        ]]);
    }

    public function testAProviderSendsItsRequestThroughTheTransportItIsGiven(): void
    {
        $transport = $this->recordingTransport(['choices' => [['message' => ['content' => 'Add an index on email']]]]);
        $provider = new OpenAiCompatibleProvider($this->openAiConfig(), 'openai-compatible', $transport);

        $answer = $provider->analyzeQuery('SELECT * FROM users WHERE email = 1');

        $this->assertSame('Add an index on email', $answer);
        $this->assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        $this->assertSame('https://llm.example.test/v1/chat/completions', $request['url']);
        $this->assertSame('Bearer test-key', $request['headers']['Authorization']);
        $this->assertSame('test/model', $request['data']['model']);
        $this->assertSame(7, $request['timeout']);
    }

    public function testTheServicePassesItsTransportToTheProvidersItCreates(): void
    {
        $transport = $this->recordingTransport(['choices' => [['message' => ['content' => 'ok']]]]);
        $service = new AiAnalysisService(self::$db, $this->openAiConfig(), null, $transport);

        $service->getProvider('openai-compatible')->analyzeQuery('SELECT 1');

        $this->assertCount(1, $transport->requests);
    }

    public function testATransportFailureReachesTheCaller(): void
    {
        $failing = new class () implements AiTransport {
            public function post(string $url, array $data, array $headers, int $timeout): array
            {
                throw new QueryException('AI API request failed with HTTP 503', 503);
            }
        };
        $provider = new OllamaProvider(new AiConfig(['ai' => ['provider' => 'ollama', 'base_url' => 'http://ollama.example.test']]), $failing);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('HTTP 503');
        $provider->analyzeQuery('SELECT 1');
    }

    public function testWithoutATransportRequestsGoThroughPhpStreamsWithRetries(): void
    {
        $provider = new OpenAiCompatibleProvider($this->openAiConfig());
        $transport = (new \ReflectionProperty($provider, 'transport'))->getValue($provider);

        $this->assertInstanceOf(RetryingAiTransport::class, $transport);
        $this->assertInstanceOf(StreamAiTransport::class, (new \ReflectionProperty($transport, 'inner'))->getValue($transport));
    }

    public function testATransportYouPassIsUsedAsGiven(): void
    {
        $given = $this->recordingTransport([]);
        $provider = new OpenAiCompatibleProvider($this->openAiConfig(), 'openai-compatible', $given);

        $this->assertSame($given, (new \ReflectionProperty($provider, 'transport'))->getValue($provider));
    }
}
