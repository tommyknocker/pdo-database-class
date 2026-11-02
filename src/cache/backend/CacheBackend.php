<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache\backend;

/**
 * Low-level operations of a cache server that the PSR-16 interface does not offer: atomic counters for statistics
 * and key lookup by pattern for invalidation.
 *
 * {@see CacheManager} depends on this port instead of on Redis, Memcached or APCu classes. Pass an implementation to
 * its constructor, or let {@see CacheBackendDetector} find one behind the PSR-16 cache.
 */
interface CacheBackend
{
    /**
     * Name of the server kind, as shown in cache statistics (Redis, Memcached, APCu).
     */
    public function type(): string;

    /**
     * Add to a counter, creating it when missing, and let it expire after $ttl seconds.
     */
    public function increment(string $key, int $by, int $ttl): void;

    /**
     * Current value of a counter; 0 when it does not exist.
     */
    public function counter(string $key): int;

    public function delete(string $key): void;

    /**
     * Whether keys can be listed by a glob pattern (`prefix*`).
     */
    public function supportsKeyPatterns(): bool;

    /**
     * Keys matching a glob pattern; empty when the server cannot list them.
     *
     * @return list<string>
     */
    public function keys(string $pattern): array;
}
