<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\sharding;

use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\sharding\strategies\HashShardStrategy;
use tommyknocker\pdodb\connection\sharding\strategies\ModuloShardStrategy;
use tommyknocker\pdodb\connection\sharding\strategies\RangeShardStrategy;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Shard Router.
 *
 * Routes queries to appropriate shards based on shard key values
 * and configured sharding strategy.
 */
class ShardRouter
{
    /**
     * @var array<string, ShardConfig> Shard configurations by table name
     */
    protected array $shardConfigs = [];

    /**
     * @var array<string, array<string, ConnectionInterface>> Connections by table and shard name
     */
    protected array $shardConnections = [];

    /**
     * Register shard configuration for a table.
     *
     * @param ShardConfig $config Shard configuration
     *
     * @throws \InvalidArgumentException If configuration is invalid
     */
    public function registerShard(ShardConfig $config): void
    {
        $config->validate();
        $this->shardConfigs[$config->getTable()] = $config;
    }

    /**
     * Add connection for a specific shard.
     *
     * @param string $table Table name
     * @param string $shardName Shard name
     * @param ConnectionInterface $connection Connection instance
     */
    public function addShardConnection(string $table, string $shardName, ConnectionInterface $connection): void
    {
        if (!isset($this->shardConnections[$table])) {
            $this->shardConnections[$table] = [];
        }
        $this->shardConnections[$table][$shardName] = $connection;
    }

    /**
     * Get connection for a specific shard.
     *
     * @param string $table Table name
     * @param string $shardName Shard name
     *
     * @return ConnectionInterface
     * @throws RuntimeException If shard connection not found
     */
    public function getShardConnection(string $table, string $shardName): ConnectionInterface
    {
        if (!isset($this->shardConnections[$table][$shardName])) {
            throw new RuntimeException("Shard connection not found for table '{$table}', shard '{$shardName}'");
        }

        return $this->shardConnections[$table][$shardName];
    }

    /**
     * Check if table has sharding configured.
     *
     * @param string $table Table name
     *
     * @return bool
     */
    public function hasSharding(string $table): bool
    {
        return isset($this->shardConfigs[$table]);
    }

    /**
     * Get shard configuration for a table.
     *
     * @param string $table Table name
     *
     * @return ShardConfig|null
     */
    public function getShardConfig(string $table): ?ShardConfig
    {
        return $this->shardConfigs[$table] ?? null;
    }

    /**
     * Resolve shard name for a query.
     *
     * @param QueryBuilder $query Query builder instance
     *
     * @return string|null Shard name or null if sharding not configured or shard key not found
     * @throws QueryException If shard key is required but not found in WHERE conditions
     */
    public function resolveShard(QueryBuilder $query): ?string
    {
        $table = $query->getTableName();
        if ($table === null) {
            return null;
        }

        $config = $this->getShardConfig($table);
        if ($config === null) {
            return null;
        }

        $shardKeyValue = $this->extractShardKeyValue($query, $config);
        if ($shardKeyValue === null) {
            // Shard key not found in WHERE conditions
            // This is acceptable for queries that need to scan all shards
            return null;
        }

        $strategy = $this->createStrategy($config);
        return $strategy->resolveShard($shardKeyValue);
    }

    /**
     * Extract shard key value from query WHERE conditions.
     *
     * @param QueryBuilder $query Query builder instance
     * @param ShardConfig $config Shard configuration
     *
     * @return mixed|null Shard key value or null if not found
     */
    protected function extractShardKeyValue(QueryBuilder $query, ShardConfig $config): mixed
    {
        $shardKey = $config->getShardKey();
        return $query->extractShardKeyValue($shardKey);
    }

    /**
     * Create shard strategy instance based on configuration.
     *
     * @param ShardConfig $config Shard configuration
     *
     * @return ShardStrategyInterface
     * @throws RuntimeException If strategy is invalid
     */
    protected function createStrategy(ShardConfig $config): ShardStrategyInterface
    {
        $strategy = $config->getStrategy();

        return match ($strategy) {
            'range' => new RangeShardStrategy($config->getRanges()),
            'hash' => new HashShardStrategy($config->getShardCount()),
            'modulo' => new ModuloShardStrategy($config->getShardCount()),
            default => throw new RuntimeException("Unknown sharding strategy: {$strategy}"),
        };
    }

    /**
     * Get all shard names for a table.
     *
     * @param string $table Table name
     *
     * @return array<string> Array of shard names
     */
    public function getShardNames(string $table): array
    {
        if (!isset($this->shardConnections[$table])) {
            return [];
        }

        return array_keys($this->shardConnections[$table]);
    }

    /**
     * Remove shard configuration for a table.
     *
     * @param string $table Table name
     */
    public function removeShard(string $table): void
    {
        unset($this->shardConfigs[$table]);
        unset($this->shardConnections[$table]);
    }
}
