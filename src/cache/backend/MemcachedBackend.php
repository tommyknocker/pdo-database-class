<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache\backend;

use Memcached;

/**
 * Memcached. It has atomic counters but cannot list keys.
 */
final class MemcachedBackend implements CacheBackend
{
    public function __construct(private readonly Memcached $client)
    {
    }

    public function type(): string
    {
        return 'Memcached';
    }

    public function increment(string $key, int $by, int $ttl): void
    {
        $this->client->increment($key, $by, 0, $ttl);
    }

    public function counter(string $key): int
    {
        $value = $this->client->get($key);

        return $value === false ? 0 : (int)$value;
    }

    public function delete(string $key): void
    {
        $this->client->delete($key);
    }

    public function supportsKeyPatterns(): bool
    {
        return false;
    }

    public function keys(string $pattern): array
    {
        return [];
    }
}
