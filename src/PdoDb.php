<?php

declare(strict_types=1);

namespace tommyknocker\pdodb;

use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\connection\ConnectionFactory;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\ConnectionRouter;
use tommyknocker\pdodb\connection\loadbalancer\LoadBalancerInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\cache\QueryCompilationCache;
use tommyknocker\pdodb\query\QueryBuilder;
use tommyknocker\pdodb\query\QueryProfiler;

class PdoDb
{
    public const string LOCK_WRITE = 'WRITE';
    public const string LOCK_READ = 'READ';

    /** @var ConnectionInterface Current active connection */
    public ConnectionInterface $connection {
        get {
            if ($this->connectionStorage === null) {
                throw new RuntimeException(
                    'Connection not initialized. Use addConnection() to add a connection, then connection() to select it.'
                );
            }
            return $this->connectionStorage;
        }
    }

    /** @var ConnectionInterface|null Internal connection storage */
    protected ?ConnectionInterface $connectionStorage = null;

    /** @var array<string, ConnectionInterface> Named connections pool */
    protected array $connections = [];

    /** @var string Table prefix for queries */
    public string $prefix;

    public ?string $lastQuery {
        get {
            return $this->connection->getLastQuery();
        }
    }

    public ?string $lastError {
        get {
            return $this->connection->getLastError();
        }
    }

    public int $lastErrNo {
        get {
            return $this->connection->getLastErrno();
        }
    }

    public ?bool $executeState {
        get {
            return $this->connection->getExecuteState();
        }
    }

    /** @var string Lock method for table locking (WRITE/READ) */
    protected string $lockMethod = 'WRITE';

    /** @var CacheManager|null Cache manager for query result caching */
    protected ?CacheManager $cacheManager = null;

    /** @var QueryCompilationCache|null Query compilation cache */
    protected ?QueryCompilationCache $compilationCache = null;

    /** @var QueryProfiler|null Query profiler for performance analysis */
    protected ?QueryProfiler $profiler = null;

    /** @var LoggerInterface|null Logger instance */
    protected ?LoggerInterface $logger = null;

    /** @var ConnectionRouter|null Connection router for read/write splitting */
    protected ?ConnectionRouter $connectionRouter = null;

    /** @var bool Whether read/write splitting is enabled */
    protected bool $readWriteSplittingEnabled = false;

    /** @var EventDispatcherInterface|null Event dispatcher */
    protected ?EventDispatcherInterface $eventDispatcher = null;

    /**
     * Initializes a new PdoDb object.
     *
     * @param string|null $driver The database driver to use. Pass null to use connection pooling without default connection.
     * @param array<string, mixed> $config An array of configuration options for the database connection.
     * @param array<int|string, mixed> $pdoOptions An array of PDO options to use to connect to the database.
     * @param LoggerInterface|null $logger The logger to use to log the queries.
     * @param CacheInterface|null $cache PSR-16 cache implementation for query result caching.
     *
     * @see /README.md for details
     */
    public function __construct(
        ?string $driver = null,
        array $config = [],
        array $pdoOptions = [],
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null
    ) {
        $prefix = $config['prefix'] ?? '';
        $this->prefix = is_string($prefix) ? $prefix : '';
        $this->logger = $logger;

        // Initialize cache manager if cache is provided
        if ($cache !== null) {
            $cacheConfig = isset($config['cache']) && is_array($config['cache']) ? $config['cache'] : [];
            $this->cacheManager = new CacheManager($cache, $cacheConfig);

            // Initialize compilation cache using the same cache backend
            $this->compilationCache = new QueryCompilationCache($cache);
            if (isset($config['compilation_cache']) && is_array($config['compilation_cache'])) {
                if (isset($config['compilation_cache']['enabled'])) {
                    $this->compilationCache->setEnabled((bool)$config['compilation_cache']['enabled']);
                }
                if (isset($config['compilation_cache']['ttl'])) {
                    $this->compilationCache->setDefaultTtl((int)$config['compilation_cache']['ttl']);
                }
            }
        }

        // Only create default connection if driver is provided
        if ($driver !== null) {
            $this->addConnection('default', [
                'driver' => $driver,
                ...$config,
            ], $pdoOptions, $logger);

            // use default connection
            $this->connection('default');
        }
    }

    /**
     * Enable query profiling.
     *
     * @param float $slowQueryThreshold Slow query threshold in seconds (default: 1.0)
     *
     * @return self
     */
    public function enableProfiling(float $slowQueryThreshold = 1.0): self
    {
        if ($this->profiler === null) {
            $this->profiler = new QueryProfiler();
            $this->profiler->setLogger($this->logger);
        }

        $this->profiler->enable();
        $this->profiler->setSlowQueryThreshold($slowQueryThreshold);

        return $this;
    }

    /**
     * Disable query profiling.
     *
     * @return self
     */
    public function disableProfiling(): self
    {
        $this->profiler?->disable();
        return $this;
    }

    /**
     * Check if profiling is enabled.
     *
     * @return bool
     */
    public function isProfilingEnabled(): bool
    {
        return $this->profiler?->isEnabled() ?? false;
    }

    /**
     * Get profiler statistics.
     *
     * @param bool $aggregated If true, return aggregated stats; if false, return per-query stats
     *
     * @return array<string, mixed> Profiler statistics
     */
    public function getProfilerStats(bool $aggregated = false): array
    {
        if ($this->profiler === null) {
            return [];
        }

        return $aggregated ? $this->profiler->getAggregatedStats() : $this->profiler->getStats();
    }

    /**
     * Get slowest queries.
     *
     * @param int $limit Number of queries to return
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSlowestQueries(int $limit = 10): array
    {
        return $this->profiler?->getSlowestQueries($limit) ?? [];
    }

    /**
     * Reset profiler statistics.
     *
     * @return self
     */
    public function resetProfiler(): self
    {
        $this->profiler?->reset();
        return $this;
    }

    /**
     * Get query profiler instance.
     *
     * @return QueryProfiler|null
     */
    public function getProfiler(): ?QueryProfiler
    {
        return $this->profiler;
    }

    /**
     * Set query profiler instance.
     *
     * @param QueryProfiler|null $profiler
     *
     * @return self
     */
    public function setProfiler(?QueryProfiler $profiler): self
    {
        $this->profiler = $profiler;
        return $this;
    }

    /**
     * Returns a new QueryBuilder instance.
     *
     * @return QueryBuilder The new QueryBuilder instance.
     */
    public function find(): QueryBuilder
    {
        $queryBuilder = new QueryBuilder($this->connection, $this->prefix, $this->cacheManager, $this->compilationCache, $this->profiler);

        // Set connection router if read/write splitting is enabled
        if ($this->readWriteSplittingEnabled && $this->connectionRouter !== null) {
            $queryBuilder->setConnectionRouter($this->connectionRouter);
        }

        return $queryBuilder;
    }

    /**
     * Set event dispatcher.
     *
     * @param EventDispatcherInterface|null $dispatcher The dispatcher instance or null to disable
     *
     * @return self
     */
    public function setEventDispatcher(?EventDispatcherInterface $dispatcher): self
    {
        $this->eventDispatcher = $dispatcher;

        // Update existing connections with the new dispatcher
        foreach ($this->connections as $connection) {
            $connection->setEventDispatcher($dispatcher);
        }

        return $this;
    }

    /**
     * Get event dispatcher.
     *
     * @return EventDispatcherInterface|null The dispatcher instance or null if not set
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * Get cache manager instance.
     *
     * @return CacheManager|null
     */
    public function getCacheManager(): ?CacheManager
    {
        return $this->cacheManager;
    }

    /**
     * Set query compilation cache.
     *
     * @param QueryCompilationCache|null $compilationCache Compilation cache instance
     *
     * @return self
     */
    public function setCompilationCache(?QueryCompilationCache $compilationCache): self
    {
        $this->compilationCache = $compilationCache;
        return $this;
    }

    /**
     * Get query compilation cache instance.
     *
     * @return QueryCompilationCache|null
     */
    public function getCompilationCache(): ?QueryCompilationCache
    {
        return $this->compilationCache;
    }

    /* ---------------- RAW ---------------- */

    /**
     * Execute a raw query.
     *
     * @param string|RawValue $query The raw query to be executed.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to be bound to the query.
     *
     * @return array<int, array<string, mixed>> The result of the query.
     */
    public function rawQuery(string|RawValue $query, array $params = []): array
    {
        return $this->find()->fetchAll($query, $params);
    }

    /**
     * Execute a raw query and return the first row.
     *
     * @param string|RawValue $query The raw query to be executed.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to be bound to the query.
     *
     * @return mixed The first row of the result.
     */
    public function rawQueryOne(string|RawValue $query, array $params = []): mixed
    {
        return $this->find()->fetch($query, $params);
    }

    /**
     * Execute a raw query and return the value of the first column of the first row.
     *
     * @param string|RawValue $query The raw query to be executed.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to be bound to the query.
     *
     * @return mixed The value of the first column of the first row.
     */
    public function rawQueryValue(string|RawValue $query, array $params = []): mixed
    {
        return $this->find()->fetchColumn($query, $params);
    }

    /* ---------------- TRANSACTIONS ---------------- */

    /**
     * Starts a transaction.
     */
    public function startTransaction(): void
    {
        $conn = $this->connection;
        if (!$conn->inTransaction()) {
            $conn->transaction();
            if ($this->connectionRouter !== null) {
                $this->connectionRouter->setTransactionState(true);
            }
        }
    }

    /**
     * Commits the transaction.
     */
    public function commit(): void
    {
        $conn = $this->connection;
        if ($conn->inTransaction()) {
            $conn->commit();
            if ($this->connectionRouter !== null) {
                $this->connectionRouter->setTransactionState(false);
            }
        }
    }

    /**
     * Rolls back the transaction.
     */
    public function rollback(): void
    {
        $conn = $this->connection;
        if ($conn->inTransaction()) {
            $conn->rollBack();
            if ($this->connectionRouter !== null) {
                $this->connectionRouter->setTransactionState(false);
            }
        }
    }

    /**
     * Checks if a transaction is currently active.
     *
     * @return bool True if a transaction is active, false otherwise.
     */
    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    /**
     * Executes a callback within a transaction.
     *
     * @param callable(PdoDb): mixed $callback The callback to be executed.
     *
     * @return mixed The result of the callback.
     * @throws Throwable If the callback throws an exception, it will be rethrown after rolling back the transaction.
     */
    public function transaction(callable $callback): mixed
    {
        $this->startTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    /* ---------------- LOCKING ---------------- */

    /**
     * Locks the specified tables.
     *
     * @param string|array<int, string> $tables The tables to lock.
     *
     * @return bool True if the lock was successful, false otherwise.
     */
    public function lock(string|array $tables): bool
    {
        $tables = (array)$tables;
        $conn = $this->connection;
        $sql = $conn->getDialect()->buildLockSql($tables, $this->prefix, $this->lockMethod);
        $conn->prepare($sql)->execute();
        return $this->executeState !== false;
    }

    /**
     * Unlocks the specified tables.
     *
     * @return bool True if unlock was successful, false otherwise.
     */
    public function unlock(): bool
    {
        $conn = $this->connection;
        $sql = $conn->getDialect()->buildUnlockSql();
        if ($sql === '') {
            return true;
        }
        $conn->prepare($sql)->execute();
        return $this->executeState !== false;
    }

    /**
     * Sets the lock method.
     *
     * @param string $method The lock method to use.
     *
     * @return self The current object.
     */
    public function setLockMethod(string $method): self
    {
        $upper = strtoupper($method);
        if (!in_array($upper, [self::LOCK_WRITE, self::LOCK_READ], true)) {
            throw new InvalidArgumentException("Invalid lock method: $method");
        }
        $this->lockMethod = $upper;
        return $this;
    }

    /* ---------------- CONNECTIONS ---------------- */

    /**
     * Adds a connection to the connection pool.
     *
     * @param string $name The name of the connection.
     * @param array<string, mixed> $params The parameters to use to connect to the database.
     * @param array<int|string, mixed> $pdoOptions The PDO options to use to connect to the database.
     * @param LoggerInterface|null $logger The logger to use to log the queries.
     */
    public function addConnection(
        string $name,
        array $params,
        array $pdoOptions = [],
        ?LoggerInterface $logger = null
    ): void {
        $params['options'] = $pdoOptions;
        $connectionFactory = new ConnectionFactory();

        // Pass event dispatcher to factory if available
        if ($this->eventDispatcher !== null) {
            $connectionFactory->setEventDispatcher($this->eventDispatcher);
        }

        $connection = $connectionFactory->create($params, $logger);
        $this->connections[$name] = $connection;

        // Register with router if read/write splitting is enabled
        if ($this->readWriteSplittingEnabled && $this->connectionRouter !== null) {
            $type = $params['type'] ?? 'write';
            if ($type === 'read') {
                $this->connectionRouter->addReadConnection($name, $connection);
            } else {
                $this->connectionRouter->addWriteConnection($name, $connection);
            }
        }
    }

    /**
     * Checks if a connection exists in the connection pool.
     *
     * @param string $name The name of the connection.
     *
     * @return bool True if the connection exists, false otherwise.
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * Returns a connection from the connection pool.
     *
     * @param string $name The name of the connection.
     *
     * @return self The connection.
     */
    public function connection(string $name): self
    {
        if (!isset($this->connections[$name])) {
            throw new RuntimeException("Connection $name not found");
        }
        $this->connectionStorage = $this->connections[$name];
        return $this;
    }

    /**
     * Disconnects from the database.
     *
     * @param string|null $name The name of the connection to disconnect.
     *                          Pass null to disconnect all connections.
     */
    public function disconnect(?string $name = null): void
    {
        if ($name === null) {
            $this->connectionStorage = null;
            $this->connections = [];
        } elseif (isset($this->connections[$name])) {
            if ($this->connectionStorage === $this->connections[$name]) {
                $this->connectionStorage = null;
            }
            unset($this->connections[$name]);
        }
    }

    /**
     * Pings the database.
     *
     * @return bool True if the ping was successful, false otherwise.
     */
    public function ping(): bool
    {
        try {
            $stmt = $this->connection->query('SELECT 1');
            if ($stmt !== false) {
                $stmt->execute();
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Sets the query timeout for the current connection.
     *
     * @param int $seconds The timeout in seconds.
     *
     * @return self The current object.
     * @throws RuntimeException If the timeout cannot be set.
     */
    public function setTimeout(int $seconds): self
    {
        try {
            $this->connection->setAttribute(\PDO::ATTR_TIMEOUT, $seconds);
            return $this;
        } catch (\PDOException $e) {
            // Some drivers (like SQLite) don't support ATTR_TIMEOUT
            if (str_contains($e->getMessage(), 'does not support this function') ||
                str_contains($e->getMessage(), 'driver does not support that attribute')) {
                // Silently ignore for unsupported drivers
                return $this;
            }

            throw new RuntimeException('Failed to set timeout: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gets the current query timeout.
     *
     * @return int The timeout in seconds.
     * @throws RuntimeException If the timeout cannot be retrieved.
     */
    public function getTimeout(): int
    {
        try {
            $timeout = $this->connection->getAttribute(\PDO::ATTR_TIMEOUT);
            return (int)$timeout;
        } catch (\PDOException $e) {
            // Some drivers (like SQLite) don't support ATTR_TIMEOUT
            if (str_contains($e->getMessage(), 'does not support this function') ||
                str_contains($e->getMessage(), 'driver does not support that attribute')) {
                // Return 0 for unsupported drivers
                return 0;
            }

            throw new RuntimeException('Failed to get timeout: ' . $e->getMessage(), 0, $e);
        }
    }

    /* ---------------- INTROSPECT ---------------- */

    /**
     * Describes a table.
     *
     * @param string $table The table to describe.
     *
     * @return array<int, array<string, mixed>> The table description.
     */
    public function describe(string $table): array
    {
        $sql = $this->connection->getDialect()->buildDescribeSql($this->prefix . $table);
        return $this->rawQuery($sql);
    }

    /**
     * Explains a query.
     *
     * @param string $query The query to explain.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to use to explain the query.
     *
     * @return array<int, array<string, mixed>> The query explanation.
     */
    public function explain(string $query, array $params = []): array
    {
        $sql = $this->connection->getDialect()->buildExplainSql($query);
        return $this->rawQuery($sql, $params);
    }

    /**
     * Explains and analyzes a query.
     *
     * @param string $query The query to explain and analyze.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to use to explain and analyze the query.
     *
     * @return array<int, array<string, mixed>> The query explanation and analysis.
     */
    public function explainAnalyze(string $query, array $params = []): array
    {
        $sql = $this->connection->getDialect()->buildExplainAnalyzeSql($query);
        return $this->rawQuery($sql, $params);
    }

    /**
     * Get indexes for a table.
     *
     * @param string $table The table name.
     *
     * @return array<int, array<string, mixed>> The indexes.
     */
    public function indexes(string $table): array
    {
        return $this->find()->from($table)->indexes();
    }

    /**
     * Get foreign keys for a table.
     *
     * @param string $table The table name.
     *
     * @return array<int, array<string, mixed>> The foreign keys.
     */
    public function keys(string $table): array
    {
        return $this->find()->from($table)->keys();
    }

    /**
     * Get constraints for a table.
     *
     * @param string $table The table name.
     *
     * @return array<int, array<string, mixed>> The constraints.
     */
    public function constraints(string $table): array
    {
        return $this->find()->from($table)->constraints();
    }

    /* ---------------- READ/WRITE SPLITTING ---------------- */

    /**
     * Enable read/write connection splitting.
     *
     * @param LoadBalancerInterface|null $loadBalancer Load balancer strategy
     *
     * @return self
     */
    public function enableReadWriteSplitting(?LoadBalancerInterface $loadBalancer = null): self
    {
        $this->connectionRouter = new ConnectionRouter($loadBalancer);
        $this->readWriteSplittingEnabled = true;
        return $this;
    }

    /**
     * Disable read/write connection splitting.
     *
     * @return self
     */
    public function disableReadWriteSplitting(): self
    {
        $this->readWriteSplittingEnabled = false;
        $this->connectionRouter = null;
        return $this;
    }

    /**
     * Enable sticky writes (read from master after write for N seconds).
     *
     * @param int $durationSeconds Duration in seconds
     *
     * @return self
     */
    public function enableStickyWrites(int $durationSeconds): self
    {
        if ($this->connectionRouter === null) {
            throw new RuntimeException('Read/write splitting must be enabled first');
        }
        $this->connectionRouter->enableStickyWrites($durationSeconds);
        return $this;
    }

    /**
     * Disable sticky writes.
     *
     * @return self
     */
    public function disableStickyWrites(): self
    {
        if ($this->connectionRouter !== null) {
            $this->connectionRouter->disableStickyWrites();
        }
        return $this;
    }

    /**
     * Get the connection router.
     *
     * @return ConnectionRouter|null
     */
    public function getConnectionRouter(): ?ConnectionRouter
    {
        return $this->connectionRouter;
    }
}
