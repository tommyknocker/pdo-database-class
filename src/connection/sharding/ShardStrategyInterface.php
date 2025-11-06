<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\sharding;

/**
 * Interface for shard resolution strategies.
 *
 * Defines how to determine which shard should handle a given shard key value.
 */
interface ShardStrategyInterface
{
    /**
     * Resolve shard name for a single shard key value.
     *
     * @param mixed $shardKeyValue Value of the shard key
     *
     * @return string Shard name
     * @throws \RuntimeException If shard cannot be resolved
     */
    public function resolveShard(mixed $shardKeyValue): string;

    /**
     * Resolve multiple shards for range queries (BETWEEN, IN).
     *
     * @param mixed $value Range value or array of values
     *
     * @return array<string> Array of shard names
     */
    public function resolveShards(mixed $value): array;
}
