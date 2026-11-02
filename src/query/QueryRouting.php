<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use LogicException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\ConnectionRouter;
use tommyknocker\pdodb\connection\sharding\ShardRouter;

/**
 * Decides which connection a query runs on: a shard when the table is sharded, otherwise the read or write
 * connection of the read/write splitter. It only chooses; the query builder moves its components to the choice.
 */
final class QueryRouting
{
    private ?ConnectionRouter $connectionRouter = null;

    private ?ShardRouter $shardRouter = null;

    private bool $forceWrite = false;

    public function setConnectionRouter(?ConnectionRouter $router): void
    {
        $this->connectionRouter = $router;
    }

    public function setShardRouter(?ShardRouter $router): void
    {
        $this->shardRouter = $router;
    }

    /**
     * The next read goes to the write connection.
     */
    public function forceWrite(): void
    {
        $this->forceWrite = true;
    }

    /**
     * The connection for a read, or null to stay on the current one.
     */
    public function forRead(QueryBuilder $query): ?ConnectionInterface
    {
        $shard = $this->forShard($query, null);
        if ($shard !== null) {
            return $shard;
        }
        if ($this->connectionRouter === null) {
            return null;
        }

        if ($this->forceWrite) {
            $this->forceWrite = false;

            return $this->connectionRouter->getWriteConnection();
        }

        return $this->connectionRouter->getReadConnection();
    }

    /**
     * The connection for a write, or null to stay on the current one.
     *
     * @param array<int, array<string, mixed>>|null $rows Rows about to be written; their shard key picks the shard when WHERE has none
     */
    public function forWrite(QueryBuilder $query, ?array $rows = null): ?ConnectionInterface
    {
        return $this->forShard($query, $rows) ?? $this->connectionRouter?->getWriteConnection();
    }

    /**
     * The SQL built so far was generated with the builder's dialect, so a connection with a different dialect cannot
     * run it; that is a configuration error and must not fall back silently.
     *
     * @throws LogicException When the target connection uses another SQL dialect
     */
    public static function assertSameDialect(ConnectionInterface $from, ConnectionInterface $to): void
    {
        $fromDriver = $from->getDialect()->getDriverName();
        $toDriver = $to->getDialect()->getDriverName();
        if ($fromDriver !== $toDriver) {
            throw new LogicException(
                "Connection routing needs every connection to use the same SQL dialect: the query is built for {$fromDriver} but was routed to {$toDriver}."
            );
        }
    }

    /**
     * The shard connection that holds the query's rows, or null when the table is not sharded.
     *
     * @param array<int, array<string, mixed>>|null $rows
     *
     * @throws LogicException When the table is sharded and the query does not say which shard
     */
    public function forShard(QueryBuilder $query, ?array $rows): ?ConnectionInterface
    {
        if ($this->shardRouter === null) {
            return null;
        }

        $table = $query->getTableName();
        if ($table === null || $this->shardRouter->getShardConfig($table) === null) {
            return null;
        }

        $shardName = $this->shardRouter->resolveShard($query);
        if ($shardName === null && $rows !== null) {
            $shardName = $this->shardRouter->resolveShardForRows($table, $rows);
        }
        if ($shardName === null) {
            // There is no scatter-gather: running the query on the default connection would silently read or
            // write only part of the table, so the caller has to pick a shard.
            throw new LogicException(
                "Cannot tell which shard of '{$table}' to use: the query has no value for its shard key. "
                . 'Filter on the shard key, or choose a shard with connection($name) for a cross-shard task.'
            );
        }

        return $this->shardRouter->getShardConnection($table, $shardName);
    }
}
