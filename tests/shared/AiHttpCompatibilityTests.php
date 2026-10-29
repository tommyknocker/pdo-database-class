<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\ai\providers\OpenAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

final class AiHttpCompatibilityTests extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHeadersDoNotLeakBetweenRequests(): void
    {
        require __DIR__ . '/../fixtures/ai-http-functions.php';
        $provider = new class () extends OpenAiProvider {
            public function request(string $url): array
            {
                return $this->makeRequest($url, []);
            }
        };

        try {
            $provider->request('https://example.test/error');
            self::fail('Expected an HTTP error');
        } catch (QueryException $exception) {
            self::assertStringContainsString('HTTP 429', $exception->getMessage());
        }

        self::assertSame(['ok' => true], $provider->request('https://example.test/success'));
        // A response without headers must not reuse the previous HTTP status.
        $GLOBALS['ai_test_headers'] = ['HTTP/1.1 500 Old Response'];
        self::assertSame(['ok' => true], $provider->request('custom://no-headers'));
    }
}
