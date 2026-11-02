<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use tommyknocker\pdodb\cache\backend\CacheBackend;
use tommyknocker\pdodb\cache\backend\CacheBackendDetector;
use tommyknocker\pdodb\events\CacheClearedEvent;
use tommyknocker\pdodb\events\CacheDeleteEvent;
use tommyknocker\pdodb\events\CacheHitEvent;
use tommyknocker\pdodb\events\CacheMissEvent;
use tommyknocker\pdodb\events\CacheSetEvent;

/**
 * Manages query result caching.
 *
 * Works with any PSR-16 cache. Statistics counters and invalidation by key pattern need server features that PSR-16
 * lacks; they come from a {@see CacheBackend}, which is passed in or found behind the cache by
 * {@see CacheBackendDetector}. Without one, statistics are kept best-effort in the cache itself and invalidation works
 * by table.
 */
class CacheManager
{
    /** Cache key prefix for metadata */
    private const META_KEY_PREFIX = '__pdodb_meta:';

    /** TTL for table metadata (30 days) */
    private const META_TTL = 30 * 24 * 3600;

    protected CacheConfig $config;

    private ?CacheBackend $backend;

    private bool $backendResolved;

    private ?CacheStatistics $statistics = null;

    /** @var EventDispatcherInterface|null Event dispatcher */
    protected ?EventDispatcherInterface $eventDispatcher = null;

    /**
     * Create a new cache manager.
     *
     * @param CacheInterface $cache PSR-16 cache implementation
     * @param array<string, mixed>|CacheConfig $config Cache configuration
     * @param CacheBackend|null $backend Server features behind the cache; null looks for them
     */
    public function __construct(
        protected CacheInterface $cache,
        array|CacheConfig $config = [],
        ?CacheBackend $backend = null
    ) {
        $this->config = $config instanceof CacheConfig
            ? $config
            : CacheConfig::fromArray($config);
        $this->backend = $backend;
        $this->backendResolved = $backend !== null;
    }

    /**
     * Server features behind the cache, or null when it has none (array, filesystem, unknown wrappers).
     */
    public function getBackend(): ?CacheBackend
    {
        if (!$this->backendResolved) {
            $this->backend = CacheBackendDetector::detect($this->cache);
            $this->backendResolved = true;
        }

        return $this->backend;
    }

    protected function statistics(): CacheStatistics
    {
        return $this->statistics ??= new CacheStatistics($this->cache, $this->config->getPrefix(), $this->getBackend());
    }

    /**
     * Set the event dispatcher.
     *
     * @param EventDispatcherInterface|null $dispatcher The dispatcher instance or null to disable
     */
    public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

    /**
     * Get the event dispatcher.
     *
     * @return EventDispatcherInterface|null The dispatcher instance or null if not set
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * Dispatch an event if dispatcher is available.
     *
     * @param object $event The event to dispatch
     */
    protected function dispatch(object $event): void
    {
        $this->eventDispatcher?->dispatch($event);
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

        $result = $this->cache->get($key);
        if ($result !== null) {
            $this->statistics()->record('hits');
            // The remaining TTL is not tracked: per-key metadata would cost an extra round-trip on every hit
            $this->dispatch(new CacheHitEvent($key, $result, null));
        } else {
            $this->statistics()->record('misses');
            $this->dispatch(new CacheMissEvent($key));
        }

        $this->statistics()->operationDone();

        return $result;
    }

    /**
     * Store value in cache.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time-to-live in seconds (null = use default)
     * @param array<string>|null $tables Optional array of table names for metadata
     *
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, ?int $ttl = null, ?array $tables = null): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $ttl = $ttl ?? $this->config->getDefaultTtl();

        $result = $this->cache->set($key, $value, $ttl);
        if ($result) {
            $this->statistics()->record('sets');

            if ($tables !== null && !empty($tables)) {
                $this->addKeyToMetadata($key, $tables);
            }

            $this->dispatch(new CacheSetEvent($key, $value, $ttl, $tables));
        }

        $this->statistics()->operationDone();

        return $result;
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
        $result = $this->cache->delete($key);

        if ($this->config->isEnabled() && $result) {
            $this->statistics()->record('deletes');
        }

        $this->dispatch(new CacheDeleteEvent($key, $result));

        if ($this->config->isEnabled()) {
            $this->statistics()->operationDone();
        }

        return $result;
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
        $result = $this->cache->clear();

        $this->dispatch(new CacheClearedEvent($result));

        return $result;
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

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed> Statistics array
     */
    public function getStats(): array
    {
        $totals = $this->config->isEnabled()
            ? $this->statistics()->totals()
            : ['hits' => 0, 'misses' => 0, 'sets' => 0, 'deletes' => 0];

        $total = $totals['hits'] + $totals['misses'];
        $hitRate = $total > 0 ? ($totals['hits'] / $total) * 100 : 0.0;

        return [
            'enabled' => $this->config->isEnabled(),
            'type' => CacheBackendDetector::describe($this->cache, $this->getBackend()),
            'prefix' => $this->config->getPrefix(),
            'default_ttl' => $this->config->getDefaultTtl(),
            'hits' => $totals['hits'],
            'misses' => $totals['misses'],
            'hit_rate' => round($hitRate, 2),
            'sets' => $totals['sets'],
            'deletes' => $totals['deletes'],
            'total_requests' => $total,
        ];
    }

    /**
     * Write counts that are still in memory to the cache (they are written every few operations anyway).
     */
    public function flushStats(): void
    {
        if ($this->config->isEnabled()) {
            $this->statistics()->flush();
        }
    }

    /**
     * Reset statistics counters (both in-memory and persistent).
     */
    public function resetStats(): void
    {
        $this->statistics()->reset();
    }

    /**
     * Invalidate cache entries matching pattern.
     *
     * Supported patterns:
     * - "table:users" - Invalidate all entries for table "users"
     * - "table:users_*" - Invalidate all entries for tables starting with "users_"
     * - "pdodb_table_users_*" - Invalidate by key pattern (Redis/Memcached only)
     * - "users" - Simple table name match
     *
     * @param string $pattern Pattern to match
     *
     * @return int Number of invalidated entries
     */
    public function invalidateByPattern(string $pattern): int
    {
        if (!$this->config->isEnabled()) {
            return 0;
        }

        $deletedCount = 0;

        // Parse pattern
        if (str_starts_with($pattern, 'table:')) {
            // Pattern like "table:users" or "table:users_*"
            $tablePattern = substr($pattern, 6); // Remove "table:"

            if (str_ends_with($tablePattern, '_*')) {
                // Table prefix
                $tablePrefix = substr($tablePattern, 0, -2);
                $deletedCount = $this->invalidateByTablePrefix($tablePrefix);
            } else {
                // Exact table match
                $deletedCount = $this->invalidateByTable($tablePattern);
            }
        } elseif (str_contains($pattern, '*')) {
            // Key pattern like "pdodb_table_users_*"
            $deletedCount = $this->invalidateByKeyPattern($pattern);
        } else {
            // Simple match (table name or part of key)
            $deletedCount = $this->invalidateByTable($pattern);
        }

        return $deletedCount;
    }

    /**
     * Add key to metadata for tables.
     *
     * @param string $key Cache key
     * @param array<string> $tables Table names
     */
    protected function addKeyToMetadata(string $key, array $tables): void
    {
        foreach ($tables as $table) {
            // Normalize table name
            $normalizedTable = $this->normalizeTableName($table);
            $metaKey = $this->config->getPrefix() . self::META_KEY_PREFIX . 'table:' . $normalizedTable;

            $keys = $this->cache->get($metaKey, []);
            if (!is_array($keys)) {
                $keys = [];
            }

            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
                $this->cache->set($metaKey, $keys, self::META_TTL);
            }
        }
    }

    /**
     * Normalize table name (remove alias, schema, quotes).
     *
     * @param string $table Table name
     *
     * @return string Normalized table name
     */
    protected function normalizeTableName(string $table): string
    {
        // Remove alias (table AS alias -> table)
        if (preg_match('/^(\S+)\s+(?:AS\s+)?\S+$/i', $table, $matches)) {
            $table = $matches[1];
        }

        // Remove quotes (also around a schema: `app`.`users`)
        $table = str_replace(['`', '"', '[', ']'], '', $table);

        // Remove schema prefix (schema.table -> table)
        if (preg_match('/\.([^.]+)$/', $table, $matches)) {
            $table = $matches[1];
        }

        return strtolower($table);
    }

    /**
     * Invalidate cache entries by table name.
     *
     * @param string $table Table name
     *
     * @return int Number of invalidated entries
     */
    protected function invalidateByTable(string $table): int
    {
        $normalizedTable = $this->normalizeTableName($table);
        $metaKey = $this->config->getPrefix() . self::META_KEY_PREFIX . 'table:' . $normalizedTable;

        $keys = $this->cache->get($metaKey, []);
        if (!is_array($keys) || empty($keys)) {
            return 0;
        }

        $deletedCount = 0;
        foreach ($keys as $key) {
            if (is_string($key) && $this->cache->delete($key)) {
                $deletedCount++;
                $this->statistics()->record('deletes');
            }
        }

        // Delete metadata
        $this->cache->delete($metaKey);

        return $deletedCount;
    }

    /**
     * Drop every cached result that read the given table.
     *
     * @param string $table Table name (alias, schema and quotes are ignored)
     *
     * @return int Number of invalidated entries
     */
    public function invalidateTable(string $table): int
    {
        if (!$this->config->isEnabled()) {
            return 0;
        }

        return $this->invalidateByTable($table);
    }

    /**
     * Invalidate cache entries by table prefix.
     *
     * @param string $tablePrefix Table prefix
     *
     * @return int Number of invalidated entries
     */
    protected function invalidateByTablePrefix(string $tablePrefix): int
    {
        $deletedCount = 0;
        $prefix = $this->config->getPrefix();
        $normalizedPrefix = strtolower($tablePrefix);

        // For universal caches, we need to find all metadata keys
        // Use low-level operations if available
        $backend = $this->getBackend();
        if ($backend !== null && $backend->supportsKeyPatterns()) {
            $metaPattern = $prefix . self::META_KEY_PREFIX . 'table:' . $normalizedPrefix . '*';
            $metaKeys = $backend->keys($metaPattern);

            foreach ($metaKeys as $metaKey) {
                $keys = $this->cache->get($metaKey, []);
                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        if (is_string($key) && $this->cache->delete($key)) {
                            $deletedCount++;
                            $this->statistics()->record('deletes');
                        }
                    }
                }
                $this->cache->delete($metaKey);
            }
        } else {
            // Fallback: try exact match (limited functionality)
            $deletedCount = $this->invalidateByTable($tablePrefix);
        }

        return $deletedCount;
    }

    /**
     * Invalidate cache entries by key pattern.
     *
     * @param string $pattern Key pattern with wildcards
     *
     * @return int Number of invalidated entries
     */
    protected function invalidateByKeyPattern(string $pattern): int
    {
        $backend = $this->getBackend();
        if ($backend !== null && $backend->supportsKeyPatterns()) {
            $keys = $backend->keys($pattern);
            $deletedCount = 0;

            foreach ($keys as $key) {
                if (is_string($key) && $this->cache->delete($key)) {
                    $deletedCount++;
                    $this->statistics()->record('deletes');
                }
            }

            return $deletedCount;
        }

        // Fallback: cannot match patterns without low-level operations
        return 0;
    }
}
