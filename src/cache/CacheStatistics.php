<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache;

use Psr\SimpleCache\CacheInterface;
use tommyknocker\pdodb\cache\backend\CacheBackend;

/**
 * Hit, miss, set and delete counters of a cache, kept across requests.
 *
 * With a {@see CacheBackend} every event is an atomic increment on the server, so concurrent processes never lose
 * counts. Without one the counts are gathered in memory and written to the PSR-16 cache every few operations;
 * that is best effort, because two processes writing at once can overwrite each other.
 */
final class CacheStatistics
{
    /** Cache key of the statistics */
    private const KEY = '__pdodb_stats__';

    /** How long counters live (30 days) */
    private const TTL = 30 * 24 * 3600;

    /** Operations between writes of the in-memory counters to the cache */
    private const FLUSH_INTERVAL = 10;

    private const TYPES = ['hits', 'misses', 'sets', 'deletes'];

    /** @var array<string, int> counts not yet written (only used without a backend) */
    private array $pending = ['hits' => 0, 'misses' => 0, 'sets' => 0, 'deletes' => 0];

    private int $operations = 0;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix,
        private readonly ?CacheBackend $backend = null
    ) {
    }

    /**
     * Count one event: 'hits', 'misses', 'sets' or 'deletes'.
     */
    public function record(string $type): void
    {
        if ($this->backend !== null) {
            $this->backend->increment($this->counterKey($type), 1, self::TTL);

            return;
        }

        $this->pending[$type]++;
    }

    /**
     * Mark the end of a cache operation; writes the pending counters every few operations.
     */
    public function operationDone(): void
    {
        if (++$this->operations >= self::FLUSH_INTERVAL) {
            $this->operations = 0;
            $this->flush();
        }
    }

    /**
     * Totals over all requests, including counts not yet written.
     *
     * @return array{hits: int, misses: int, sets: int, deletes: int}
     */
    public function totals(): array
    {
        if ($this->backend !== null) {
            return [
                'hits' => $this->backend->counter($this->counterKey('hits')),
                'misses' => $this->backend->counter($this->counterKey('misses')),
                'sets' => $this->backend->counter($this->counterKey('sets')),
                'deletes' => $this->backend->counter($this->counterKey('deletes')),
            ];
        }

        $stored = $this->stored();

        return [
            'hits' => $stored['hits'] + $this->pending['hits'],
            'misses' => $stored['misses'] + $this->pending['misses'],
            'sets' => $stored['sets'] + $this->pending['sets'],
            'deletes' => $stored['deletes'] + $this->pending['deletes'],
        ];
    }

    /**
     * Write the pending counters to the cache.
     */
    public function flush(): void
    {
        if ($this->backend !== null || array_sum($this->pending) === 0) {
            return;
        }

        $stored = $this->stored();
        foreach (self::TYPES as $type) {
            $stored[$type] += $this->pending[$type];
        }

        // A failed write keeps the counts for the next flush
        if ($this->cache->set($this->prefix . self::KEY, $stored, self::TTL)) {
            $this->pending = ['hits' => 0, 'misses' => 0, 'sets' => 0, 'deletes' => 0];
        }
    }

    /**
     * Forget all counts, stored and pending.
     */
    public function reset(): void
    {
        $this->pending = ['hits' => 0, 'misses' => 0, 'sets' => 0, 'deletes' => 0];
        $this->operations = 0;

        if ($this->backend !== null) {
            foreach (self::TYPES as $type) {
                $this->backend->delete($this->counterKey($type));
            }

            return;
        }

        $this->cache->delete($this->prefix . self::KEY);
    }

    private function counterKey(string $type): string
    {
        return $this->prefix . self::KEY . ':' . $type;
    }

    /**
     * @return array{hits: int, misses: int, sets: int, deletes: int}
     */
    private function stored(): array
    {
        $stats = $this->cache->get($this->prefix . self::KEY);
        $stats = is_array($stats) ? $stats : [];

        return [
            'hits' => (int)($stats['hits'] ?? 0),
            'misses' => (int)($stats['misses'] ?? 0),
            'sets' => (int)($stats['sets'] ?? 0),
            'deletes' => (int)($stats['deletes'] ?? 0),
        ];
    }
}
