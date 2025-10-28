<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Manages query result caching.
 */
class CacheManager
{
    protected CacheConfig $config;

    /**
     * Create a new cache manager.
     *
     * @param CacheInterface $cache PSR-16 cache implementation
     * @param array<string, mixed>|CacheConfig $config Cache configuration
     */
    public function __construct(
        protected CacheInterface $cache,
        array|CacheConfig $config = []
    ) {
        $this->config = $config instanceof CacheConfig
            ? $config
            : CacheConfig::fromArray($config);
    }

    /**
     * Get cached value.
     *
     * @param string $key Cache key
     *
     * @return mixed|null Cached value or null if not found
     * @throws InvalidArgumentException
     */
    public function get(string $key): mixed
    {
        if (!$this->config->isEnabled()) {
            return null;
        }

        return $this->cache->get($key);
    }

    /**
     * Store value in cache.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time-to-live in seconds (null = use default)
     *
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $ttl = $ttl ?? $this->config->getDefaultTtl();

        return $this->cache->set($key, $value, $ttl);
    }

    /**
     * Delete cached value.
     *
     * @param string $key Cache key
     *
     * @throws InvalidArgumentException
     */
    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    /**
     * Check if cache has a key.
     *
     * @param string $key Cache key
     *
     * @throws InvalidArgumentException
     */
    public function has(string $key): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        return $this->cache->has($key);
    }

    /**
     * Clear all cached values.
     */
    public function clear(): bool
    {
        return $this->cache->clear();
    }

    /**
     * Get cache configuration.
     */
    public function getConfig(): CacheConfig
    {
        return $this->config;
    }

    /**
     * Get the underlying cache instance.
     */
    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * Generate a cache key for a query.
     *
     * @param string $sql SQL query
     * @param array<string|int, mixed> $params Query parameters
     * @param string $driver Database driver
     */
    public function generateKey(string $sql, array $params, string $driver): string
    {
        return QueryCacheKey::generate(
            $sql,
            $params,
            $driver,
            $this->config->getPrefix()
        );
    }
}
