<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\sharding\strategies;

use InvalidArgumentException;
use tommyknocker\pdodb\connection\sharding\ShardStrategyInterface;

/**
 * Hash-based sharding strategy.
 *
 * Distributes data based on hash of the shard key value.
 * Uses CRC32 for consistent hashing.
 */
class HashShardStrategy implements ShardStrategyInterface
{
    protected int $shardCount;

    /**
     * Create hash shard strategy.
     *
     * @param int $shardCount Number of shards
     */
    public function __construct(int $shardCount)
    {
        if ($shardCount < 1) {
            throw new InvalidArgumentException("Shard count must be at least 1, got: {$shardCount}");
        }
        $this->shardCount = $shardCount;
    }

    /**
     * Resolve shard name for a single value.
     *
     * @param mixed $shardKeyValue Value of the shard key
     *
     * @return string Shard name
     */
    public function resolveShard(mixed $shardKeyValue): string
    {
        $hash = crc32((string)$shardKeyValue);
        $shardIndex = abs($hash) % $this->shardCount;
        $shardNumber = $shardIndex + 1;

        return "shard{$shardNumber}";
    }

    /**
     * Resolve multiple shards for range queries.
     *
     * @param mixed $value Range value or array of values
     *
     * @return array<string> Array of shard names
     */
    public function resolveShards(mixed $value): array
    {
        $shards = [];

        if (is_array($value)) {
            // For IN or BETWEEN
            foreach ($value as $val) {
                $shard = $this->resolveShard($val);
                if (!in_array($shard, $shards, true)) {
                    $shards[] = $shard;
                }
            }
        } else {
            $shards[] = $this->resolveShard($value);
        }

        return array_unique($shards);
    }
}
