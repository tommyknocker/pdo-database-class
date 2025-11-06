<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\sharding;

use RuntimeException;
use tommyknocker\pdodb\PdoDb;

/**
 * Builder for shard configuration.
 *
 * Provides fluent API for configuring sharding.
 */
class ShardConfigBuilder
{
    protected ShardConfig $config;
    protected PdoDb $db;
    protected ShardRouter $shardRouter;

    /**
     * Create shard config builder.
     *
     * @param ShardConfig $config Shard configuration
     * @param PdoDb $db PdoDb instance
     * @param ShardRouter $shardRouter Shard router instance
     */
    public function __construct(ShardConfig $config, PdoDb $db, ShardRouter $shardRouter)
    {
        $this->config = $config;
        $this->db = $db;
        $this->shardRouter = $shardRouter;
    }

    /**
     * Set shard key column name.
     *
     * @param string $key Column name used for sharding
     *
     * @return static
     */
    public function shardKey(string $key): static
    {
        $this->config->setShardKey($key);
        return $this;
    }

    /**
     * Set sharding strategy.
     *
     * @param string $strategy Strategy name: 'range', 'hash', or 'modulo'
     *
     * @return static
     */
    public function strategy(string $strategy): static
    {
        $this->config->setStrategy($strategy);
        return $this;
    }

    /**
     * Use existing connections from connection pool.
     *
     * @param array<string> $connectionNames Array of connection names from pool
     *
     * @return static
     * @throws RuntimeException If connection not found
     */
    public function useConnections(array $connectionNames): static
    {
        foreach ($connectionNames as $shardName) {
            if (!$this->db->hasConnection($shardName)) {
                throw new RuntimeException("Connection '{$shardName}' not found in connection pool. Use addConnection() to add it first.");
            }

            $connection = $this->db->getConnection($shardName);
            $this->shardRouter->addShardConnection($this->config->getTable(), $shardName, $connection);
        }

        // Set nodes count for validation
        $this->config->setShardCount(count($connectionNames));

        return $this;
    }

    /**
     * Set range definitions for range strategy.
     *
     * @param array<string, array<int, int>> $ranges Array of shard name => [min, max]
     *
     * @return static
     */
    public function ranges(array $ranges): static
    {
        $this->config->setRanges($ranges);
        return $this;
    }

    /**
     * Register the shard configuration.
     *
     * @return static
     */
    public function register(): static
    {
        $this->shardRouter->registerShard($this->config);
        return $this;
    }

    /**
     * Get the shard configuration.
     *
     * @return ShardConfig
     */
    public function getConfig(): ShardConfig
    {
        return $this->config;
    }
}
