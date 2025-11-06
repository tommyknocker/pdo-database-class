<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\sharding\strategies;

use RuntimeException;
use tommyknocker\pdodb\connection\sharding\ShardStrategyInterface;

/**
 * Range-based sharding strategy.
 *
 * Distributes data based on numeric ranges.
 * Each shard handles a specific range of values.
 */
class RangeShardStrategy implements ShardStrategyInterface
{
    /**
     * @var array<string, array<int, int>> Shard name => [min, max] range
     */
    protected array $ranges = [];

    /**
     * Create range shard strategy.
     *
     * @param array<string, array<int, int>> $ranges Shard name => [min, max] range
     */
    public function __construct(array $ranges)
    {
        $this->ranges = $ranges;
    }

    /**
     * Resolve shard name for a single value.
     *
     * @param mixed $shardKeyValue Value of the shard key
     *
     * @return string Shard name
     * @throws RuntimeException If shard cannot be resolved
     */
    public function resolveShard(mixed $shardKeyValue): string
    {
        if (!is_numeric($shardKeyValue)) {
            throw new RuntimeException('Range strategy requires numeric value, got: ' . gettype($shardKeyValue));
        }

        $value = (int)$shardKeyValue;

        foreach ($this->ranges as $shardName => $range) {
            if (count($range) !== 2) {
                continue;
            }

            [$min, $max] = $range;

            if ($value >= $min && $value <= $max) {
                return $shardName;
            }
        }

        throw new RuntimeException("No shard found for value: {$value}");
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
            // For BETWEEN: [min, max]
            if (count($value) === 2 && isset($value[0]) && isset($value[1])) {
                $min = is_numeric($value[0]) ? (int)$value[0] : null;
                $max = is_numeric($value[1]) ? (int)$value[1] : null;

                if ($min !== null && $max !== null) {
                    foreach ($this->ranges as $shardName => $range) {
                        if (count($range) !== 2) {
                            continue;
                        }

                        [$rangeMin, $rangeMax] = $range;

                        // Check if ranges overlap
                        if (!($max < $rangeMin || $min > $rangeMax)) {
                            $shards[] = $shardName;
                        }
                    }
                }
            } else {
                // For IN: array of values
                foreach ($value as $val) {
                    try {
                        $shard = $this->resolveShard($val);
                        if (!in_array($shard, $shards, true)) {
                            $shards[] = $shard;
                        }
                    } catch (RuntimeException) {
                        // Skip invalid values
                    }
                }
            }
        } else {
            // Single value
            try {
                $shards[] = $this->resolveShard($value);
            } catch (RuntimeException) {
                // Return empty if cannot resolve
            }
        }

        return array_unique($shards);
    }
}
