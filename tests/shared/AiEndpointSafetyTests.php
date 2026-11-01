<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\AiEndpoint;
use tommyknocker\pdodb\ai\providers\MicrosoftProvider;
use tommyknocker\pdodb\ai\providers\OllamaProvider;
use tommyknocker\pdodb\ai\providers\YandexProvider;
use tommyknocker\pdodb\ai\QueryAdvicePrompt;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PackageVersion;

final class AiEndpointSafetyTests extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function badUrls(): array
    {
        return [
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://internal:70/'],
            'no scheme' => ['internal.example/api'],
            'credentials' => ['https://user:pass@example.com/'],
            'query' => ['https://example.com/?token=1'],
        ];
    }

    #[DataProvider('badUrls')]
    public function testUnsafeEndpointsAreRejected(string $url): void
    {
        $this->expectException(QueryException::class);
        AiEndpoint::assertValid($url);
    }

    public function testPlainHttpAndHttpsEndpointsAreAccepted(): void
    {
        AiEndpoint::assertValid('https://example.openai.azure.com');
        AiEndpoint::assertValid('http://localhost:11434');
        $this->addToAssertionCount(2);
    }

    public function testMicrosoftProviderChecksItsEndpoint(): void
    {
        $config = new AiConfig();
        $config->setProviderSetting('microsoft', 'base_url', 'file:///etc/passwd');

        $this->expectException(QueryException::class);
        new MicrosoftProvider($config);
    }

    public function testOllamaProviderChecksItsUrl(): void
    {
        $config = new AiConfig();
        $config->setOllamaUrl('gopher://internal:70/');

        $this->expectException(QueryException::class);
        new OllamaProvider($config);
    }

    public function testYandexErrorDoesNotEchoTheResponseBody(): void
    {
        $config = new AiConfig();
        $config->setApiKey('yandex', 'k');
        $config->setProviderSetting('yandex', 'folder_id', 'f');
        $provider = new class ($config) extends YandexProvider {
            protected function makeRequest(string $url, array $data, array $headers = []): array
            {
                return ['output' => [], 'echo' => 'SECRET-FROM-PROVIDER'];
            }
        };

        try {
            $provider->analyzeQuery('select 1');
            $this->fail('A response without output must be an error.');
        } catch (QueryException $e) {
            $this->assertStringNotContainsString('SECRET-FROM-PROVIDER', $e->getMessage());
        }
    }

    public function testStructuredPromptHasARealNewline(): void
    {
        $prompt = QueryAdvicePrompt::user('select 1', ['sql' => 'select 1', 'output_format' => 'structured']);

        $this->assertStringContainsString("\nReturn only JSON:", $prompt);
        $this->assertStringNotContainsString('\nReturn only JSON:', $prompt);
    }

    public function testPackageVersionIsNeverEmpty(): void
    {
        $this->assertNotSame('', PackageVersion::detect());
    }
}
