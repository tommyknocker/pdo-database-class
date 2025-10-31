<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDOStatement;

/**
 * LRU-based prepared statement pool.
 * Caches prepared PDOStatement instances by SQL key with configurable capacity.
 *
 * Provides significant performance boost (20-50%) for repeated queries
 * by avoiding redundant PDO::prepare() calls.
 */
class PreparedStatementPool
{
    /** @var int Maximum number of cached statements */
    protected int $capacity;

    /** @var array<string, PDOStatement> Cached statements indexed by SQL key */
    protected array $items = [];

    /** @var array<string> MRU order (most recently used at front) */
    protected array $order = [];

    /** @var int Number of cache hits */
    protected int $hits = 0;

    /** @var int Number of cache misses */
    protected int $misses = 0;

    /** @var bool Whether the pool is enabled */
    protected bool $enabled = true;

    /**
     * Constructor.
     *
     * @param int $capacity Maximum number of statements to cache (default: 256)
     * @param bool $enabled Whether the pool is enabled (default: true)
     */
    public function __construct(int $capacity = 256, bool $enabled = true)
    {
        if ($capacity < 1) {
            $capacity = 1;
        }
        $this->capacity = $capacity;
        $this->enabled = $enabled;
    }

    /**
     * Check if the pool is enabled.
     *
     * @return bool True if enabled, false otherwise
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable the pool.
     *
     * @param bool $enabled Whether to enable the pool
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Get the maximum capacity.
     *
     * @return int The maximum number of cached statements
     */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Set the maximum capacity.
     *
     * @param int $capacity The new capacity (minimum: 1)
     */
    public function setCapacity(int $capacity): void
    {
        $this->capacity = max(1, $capacity);
        $this->trimIfNeeded();
    }

    /**
     * Get the number of cache hits.
     *
     * @return int Number of hits
     */
    public function getHits(): int
    {
        return $this->hits;
    }

    /**
     * Get the number of cache misses.
     *
     * @return int Number of misses
     */
    public function getMisses(): int
    {
        return $this->misses;
    }

    /**
     * Get hit rate (hits / total requests).
     *
     * @return float Hit rate between 0 and 1
     */
    public function getHitRate(): float
    {
        $total = $this->hits + $this->misses;
        if ($total === 0) {
            return 0.0;
        }
        return $this->hits / $total;
    }

    /**
     * Reset statistics.
     */
    public function clearStats(): void
    {
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Clear all cached statements.
     */
    public function clear(): void
    {
        $this->items = [];
        $this->order = [];
        $this->clearStats();
    }

    /**
     * Invalidate a specific cached statement.
     *
     * @param string $sqlKey The SQL key to invalidate
     */
    public function invalidate(string $sqlKey): void
    {
        if (!isset($this->items[$sqlKey])) {
            return;
        }
        unset($this->items[$sqlKey]);
        $this->order = array_values(array_filter($this->order, static fn ($k) => $k !== $sqlKey));
    }

    /**
     * Get a cached statement by SQL key.
     *
     * @param string $sqlKey The SQL key
     *
     * @return PDOStatement|null The cached statement or null if not found
     */
    public function get(string $sqlKey): ?PDOStatement
    {
        if (!$this->enabled) {
            $this->misses++;
            return null;
        }
        if (!isset($this->items[$sqlKey])) {
            $this->misses++;
            return null;
        }
        // Move to MRU front
        $this->order = array_values(array_filter($this->order, static fn ($k) => $k !== $sqlKey));
        array_unshift($this->order, $sqlKey);
        $this->hits++;
        return $this->items[$sqlKey];
    }

    /**
     * Store a statement in the pool.
     *
     * @param string $sqlKey The SQL key
     * @param PDOStatement $stmt The statement to cache
     */
    public function put(string $sqlKey, PDOStatement $stmt): void
    {
        if (!$this->enabled) {
            return;
        }
        if (isset($this->items[$sqlKey])) {
            // Update order to MRU
            $this->order = array_values(array_filter($this->order, static fn ($k) => $k !== $sqlKey));
        }
        $this->items[$sqlKey] = $stmt;
        array_unshift($this->order, $sqlKey);
        $this->trimIfNeeded();
    }

    /**
     * Get the current number of cached statements.
     *
     * @return int Number of cached statements
     */
    public function size(): int
    {
        return count($this->items);
    }

    /**
     * Trim the cache if it exceeds capacity (remove LRU items).
     */
    protected function trimIfNeeded(): void
    {
        while (count($this->order) > $this->capacity) {
            $lruKey = array_pop($this->order);
            if ($lruKey !== null) {
                unset($this->items[$lruKey]);
            }
        }
    }
}
