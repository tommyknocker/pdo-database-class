<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\sharding\strategies;

use InvalidArgumentException;
use RuntimeException;
use tommyknocker\pdodb\connection\sharding\ShardStrategyInterface;

/**
 * Modulo-based sharding strategy.
 *
 * Distributes data based on modulo operation: value % shard_count.
 * Requires numeric shard key values.
 */
class ModuloShardStrategy implements ShardStrategyInterface
{
    protected int $shardCount;

    /**
     * Create modulo shard strategy.
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
     * @throws RuntimeException If value is not numeric
     */
    public function resolveShard(mixed $shardKeyValue): string
    {
        if (!is_numeric($shardKeyValue)) {
            throw new RuntimeException('Modulo strategy requires numeric value, got: ' . gettype($shardKeyValue));
        }

        $value = (int)$shardKeyValue;
        $shardIndex = $value % $this->shardCount;
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
                try {
                    $shard = $this->resolveShard($val);
                    if (!in_array($shard, $shards, true)) {
                        $shards[] = $shard;
                    }
                } catch (RuntimeException) {
                    // Skip non-numeric values
                }
            }
        } else {
            try {
                $shards[] = $this->resolveShard($value);
            } catch (RuntimeException) {
                // Return empty if cannot resolve
            }
        }

        return array_unique($shards);
    }
}
