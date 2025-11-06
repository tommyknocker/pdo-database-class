<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\sharding;

use InvalidArgumentException;

/**
 * Shard configuration for a table.
 *
 * Stores configuration for sharding a specific table including
 * shard key, strategy, and node connections.
 */
class ShardConfig
{
    protected string $table;
    protected string $shardKey = '';
    protected string $strategy = '';
    /** @var array<string, array<int, int>> */
    protected array $ranges = [];
    protected int $shardCount = 0;

    /**
     * Create shard configuration for a table.
     *
     * @param string $table Table name
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * Set shard key column name.
     *
     * @param string $key Column name used for sharding
     *
     * @return static
     */
    public function setShardKey(string $key): static
    {
        $this->shardKey = $key;
        return $this;
    }

    /**
     * Get shard key column name.
     *
     * @return string
     */
    public function getShardKey(): string
    {
        return $this->shardKey;
    }

    /**
     * Set sharding strategy.
     *
     * @param string $strategy Strategy name: 'range', 'hash', or 'modulo'
     *
     * @return static
     * @throws InvalidArgumentException If strategy is invalid
     */
    public function setStrategy(string $strategy): static
    {
        if (!in_array($strategy, ['range', 'hash', 'modulo'], true)) {
            throw new InvalidArgumentException("Invalid sharding strategy: {$strategy}. Must be 'range', 'hash', or 'modulo'.");
        }
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * Get sharding strategy.
     *
     * @return string
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Set shard count.
     *
     * @param int $count Number of shards
     *
     * @return static
     */
    public function setShardCount(int $count): static
    {
        $this->shardCount = $count;
        return $this;
    }

    /**
     * Set range definitions for range strategy.
     *
     * @param array<string, array<int, int>> $ranges Array of shard name => [min, max]
     *
     * @return static
     */
    public function setRanges(array $ranges): static
    {
        $this->ranges = $ranges;
        return $this;
    }

    /**
     * Get range definitions.
     *
     * @return array<string, array<int, int>>
     */
    public function getRanges(): array
    {
        return $this->ranges;
    }

    /**
     * Get number of shards.
     *
     * @return int
     */
    public function getShardCount(): int
    {
        return $this->shardCount;
    }

    /**
     * Get table name.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Validate configuration.
     *
     * @throws InvalidArgumentException If configuration is invalid
     */
    public function validate(): void
    {
        if (empty($this->shardKey)) {
            throw new InvalidArgumentException('Shard key must be set');
        }

        if (empty($this->strategy)) {
            throw new InvalidArgumentException('Sharding strategy must be set');
        }

        if ($this->shardCount < 1) {
            throw new InvalidArgumentException('Shard count must be at least 1. Use useConnections() to configure shard connections.');
        }

        if ($this->strategy === 'range' && empty($this->ranges)) {
            throw new InvalidArgumentException('Range strategy requires ranges to be defined');
        }

        if ($this->strategy === 'range') {
            // Validate that all ranges are defined
            foreach ($this->ranges as $shardName => $range) {
                if (!is_array($range) || count($range) !== 2) {
                    throw new InvalidArgumentException("Invalid range for shard: {$shardName}. Must be [min, max]");
                }
            }
        }
    }
}
