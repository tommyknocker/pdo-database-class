<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Repeats a request that failed for a reason that is likely to pass: a rate limit (429), a server error or timeout
 * (408, 5xx) or a connection that could not be made. Other failures, such as a rejected key (401) or a bad request
 * (400), are thrown at once, because repeating them cannot help and may cost money.
 *
 * Waits grow exponentially from `$baseDelayMilliseconds` with some random spread, up to `$maxDelayMilliseconds`.
 * The wait is not taken from a `Retry-After` header: the transport does not expose headers.
 */
final class RetryingAiTransport implements AiTransport
{
    /** HTTP statuses worth another attempt */
    private const RETRYABLE_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /** @var callable(int): void */
    private $sleep;

    /**
     * @param int $retries How many more attempts after the first one fails (0 sends once)
     * @param callable(int): void|null $sleep Waits for the given microseconds; for tests
     */
    public function __construct(
        private readonly AiTransport $inner,
        private readonly int $retries = 2,
        private readonly int $baseDelayMilliseconds = 500,
        private readonly int $maxDelayMilliseconds = 8000,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function post(string $url, array $data, array $headers, int $timeout): array
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->inner->post($url, $data, $headers, $timeout);
            } catch (QueryException $e) {
                if ($attempt >= $this->retries || !self::isRetryable($e)) {
                    throw $e;
                }
                $attempt++;
                ($this->sleep)($this->delayMicroseconds($attempt));
            }
        }
    }

    public static function isRetryable(QueryException $e): bool
    {
        $code = (int)$e->getCode();
        if ($code !== 0) {
            return in_array($code, self::RETRYABLE_STATUSES, true);
        }

        // no HTTP status: only a connection that could not be made is worth repeating, not a malformed answer
        return str_contains($e->getMessage(), 'unable to connect');
    }

    private function delayMicroseconds(int $attempt): int
    {
        $delay = min($this->maxDelayMilliseconds, $this->baseDelayMilliseconds * (2 ** ($attempt - 1)));
        // spread by up to a quarter so that clients that failed together do not retry together
        $delay += random_int(0, intdiv($delay, 4));

        return min($this->maxDelayMilliseconds, $delay) * 1000;
    }
}
