<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use tommyknocker\pdodb\events\CacheClearedEvent;
use tommyknocker\pdodb\events\CacheDeleteEvent;
use tommyknocker\pdodb\events\CacheHitEvent;
use tommyknocker\pdodb\events\CacheMissEvent;
use tommyknocker\pdodb\events\CacheSetEvent;

/**
 * Manages query result caching.
 */
class CacheManager
{
    /** Cache key for statistics */
    private const STATS_KEY = '__pdodb_stats__';

    /** Cache key prefix for metadata */
    private const META_KEY_PREFIX = '__pdodb_meta:';

    /** TTL for statistics (30 days) */
    private const STATS_TTL = 30 * 24 * 3600;

    /** Interval for persisting statistics (number of operations) */
    private const PERSIST_INTERVAL = 10;

    protected CacheConfig $config;

    /** @var int Number of cache hits (in-memory for current request) */
    protected int $hits = 0;

    /** @var int Number of cache misses (in-memory for current request) */
    protected int $misses = 0;

    /** @var int Number of cache sets (in-memory for current request) */
    protected int $sets = 0;

    /** @var int Number of cache deletes (in-memory for current request) */
    protected int $deletes = 0;

    /** @var int Operation counter for batch persistence */
    private int $operationCount = 0;

    /** @var object|null Cached low-level connection for atomic operations */
    private ?object $atomicConnection = null;

    /** @var bool|null Cached flag for atomic operations support */
    private ?bool $atomicSupport = null;

    /** @var EventDispatcherInterface|null Event dispatcher */
    protected ?EventDispatcherInterface $eventDispatcher = null;

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
            $this->hits++;
            $this->incrementStatAtomic('hits');

            // Try to get TTL from metadata
            $ttl = null;
            $metaKey = self::META_KEY_PREFIX . $key;
            $metadata = $this->cache->get($metaKey);
            if (is_array($metadata) && isset($metadata['ttl'])) {
                $ttl = (int)$metadata['ttl'];
            }

            // Dispatch cache hit event
            $this->dispatch(new CacheHitEvent($key, $result, $ttl));
        } else {
            $this->misses++;
            $this->incrementStatAtomic('misses');

            // Dispatch cache miss event
            $this->dispatch(new CacheMissEvent($key));
        }

        $this->operationCount++;
        if ($this->operationCount >= self::PERSIST_INTERVAL) {
            $this->persistStats();
            $this->operationCount = 0;
        }

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
            $this->sets++;
            $this->incrementStatAtomic('sets');

            // Save metadata about tables if provided
            if ($tables !== null && !empty($tables)) {
                $this->addKeyToMetadata($key, $tables);
            }

            // Dispatch cache set event
            $this->dispatch(new CacheSetEvent($key, $value, $ttl, $tables));
        }

        $this->operationCount++;
        if ($this->operationCount >= self::PERSIST_INTERVAL) {
            $this->persistStats();
            $this->operationCount = 0;
        }

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

        // Only track statistics if cache is enabled
        if ($this->config->isEnabled() && $result) {
            $this->deletes++;
            $this->incrementStatAtomic('deletes');
        }

        // Dispatch cache delete event
        $this->dispatch(new CacheDeleteEvent($key, $result));

        if ($this->config->isEnabled()) {
            $this->operationCount++;
            if ($this->operationCount >= self::PERSIST_INTERVAL) {
                $this->persistStats();
                $this->operationCount = 0;
            }
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

        // Dispatch cache cleared event
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
     * Combines in-memory counters with persistent counters from cache.
     *
     * @return array<string, mixed> Statistics array
     */
    public function getStats(): array
    {
        // Get persistent statistics from cache
        $persistentStats = $this->getPersistentStats();

        // Combine with in-memory counters
        $totalHits = $persistentStats['hits'] + $this->hits;
        $totalMisses = $persistentStats['misses'] + $this->misses;
        $totalSets = $persistentStats['sets'] + $this->sets;
        $totalDeletes = $persistentStats['deletes'] + $this->deletes;

        $total = $totalHits + $totalMisses;
        $hitRate = $total > 0 ? ($totalHits / $total) * 100 : 0.0;

        return [
            'enabled' => $this->config->isEnabled(),
            'type' => $this->detectCacheType(),
            'prefix' => $this->config->getPrefix(),
            'default_ttl' => $this->config->getDefaultTtl(),
            'hits' => $totalHits,
            'misses' => $totalMisses,
            'hit_rate' => round($hitRate, 2),
            'sets' => $totalSets,
            'deletes' => $totalDeletes,
            'total_requests' => $total,
        ];
    }

    /**
     * Reset statistics counters (both in-memory and persistent).
     */
    public function resetStats(): void
    {
        // Reset in-memory counters
        $this->hits = 0;
        $this->misses = 0;
        $this->sets = 0;
        $this->deletes = 0;
        $this->operationCount = 0;

        // Clear persistent statistics
        if ($this->supportsAtomicIncrement()) {
            // For Redis/Memcached - delete atomic keys
            $statsKey = $this->config->getPrefix() . self::STATS_KEY;
            $this->deleteAtomicValue($statsKey . ':hits');
            $this->deleteAtomicValue($statsKey . ':misses');
            $this->deleteAtomicValue($statsKey . ':sets');
            $this->deleteAtomicValue($statsKey . ':deletes');
        } else {
            // For other caches - delete the stats object
            $statsKey = $this->config->getPrefix() . self::STATS_KEY;
            $this->cache->delete($statsKey);
        }
    }

    /**
     * Increment statistic atomically if supported, otherwise use fallback.
     *
     * @param string $type Statistic type: 'hits', 'misses', 'sets', 'deletes'
     */
    protected function incrementStatAtomic(string $type): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // For atomic-capable caches, increment immediately
        if ($this->supportsAtomicIncrement()) {
            $statsKey = $this->config->getPrefix() . self::STATS_KEY;
            $this->incrementAtomic($statsKey . ':' . $type);
        }
        // For other caches, we'll batch persist periodically (in persistStats)
    }

    /**
     * Check if cache supports atomic increment operations.
     *
     * @return bool True if atomic operations are supported
     */
    protected function supportsAtomicIncrement(): bool
    {
        if ($this->atomicSupport !== null) {
            return $this->atomicSupport;
        }

        $this->atomicSupport = $this->getRedisConnection() !== null
            || $this->getMemcachedConnection() !== null
            || $this->getApcuConnection() !== null;

        return $this->atomicSupport;
    }

    /**
     * Get Redis connection from cache implementation (universal check).
     *
     * Checks for Redis connection in:
     * - Cached atomic connection
     * - Direct cache instance
     * - Properties via reflection (Symfony Cache and other PSR-16 implementations)
     *
     * @return object|null Redis connection (\Redis or \Predis\Client) or null
     */
    protected function getRedisConnection(): ?object
    {
        // 1. Check cached connection
        if ($this->atomicConnection !== null) {
            $isRedis = $this->atomicConnection instanceof \Redis;
            $isPredis = false;
            if (class_exists(\Predis\Client::class)) {
                /** @var class-string<\Predis\Client> $predisClass */
                $predisClass = \Predis\Client::class;
                $isPredis = $this->atomicConnection instanceof $predisClass;
            }
            $redis = $isRedis || $isPredis ? $this->atomicConnection : null;
            if ($redis !== null) {
                return $redis;
            }
        }

        // 2. Direct check: cache instance might be Redis/Predis directly
        if ($this->cache instanceof \Redis) {
            $this->atomicConnection = $this->cache;
            return $this->cache;
        }
        if (class_exists(\Predis\Client::class)) {
            /** @var class-string<\Predis\Client> $predisClass */
            $predisClass = \Predis\Client::class;
            if ($this->cache instanceof $predisClass) {
                $this->atomicConnection = $this->cache;
                return $this->cache;
            }
        }

        // 3. Universal reflection check (works for any PSR-16 implementation)
        try {
            $reflection = new ReflectionClass($this->cache);

            // Check all properties recursively
            $redis = $this->findRedisInProperties($reflection, $this->cache);
            if ($redis !== null) {
                $this->atomicConnection = $redis;
                return $redis;
            }

            // Check Symfony Cache specifically (for backward compatibility)
            if (class_exists(\Symfony\Component\Cache\Psr16Cache::class)
                && $this->cache instanceof \Symfony\Component\Cache\Psr16Cache) {
                $poolProperty = $reflection->getProperty('pool');
                $poolProperty->setAccessible(true);

                // Check if property is initialized (for typed properties)
                if ($poolProperty->isInitialized($this->cache)) {
                    $pool = $poolProperty->getValue($this->cache);

                    if (class_exists(\Symfony\Component\Cache\Adapter\RedisAdapter::class)
                        && $pool instanceof \Symfony\Component\Cache\Adapter\RedisAdapter) {
                        $poolReflection = new ReflectionClass($pool);
                        $redisProperty = $poolReflection->getProperty('redis');
                        $redisProperty->setAccessible(true);

                        // Check if redis property is initialized
                        if ($redisProperty->isInitialized($pool)) {
                            $redis = $redisProperty->getValue($pool);

                            if ($redis instanceof \Redis) {
                                $this->atomicConnection = $redis;
                                return $redis;
                            }
                            // Check for Predis\Client (optional dependency)
                            if (class_exists(\Predis\Client::class)) {
                                /** @var class-string<\Predis\Client> $predisClass */
                                $predisClass = \Predis\Client::class;
                                if ($redis instanceof $predisClass) {
                                    $this->atomicConnection = $redis;
                                    return $redis;
                                }
                            }
                        }
                    }
                }
            }
        } catch (ReflectionException) {
            // Ignore reflection errors
        }

        return null;
    }

    /**
     * Recursively find Redis connection in object properties.
     *
     * @param ReflectionClass $reflection Reflection of object to check
     * @param object $object Object instance
     * @param int $depth Current recursion depth
     *
     * @return object|null Redis connection or null
     *
     * @phpstan-ignore-next-line - ReflectionClass generic type cannot be specified statically
     */
    protected function findRedisInProperties(ReflectionClass $reflection, object $object, int $depth = 0): ?object
    {
        // Limit recursion depth to avoid infinite loops
        if ($depth > 3) {
            return null;
        }

        foreach ($reflection->getProperties() as $property) {
            try {
                $property->setAccessible(true);

                // Check if property is initialized (for typed properties)
                if (!$property->isInitialized($object)) {
                    continue;
                }

                $value = $property->getValue($object);

                if ($value instanceof \Redis) {
                    return $value;
                }

                if (class_exists(\Predis\Client::class)) {
                    /** @var class-string<\Predis\Client> $predisClass */
                    $predisClass = \Predis\Client::class;
                    if ($value instanceof $predisClass) {
                        return $value;
                    }
                }

                // Recursively check nested objects
                if (is_object($value) && $value !== $this->cache) {
                    $nestedReflection = new ReflectionClass($value);
                    $found = $this->findRedisInProperties($nestedReflection, $value, $depth + 1);
                    if ($found !== null) {
                        return $found;
                    }
                }
            } catch (\ReflectionException | \Error $e) {
                // Ignore individual property access errors (ReflectionException or uninitialized typed property Error)
                continue;
            }
        }

        return null;
    }

    /**
     * Get Memcached connection from cache implementation (universal check).
     *
     * Checks for Memcached connection in:
     * - Cached atomic connection
     * - Direct cache instance
     * - Properties via reflection (Symfony Cache and other PSR-16 implementations)
     *
     * @return \Memcached|null
     */
    protected function getMemcachedConnection(): ?\Memcached
    {
        // 1. Check cached connection
        if ($this->atomicConnection instanceof \Memcached) {
            return $this->atomicConnection;
        }

        // 2. Direct check: cache instance might be Memcached directly
        if ($this->cache instanceof \Memcached) {
            $this->atomicConnection = $this->cache;
            return $this->cache;
        }

        // 3. Universal reflection check (works for any PSR-16 implementation)
        try {
            $reflection = new ReflectionClass($this->cache);

            // Check all properties recursively
            $memcached = $this->findMemcachedInProperties($reflection, $this->cache);
            if ($memcached !== null) {
                $this->atomicConnection = $memcached;
                return $memcached;
            }

            // Check Symfony Cache specifically (for backward compatibility)
            if (class_exists(\Symfony\Component\Cache\Psr16Cache::class)
                && $this->cache instanceof \Symfony\Component\Cache\Psr16Cache) {
                $poolProperty = $reflection->getProperty('pool');
                $poolProperty->setAccessible(true);
                $pool = $poolProperty->getValue($this->cache);

                if (class_exists(\Symfony\Component\Cache\Adapter\MemcachedAdapter::class)
                    && $pool instanceof \Symfony\Component\Cache\Adapter\MemcachedAdapter) {
                    $poolReflection = new ReflectionClass($pool);
                    $memcachedProperty = $poolReflection->getProperty('client');
                    $memcachedProperty->setAccessible(true);
                    $memcached = $memcachedProperty->getValue($pool);

                    if ($memcached instanceof \Memcached) {
                        $this->atomicConnection = $memcached;
                        return $memcached;
                    }
                }
            }
        } catch (ReflectionException) {
            // Ignore reflection errors
        }

        return null;
    }

    /**
     * Recursively find Memcached connection in object properties.
     *
     * @param ReflectionClass $reflection Reflection of object to check
     * @param object $object Object instance
     * @param int $depth Current recursion depth
     *
     * @return \Memcached|null Memcached connection or null
     */
    protected function findMemcachedInProperties(ReflectionClass $reflection, object $object, int $depth = 0): ?\Memcached
    {
        // Limit recursion depth to avoid infinite loops
        if ($depth > 3) {
            return null;
        }

        foreach ($reflection->getProperties() as $property) {
            try {
                $property->setAccessible(true);
                $value = $property->getValue($object);

                if ($value instanceof \Memcached) {
                    return $value;
                }

                // Recursively check nested objects
                if (is_object($value) && $value !== $this->cache) {
                    $nestedReflection = new ReflectionClass($value);
                    $found = $this->findMemcachedInProperties($nestedReflection, $value, $depth + 1);
                    if ($found !== null) {
                        return $found;
                    }
                }
            } catch (ReflectionException) {
                // Ignore individual property access errors
                continue;
            }
        }

        return null;
    }

    /**
     * Check if APCu is available and being used.
     *
     * @return bool True if APCu is available
     */
    protected function getApcuConnection(): ?bool
    {
        if (!extension_loaded('apcu') || !function_exists('apcu_inc')) {
            return null;
        }

        if (!class_exists(\Symfony\Component\Cache\Psr16Cache::class)
            || !$this->cache instanceof \Symfony\Component\Cache\Psr16Cache) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($this->cache);
            $poolProperty = $reflection->getProperty('pool');
            $poolProperty->setAccessible(true);
            $pool = $poolProperty->getValue($this->cache);

            if (class_exists(\Symfony\Component\Cache\Adapter\ApcuAdapter::class)
                && $pool instanceof \Symfony\Component\Cache\Adapter\ApcuAdapter) {
                return true;
            }
        } catch (ReflectionException) {
            // Ignore reflection errors
        }

        return null;
    }

    /**
     * Atomically increment a counter value.
     *
     * @param string $key Cache key
     * @param int $by Increment by value (default: 1)
     */
    protected function incrementAtomic(string $key, int $by = 1): void
    {
        // Redis (ext-redis)
        $redis = $this->getRedisConnection();
        if ($redis instanceof \Redis) {
            if ($by === 1) {
                $redis->incr($key);
            } else {
                $redis->incrBy($key, $by);
            }
            $redis->expire($key, self::STATS_TTL);
            return;
        }

        // Predis
        if (class_exists(\Predis\Client::class)) {
            /** @var class-string<\Predis\Client> $predisClass */
            $predisClass = \Predis\Client::class;
            if ($redis instanceof $predisClass) {
                if ($by === 1) {
                    /* @var \Predis\Client $redis */
                    $redis->incr($key);
                } else {
                    /* @var \Predis\Client $redis */
                    $redis->incrby($key, $by);
                }
                /* @var \Predis\Client $redis */
                $redis->expire($key, self::STATS_TTL);
                return;
            }
        }

        // Memcached
        $memcached = $this->getMemcachedConnection();
        if ($memcached instanceof \Memcached) {
            $memcached->increment($key, $by, 0, self::STATS_TTL);
            return;
        }

        // APCu
        if ($this->getApcuConnection() === true) {
            for ($i = 0; $i < $by; $i++) {
                apcu_inc($key, 1, $success);
                if (!$success) {
                    apcu_store($key, 1, self::STATS_TTL);
                }
            }
        }
    }

    /**
     * Get atomically stored value.
     *
     * @param string $key Cache key
     *
     * @return int Value or 0 if not found
     */
    protected function getAtomicValue(string $key): int
    {
        // Redis (ext-redis)
        $redis = $this->getRedisConnection();
        if ($redis instanceof \Redis) {
            $value = $redis->get($key);
            return $value !== false ? (int)$value : 0;
        }

        // Predis
        if (class_exists(\Predis\Client::class)) {
            /** @var class-string<\Predis\Client> $predisClass */
            $predisClass = \Predis\Client::class;
            if ($redis instanceof $predisClass) {
                /** @var \Predis\Client $redis */
                $value = $redis->get($key);
                return $value !== null ? (int)$value : 0;
            }
        }

        // Memcached
        $memcached = $this->getMemcachedConnection();
        if ($memcached instanceof \Memcached) {
            $value = $memcached->get($key);
            return $value !== false ? (int)$value : 0;
        }

        // APCu
        if ($this->getApcuConnection() === true) {
            $value = apcu_fetch($key, $success);
            return $success ? (int)$value : 0;
        }

        return 0;
    }

    /**
     * Delete atomically stored value.
     *
     * @param string $key Cache key
     */
    protected function deleteAtomicValue(string $key): void
    {
        // Redis
        $redis = $this->getRedisConnection();
        if ($redis instanceof \Redis) {
            $redis->del($key);
            return;
        }
        // Predis
        if (class_exists(\Predis\Client::class)) {
            /** @var class-string<\Predis\Client> $predisClass */
            $predisClass = \Predis\Client::class;
            if ($redis instanceof $predisClass) {
                /* @var \Predis\Client $redis */
                $redis->del($key);
                return;
            }
        }

        // Memcached
        $memcached = $this->getMemcachedConnection();
        if ($memcached instanceof \Memcached) {
            $memcached->delete($key);
            return;
        }

        // APCu
        if ($this->getApcuConnection() === true) {
            apcu_delete($key);
            return;
        }

        // Fallback: use PSR-16 delete
        $this->cache->delete($key);
    }

    /**
     * Get persistent statistics from cache.
     *
     * @return array<string, int> Statistics array
     */
    protected function getPersistentStats(): array
    {
        if (!$this->config->isEnabled()) {
            return ['hits' => 0, 'misses' => 0, 'sets' => 0, 'deletes' => 0];
        }

        $statsKey = $this->config->getPrefix() . self::STATS_KEY;

        // For atomic-capable caches, read separate keys
        if ($this->supportsAtomicIncrement()) {
            return [
                'hits' => $this->getAtomicValue($statsKey . ':hits'),
                'misses' => $this->getAtomicValue($statsKey . ':misses'),
                'sets' => $this->getAtomicValue($statsKey . ':sets'),
                'deletes' => $this->getAtomicValue($statsKey . ':deletes'),
            ];
        }

        // For other caches, read single stats object
        $stats = $this->cache->get($statsKey, [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
        ]);

        // Remove version field if present (used for optimistic locking)
        unset($stats['_version']);

        return [
            'hits' => (int)($stats['hits'] ?? 0),
            'misses' => (int)($stats['misses'] ?? 0),
            'sets' => (int)($stats['sets'] ?? 0),
            'deletes' => (int)($stats['deletes'] ?? 0),
        ];
    }

    /**
     * Persist in-memory statistics to cache (batch operation for non-atomic caches).
     */
    protected function persistStats(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // If no changes in memory, skip
        if ($this->hits === 0 && $this->misses === 0 && $this->sets === 0 && $this->deletes === 0) {
            return;
        }

        // For atomic-capable caches, increments are already done atomically
        if ($this->supportsAtomicIncrement()) {
            // Just reset in-memory counters (they're already persisted atomically)
            $this->hits = 0;
            $this->misses = 0;
            $this->sets = 0;
            $this->deletes = 0;
            return;
        }

        // For other caches, use optimistic locking with retry
        $this->persistStatsWithRetry();

        // Reset in-memory counters after successful persistence
        $this->hits = 0;
        $this->misses = 0;
        $this->sets = 0;
        $this->deletes = 0;
    }

    /**
     * Persist statistics using optimistic locking (for non-atomic caches).
     *
     * @param int $maxRetries Maximum number of retry attempts
     */
    protected function persistStatsWithRetry(int $maxRetries = 3): void
    {
        $statsKey = $this->config->getPrefix() . self::STATS_KEY;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Read current statistics
            $stats = $this->cache->get($statsKey, [
                'hits' => 0,
                'misses' => 0,
                'sets' => 0,
                'deletes' => 0,
                '_version' => time(),
            ]);

            $oldVersion = $stats['_version'] ?? time();

            // Add in-memory counters
            $stats['hits'] = (int)($stats['hits'] ?? 0) + $this->hits;
            $stats['misses'] = (int)($stats['misses'] ?? 0) + $this->misses;
            $stats['sets'] = (int)($stats['sets'] ?? 0) + $this->sets;
            $stats['deletes'] = (int)($stats['deletes'] ?? 0) + $this->deletes;
            $stats['_version'] = time();

            // Try to save (with optimistic assumption that version didn't change significantly)
            $success = $this->cache->set($statsKey, $stats, self::STATS_TTL);

            if ($success) {
                return; // Successfully persisted
            }

            // Small delay before retry to reduce contention
            if ($attempt < $maxRetries - 1) {
                usleep(1000 * ($attempt + 1)); // 1ms, 2ms, 3ms...
            }
        }

        // If all retries failed, we'll try again on next batch
        // This is acceptable - statistics are best-effort for non-atomic caches
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
                $this->cache->set($metaKey, $keys, self::STATS_TTL);
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

        // Remove quotes
        $table = trim($table, '`"[]');

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
                $this->deletes++;
                $this->incrementStatAtomic('deletes');
            }
        }

        // Delete metadata
        $this->cache->delete($metaKey);

        return $deletedCount;
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
        if ($this->supportsKeyPatternMatching()) {
            $metaPattern = $prefix . self::META_KEY_PREFIX . 'table:' . $normalizedPrefix . '*';
            $metaKeys = $this->getKeysByPattern($metaPattern);

            foreach ($metaKeys as $metaKey) {
                $keys = $this->cache->get($metaKey, []);
                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        if (is_string($key) && $this->cache->delete($key)) {
                            $deletedCount++;
                            $this->deletes++;
                            $this->incrementStatAtomic('deletes');
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
        if ($this->supportsKeyPatternMatching()) {
            $keys = $this->getKeysByPattern($pattern);
            $deletedCount = 0;

            foreach ($keys as $key) {
                if (is_string($key) && $this->cache->delete($key)) {
                    $deletedCount++;
                    $this->deletes++;
                    $this->incrementStatAtomic('deletes');
                }
            }

            return $deletedCount;
        }

        // Fallback: cannot match patterns without low-level operations
        return 0;
    }

    /**
     * Check if cache supports key pattern matching.
     *
     * @return bool True if pattern matching is supported
     */
    protected function supportsKeyPatternMatching(): bool
    {
        return $this->getRedisConnection() !== null
            || $this->getMemcachedConnection() !== null;
    }

    /**
     * Get cache keys matching pattern (for Redis/Memcached).
     *
     * @param string $pattern Pattern with wildcards (*, ?)
     *
     * @return array<string> Array of matching keys
     */
    protected function getKeysByPattern(string $pattern): array
    {
        // Redis
        $redis = $this->getRedisConnection();
        if ($redis instanceof \Redis) {
            $keys = $redis->keys($pattern);
            return is_array($keys) ? $keys : [];
        }

        // Predis
        if (class_exists(\Predis\Client::class)) {
            /** @var class-string<\Predis\Client> $predisClass */
            $predisClass = \Predis\Client::class;
            if ($redis instanceof $predisClass) {
                /** @var \Predis\Client $redis */
                $keys = $redis->keys($pattern);
                return is_array($keys) ? $keys : [];
            }
        }

        // Memcached doesn't support patterns directly - return empty array
        return [];
    }

    /**
     * Detect cache type from cache instance (universal detection).
     *
     * Uses multiple strategies:
     * 1. Check via atomic connection methods (already discovered)
     * 2. Direct type checking
     * 3. Universal reflection (any PSR-16 implementation)
     * 4. Class name pattern matching
     *
     * @return string Cache type name (Redis, Memcached, APCu, Filesystem, Array, Unknown)
     */
    protected function detectCacheType(): string
    {
        // 1. Check via already discovered connections (fastest path)
        if ($this->getRedisConnection() !== null) {
            return 'Redis';
        }

        if ($this->getMemcachedConnection() !== null) {
            return 'Memcached';
        }

        if ($this->getApcuConnection() === true) {
            return 'APCu';
        }

        // 2. Direct type checking
        if ($this->cache instanceof \Redis) {
            return 'Redis';
        }
        if ($this->cache instanceof \Memcached) {
            return 'Memcached';
        }
        if (class_exists(\Predis\Client::class)) {
            /** @var class-string<\Predis\Client> $predisClass */
            $predisClass = \Predis\Client::class;
            if ($this->cache instanceof $predisClass) {
                return 'Redis';
            }
        }

        // 3. Universal reflection check (works for any PSR-16 implementation)
        try {
            $reflection = new ReflectionClass($this->cache);

            // Check properties for backend connections
            foreach ($reflection->getProperties() as $property) {
                try {
                    $property->setAccessible(true);
                    $value = $property->getValue($this->cache);

                    if ($value instanceof \Redis) {
                        return 'Redis';
                    }
                    if ($value instanceof \Memcached) {
                        return 'Memcached';
                    }
                    if (class_exists(\Predis\Client::class)) {
                        /** @var class-string<\Predis\Client> $predisClass */
                        $predisClass = \Predis\Client::class;
                        if ($value instanceof $predisClass) {
                            return 'Redis';
                        }
                    }
                } catch (ReflectionException) {
                    // Ignore individual property access errors
                    continue;
                }
            }

            // Check methods for type hints
            $methods = array_map(static fn ($m) => $m->getName(), $reflection->getMethods());
            $redisMethods = ['incr', 'decr', 'keys', 'del', 'get', 'set', 'hget', 'hset'];
            $memcachedMethods = ['increment', 'decrement', 'getallkeys', 'getbykey'];

            if (array_intersect($redisMethods, $methods)) {
                // Likely Redis-like cache
                return 'Redis';
            }
            if (array_intersect($memcachedMethods, $methods)) {
                // Likely Memcached-like cache
                return 'Memcached';
            }

            // Check Symfony Cache adapters specifically
            if (class_exists(\Symfony\Component\Cache\Psr16Cache::class)
                && $this->cache instanceof \Symfony\Component\Cache\Psr16Cache) {
                try {
                    $poolProperty = $reflection->getProperty('pool');
                    $poolProperty->setAccessible(true);
                    $pool = $poolProperty->getValue($this->cache);

                    $poolClass = get_class($pool);
                    $poolClassName = (string)$poolClass;
                    if (str_contains($poolClassName, 'FilesystemAdapter')) {
                        return 'Filesystem';
                    }
                    if (str_contains($poolClassName, 'ArrayAdapter')) {
                        return 'Array';
                    }
                    if (str_contains($poolClassName, 'ApcuAdapter')) {
                        return 'APCu';
                    }
                } catch (ReflectionException) {
                    // Ignore
                }
            }
        } catch (ReflectionException) {
            // Ignore reflection errors
        }

        // 4. Fallback: detect by class name pattern
        $className = get_class($this->cache);
        $lowerClassName = strtolower($className);

        if (str_contains($lowerClassName, 'redis')) {
            return 'Redis';
        }
        if (str_contains($lowerClassName, 'memcached') || str_contains($lowerClassName, 'memcache')) {
            return 'Memcached';
        }
        if (str_contains($lowerClassName, 'apcu') || str_contains($lowerClassName, 'apc')) {
            return 'APCu';
        }
        if (str_contains($lowerClassName, 'filesystem') || str_contains($lowerClassName, 'file')) {
            return 'Filesystem';
        }
        if (str_contains($lowerClassName, 'array')) {
            return 'Array';
        }

        return 'Unknown';
    }
}
