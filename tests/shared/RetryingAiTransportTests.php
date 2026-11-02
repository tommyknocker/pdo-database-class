<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\ai\AiTransport;
use tommyknocker\pdodb\ai\RetryingAiTransport;
use tommyknocker\pdodb\exceptions\QueryException;

final class RetryingAiTransportTests extends TestCase
{
    /**
     * A transport that fails with the given exceptions in turn, then answers.
     *
     * @param list<QueryException> $failures
     */
    private function failing(array $failures): object
    {
        return new class ($failures) implements AiTransport {
            public int $calls = 0;

            /** @param list<QueryException> $failures */
            public function __construct(private array $failures)
            {
            }

            public function post(string $url, array $data, array $headers, int $timeout): array
            {
                $this->calls++;
                if ($this->failures !== []) {
                    throw array_shift($this->failures);
                }

                return ['ok' => true];
            }
        };
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function retryable(): array
    {
        return [
            'rate limit' => [429, 'AI API request failed with HTTP 429'],
            'server error' => [500, 'AI API request failed with HTTP 500'],
            'bad gateway' => [502, 'AI API request failed with HTTP 502'],
            'unavailable' => [503, 'AI API request failed with HTTP 503'],
            'gateway timeout' => [504, 'AI API request failed with HTTP 504'],
            'request timeout' => [408, 'AI API request failed with HTTP 408'],
            'no connection' => [0, 'AI API request failed: unable to connect to provider'],
        ];
    }

    #[DataProvider('retryable')]
    public function testTransientFailuresAreRepeatedUntilItWorks(int $code, string $message): void
    {
        $inner = $this->failing([new QueryException($message, $code), new QueryException($message, $code)]);
        $waits = [];
        $transport = new RetryingAiTransport($inner, 2, 100, 5000, function (int $microseconds) use (&$waits): void {
            $waits[] = $microseconds;
        });

        $this->assertSame(['ok' => true], $transport->post('https://x.test', [], [], 5));
        $this->assertSame(3, $inner->calls);
        $this->assertCount(2, $waits);
        // 100 ms then 200 ms, each with up to a quarter on top
        $this->assertGreaterThanOrEqual(100_000, $waits[0]);
        $this->assertLessThanOrEqual(125_000, $waits[0]);
        $this->assertGreaterThanOrEqual(200_000, $waits[1]);
        $this->assertLessThanOrEqual(250_000, $waits[1]);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function notWorthRepeating(): array
    {
        return [
            'rejected key' => [401, 'AI API request failed with HTTP 401'],
            'bad request' => [400, 'AI API request failed with HTTP 400'],
            'unknown model' => [404, 'AI API request failed with HTTP 404'],
            'malformed answer' => [0, 'Invalid JSON response from AI API'],
            'disabled streams' => [0, 'AI requests need allow_url_fopen=On in php.ini; it is disabled.'],
        ];
    }

    #[DataProvider('notWorthRepeating')]
    public function testOtherFailuresAreThrownAtOnce(int $code, string $message): void
    {
        $inner = $this->failing([new QueryException($message, $code)]);
        $waited = false;
        $transport = new RetryingAiTransport($inner, 3, 100, 1000, function () use (&$waited): void {
            $waited = true;
        });

        try {
            $transport->post('https://x.test', [], [], 5);
            $this->fail('expected the failure');
        } catch (QueryException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame(1, $inner->calls);
        $this->assertFalse($waited);
    }

    public function testTheLastFailureIsThrownWhenTheRetriesRunOut(): void
    {
        $inner = $this->failing(array_map(
            static fn (int $i): QueryException => new QueryException("HTTP 503 attempt {$i}", 503),
            [1, 2, 3, 4]
        ));
        $transport = new RetryingAiTransport($inner, 2, 1, 1, static function (): void {
        });

        try {
            $transport->post('https://x.test', [], [], 5);
            $this->fail('expected the failure');
        } catch (QueryException $e) {
            $this->assertSame('HTTP 503 attempt 3', $e->getMessage(), 'first try plus two retries');
        }
        $this->assertSame(3, $inner->calls);
    }

    public function testZeroRetriesSendsOnce(): void
    {
        $inner = $this->failing([new QueryException('HTTP 503', 503)]);

        $this->expectException(QueryException::class);

        try {
            (new RetryingAiTransport($inner, 0))->post('https://x.test', [], [], 5);
        } finally {
            $this->assertSame(1, $inner->calls);
        }
    }

    public function testTheWaitNeverExceedsTheCap(): void
    {
        $inner = $this->failing(array_fill(0, 6, new QueryException('HTTP 429', 429)));
        $waits = [];
        $transport = new RetryingAiTransport($inner, 6, 1000, 3000, function (int $microseconds) use (&$waits): void {
            $waits[] = $microseconds;
        });

        $transport->post('https://x.test', [], [], 5);

        $this->assertCount(6, $waits);
        $this->assertLessThanOrEqual(3_000_000, max($waits));
        $this->assertGreaterThanOrEqual(3_000_000, $waits[5], 'the later waits sit at the cap');
    }
}
