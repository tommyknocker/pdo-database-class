<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache\backend;

use Predis\Client;

/**
 * Redis through ext-redis or Predis; both expose the commands used here under the same names (command names are
 * case-insensitive in PHP, so `incrby` serves both).
 */
final class RedisBackend implements CacheBackend
{
    /**
     * @param object $client An ext-redis connection or a Predis client (they share the commands used here)
     */
    public function __construct(private readonly object $client)
    {
    }

    /**
     * @return \Redis typed as ext-redis for static analysis; a Predis client answers the same calls
     */
    private function redis(): object
    {
        /** @var \Redis $client */
        $client = $this->client;

        return $client;
    }

    public function type(): string
    {
        return 'Redis';
    }

    public function increment(string $key, int $by, int $ttl): void
    {
        $this->redis()->incrby($key, $by);
        $this->redis()->expire($key, $ttl);
    }

    public function counter(string $key): int
    {
        $value = $this->redis()->get($key);

        // ext-redis returns false for a missing key, Predis null
        return $value === false || $value === null ? 0 : (int)$value;
    }

    public function delete(string $key): void
    {
        $this->redis()->del($key);
    }

    public function supportsKeyPatterns(): bool
    {
        return true;
    }

    public function keys(string $pattern): array
    {
        $keys = $this->redis()->keys($pattern);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }
}
