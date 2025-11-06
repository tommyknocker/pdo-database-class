<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use Closure;
use Generator;
use InvalidArgumentException;
use PDOException;
use PDOStatement;
use RuntimeException;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\ConnectionRouter;
use tommyknocker\pdodb\connection\sharding\ShardRouter;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\cache\QueryCompilationCache;
use tommyknocker\pdodb\query\cte\CteDefinition;
use tommyknocker\pdodb\query\cte\CteManager;
use tommyknocker\pdodb\query\interfaces\BatchProcessorInterface;
use tommyknocker\pdodb\query\interfaces\ConditionBuilderInterface;
use tommyknocker\pdodb\query\interfaces\DmlQueryBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\FileLoaderInterface;
use tommyknocker\pdodb\query\interfaces\JoinBuilderInterface;
use tommyknocker\pdodb\query\interfaces\JsonQueryBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;
use tommyknocker\pdodb\query\interfaces\QueryBuilderInterface;
use tommyknocker\pdodb\query\interfaces\SelectQueryBuilderInterface;

class QueryBuilder implements QueryBuilderInterface
{
    /* ---------------- Construction / meta ---------------- */

    /** @var ConnectionInterface Database connection instance */
    protected ConnectionInterface $connection;

    /** @var DialectInterface Dialect instance for database-specific SQL */
    protected DialectInterface $dialect;

    /** @var string|null table name */
    protected ?string $table = null {
        get {
            if (!$this->table) {
                throw new RuntimeException('You must define table first. Use table() or from() methods');
            }
            return $this->table;
        }
    }

    /** @var string|null Table prefix */
    protected ?string $prefix = null;

    /** @var CacheManager|null Cache manager for query result caching */
    protected ?CacheManager $cacheManager = null;

    /** @var QueryCompilationCache|null Query compilation cache */
    protected ?QueryCompilationCache $compilationCache = null;

    /** @var ConnectionRouter|null Connection router for read/write splitting */
    protected ?ConnectionRouter $connectionRouter = null;

    /** @var ShardRouter|null Shard router for sharding */
    protected ?ShardRouter $shardRouter = null;

    /** @var bool Force next query to use write connection */
    protected bool $forceWriteConnection = false;

    /** @var CteManager|null CTE manager for Common Table Expressions */
    protected ?CteManager $cteManager = null;

    /** @var array<UnionQuery> Array of UNION/INTERSECT/EXCEPT operations */
    protected array $unions = [];

    /** @var bool Whether to use DISTINCT */
    protected bool $distinct = false;

    /** @var array<string> Columns for DISTINCT ON (PostgreSQL) */
    protected array $distinctOn = [];

    /** @var array<string> Disabled global scopes */
    protected array $disabledGlobalScopes = [];

    /**
     * @var array<string, callable> Global scopes to apply
     *
     * @phpstan-var array<string, callable(QueryBuilder, mixed...): QueryBuilder>
     */
    protected array $globalScopes = [];

    /** @var bool Whether global scopes have been applied */
    protected bool $globalScopesApplied = false;

    // Component instances
    protected ParameterManagerInterface $parameterManager;
    protected ExecutionEngineInterface $executionEngine;
    protected ConditionBuilderInterface $conditionBuilder;
    protected JoinBuilderInterface $joinBuilder;
    protected SelectQueryBuilderInterface $selectQueryBuilder;
    protected DmlQueryBuilderInterface $dmlQueryBuilder;
    protected BatchProcessorInterface $batchProcessor;
    protected JsonQueryBuilderInterface $jsonQueryBuilder;
    protected FileLoaderInterface $fileLoader;

    /**
     * QueryBuilder constructor.
     *
     * @param ConnectionInterface $connection
     * @param string $prefix
     * @param CacheManager|null $cacheManager
     * @param QueryCompilationCache|null $compilationCache
     * @param QueryProfiler|null $profiler
     */
    public function __construct(
        ConnectionInterface $connection,
        string $prefix = '',
        ?CacheManager $cacheManager = null,
        ?QueryCompilationCache $compilationCache = null,
        ?QueryProfiler $profiler = null
    ) {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->prefix = $prefix;
        $this->cacheManager = $cacheManager;
        $this->compilationCache = $compilationCache;

        // Initialize components with shared parameter manager and raw value resolver
        $this->parameterManager = new ParameterManager();
        $rawValueResolver = new RawValueResolver($connection, $this->parameterManager);
        $this->executionEngine = new ExecutionEngine($connection, $rawValueResolver, $this->parameterManager, $profiler);
        $this->conditionBuilder = new ConditionBuilder(
            $connection,
            $this->parameterManager,
            $this->executionEngine,
            $rawValueResolver
        );
        $this->joinBuilder = new JoinBuilder($connection, $rawValueResolver, $this->parameterManager);
        $this->selectQueryBuilder = new SelectQueryBuilder(
            $connection,
            $this->parameterManager,
            $this->executionEngine,
            $this->conditionBuilder,
            $this->joinBuilder,
            $rawValueResolver,
            $cacheManager,
            $this->compilationCache
        );
        $this->dmlQueryBuilder = new DmlQueryBuilder(
            $connection,
            $this->parameterManager,
            $this->executionEngine,
            $this->conditionBuilder,
            $rawValueResolver,
            $this->joinBuilder
        );
        $this->batchProcessor = new BatchProcessor(
            $connection,
            $this->executionEngine,
            $this->parameterManager,
            $rawValueResolver
        );
        $this->jsonQueryBuilder = new JsonQueryBuilder(
            $connection,
            $this->parameterManager,
            $this->conditionBuilder,
            $rawValueResolver
        );
        $this->fileLoader = new FileLoader($connection);

        // Set initial state
        $this->setPrefix($prefix);
    }

    /**
     * Return active connection instance.
     *
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Return dialect instance used by this builder.
     *
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface
    {
        return $this->dialect;
    }

    /**
     * Prepare query context for error reporting.
     *
     * Sets debug information in ExecutionEngine so it can be included
     * in exception context when errors occur.
     *
     * @return static
     */
    protected function prepareQueryContext(): static
    {
        try {
            $debugInfo = $this->getDebugInfo();
            // Set in shared ExecutionEngine (used by both SelectQueryBuilder and DmlQueryBuilder)
            $this->executionEngine->setQueryContext($debugInfo);
        } catch (\Throwable) {
            // If getting debug info fails, don't break the query execution
            $this->executionEngine->setQueryContext(null);
        }

        return $this;
    }

    /**
     * Return table prefix configured for this builder.
     *
     * @return string|null
     */
    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    /**
     * Set connection router for read/write splitting.
     *
     * @param ConnectionRouter|null $router
     *
     * @return static
     */
    public function setConnectionRouter(?ConnectionRouter $router): static
    {
        $this->connectionRouter = $router;
        return $this;
    }

    /**
     * Set shard router for sharding.
     *
     * @param ShardRouter|null $router
     *
     * @return static
     */
    public function setShardRouter(?ShardRouter $router): static
    {
        $this->shardRouter = $router;
        return $this;
    }

    /**
     * Force next read query to use write connection.
     *
     * @return static
     */
    public function forceWrite(): static
    {
        $this->forceWriteConnection = true;
        return $this;
    }

    /**
     * Switch to shard connection if sharding is configured.
     *
     * @return ConnectionInterface|null Original connection before switch, or null if no sharding
     */
    protected function switchToShardConnection(): ?ConnectionInterface
    {
        if ($this->shardRouter === null) {
            return null;
        }

        try {
            $shardName = $this->shardRouter->resolveShard($this);
            if ($shardName === null) {
                // Shard key not found in WHERE conditions - cannot determine shard
                // This is acceptable for queries that need to scan all shards
                return null;
            }

            $table = $this->getTableName();
            if ($table === null) {
                return null;
            }

            $originalConnection = $this->connection;
            $this->connection = $this->shardRouter->getShardConnection($table, $shardName);

            // Update components with new connection
            $this->updateComponents();

            return $originalConnection;
        } catch (RuntimeException) {
            // If shard cannot be resolved, return null to fall back to normal routing
            return null;
        }
    }

    /**
     * Switch to read connection if router is available.
     *
     * @return ConnectionInterface|null Original connection before switch
     */
    protected function switchToReadConnection(): ?ConnectionInterface
    {
        // First try sharding
        $originalConnection = $this->switchToShardConnection();
        if ($originalConnection !== null) {
            return $originalConnection;
        }

        // Fall back to read/write splitting
        if ($this->connectionRouter === null) {
            return null;
        }

        $originalConnection = $this->connection;

        // Get appropriate connection based on forceWrite flag
        if ($this->forceWriteConnection) {
            $this->connection = $this->connectionRouter->getWriteConnection();
            $this->forceWriteConnection = false; // Reset flag after use
        } else {
            $this->connection = $this->connectionRouter->getReadConnection();
        }

        // Update components with new connection
        $this->updateComponents();

        return $originalConnection;
    }

    /**
     * Switch to write connection if router is available.
     *
     * @return ConnectionInterface|null Original connection before switch
     */
    protected function switchToWriteConnection(): ?ConnectionInterface
    {
        // First try sharding
        $originalConnection = $this->switchToShardConnection();
        if ($originalConnection !== null) {
            return $originalConnection;
        }

        // Fall back to read/write splitting
        if ($this->connectionRouter === null) {
            return null;
        }

        $originalConnection = $this->connection;
        $this->connection = $this->connectionRouter->getWriteConnection();

        // Update components with new connection
        $this->updateComponents();

        return $originalConnection;
    }

    /**
     * Restore original connection.
     *
     * @param ConnectionInterface|null $originalConnection
     */
    protected function restoreConnection(?ConnectionInterface $originalConnection): void
    {
        if ($originalConnection !== null && $this->connectionRouter !== null) {
            $this->connection = $originalConnection;
            $this->updateComponents();
        }
    }

    /**
     * Update all components with current connection.
     *
     * Note: This method minimally updates components to preserve state.
     * We only update the execution engine which directly uses the connection.
     */
    protected function updateComponents(): void
    {
        $this->dialect = $this->connection->getDialect();
        $rawValueResolver = new RawValueResolver($this->connection, $this->parameterManager);

        // Only update execution engine to use new connection
        // Keep other components (conditionBuilder, etc.) to preserve their state
        $this->executionEngine = new ExecutionEngine($this->connection, $rawValueResolver, $this->parameterManager);

        // Update execution engine reference in condition builder
        // This is done via property access if the builder supports it
    }

    /* ---------------- Table / source ---------------- */

    /**
     * Sets the table to query.
     *
     * @param string $table The table to query.
     *
     * @return static The current instance.
     */
    public function table(string $table): static
    {
        return $this->from($table);
    }

    /**
     * Sets the table to query.
     *
     * @param string $table The table to query.
     *
     * @return static The current instance.
     */
    public function from(string $table): static
    {
        $this->table = $table;
        $this->selectQueryBuilder->setTable($table);
        $this->dmlQueryBuilder->setTable($table);
        $this->fileLoader->setTable($table);
        return $this;
    }

    /**
     * Add a Common Table Expression (CTE) to the query.
     *
     * @param string $name CTE name
     * @param QueryBuilder|Closure(QueryBuilder): void|string|RawValue $query Query builder, closure, or raw SQL
     * @param array<string> $columns Optional explicit column list
     *
     * @return static The current instance.
     */
    public function with(
        string $name,
        QueryBuilder|Closure|string|RawValue $query,
        array $columns = []
    ): static {
        if ($this->cteManager === null) {
            $this->cteManager = new CteManager($this->connection);
        }

        // Convert RawValue to string
        if ($query instanceof RawValue) {
            $query = $query->getValue();
        }

        $cte = new CteDefinition($name, $query, false, false, $columns);
        $this->cteManager->add($cte);

        return $this;
    }

    /**
     * Add a recursive Common Table Expression (CTE) to the query.
     *
     * @param string $name CTE name
     * @param QueryBuilder|Closure(QueryBuilder): void|string|RawValue $query Query with UNION ALL structure
     * @param array<string> $columns Explicit column list (recommended for recursive CTEs)
     *
     * @return static The current instance.
     */
    public function withRecursive(
        string $name,
        QueryBuilder|Closure|string|RawValue $query,
        array $columns = []
    ): static {
        if ($this->cteManager === null) {
            $this->cteManager = new CteManager($this->connection);
        }

        // Convert RawValue to string
        if ($query instanceof RawValue) {
            $query = $query->getValue();
        }

        $cte = new CteDefinition($name, $query, true, false, $columns);
        $this->cteManager->add($cte);

        return $this;
    }

    /**
     * Add a materialized Common Table Expression (CTE) to the query.
     *
     * Materialized CTEs cache the result set, improving performance for expensive queries
     * that are referenced multiple times. The result is computed once and stored in memory.
     *
     * Supported databases:
     * - PostgreSQL: Uses MATERIALIZED keyword (PostgreSQL 12+)
     * - MySQL: Uses optimizer hints (MySQL 8.0+)
     * - SQLite: Not supported (throws RuntimeException)
     *
     * @param string $name CTE name
     * @param QueryBuilder|Closure(QueryBuilder): void|string|RawValue $query Query builder, closure, or raw SQL
     * @param array<string> $columns Explicit column list (optional)
     *
     * @return static The current instance.
     * @throws RuntimeException If database dialect does not support materialized CTEs
     */
    public function withMaterialized(
        string $name,
        QueryBuilder|Closure|string|RawValue $query,
        array $columns = []
    ): static {
        if ($this->cteManager === null) {
            $this->cteManager = new CteManager($this->connection);
        }

        // Convert RawValue to string
        if ($query instanceof RawValue) {
            $query = $query->getValue();
        }

        $cte = new CteDefinition($name, $query, false, true, $columns);
        $this->cteManager->add($cte);

        return $this;
    }

    /**
     * Get CTE manager instance.
     *
     * @return CteManager|null CTE manager or null if not initialized.
     */
    public function getCteManager(): ?CteManager
    {
        return $this->cteManager;
    }

    /**
     * Add UNION operation.
     *
     * @param QueryBuilder|Closure(QueryBuilder): void $query Query to union.
     *
     * @return static The current instance.
     */
    public function union(QueryBuilder|Closure $query): static
    {
        $this->unions[] = new UnionQuery('UNION', $query);
        return $this;
    }

    /**
     * Add UNION ALL operation.
     *
     * @param QueryBuilder|Closure(QueryBuilder): void $query Query to union.
     *
     * @return static The current instance.
     */
    public function unionAll(QueryBuilder|Closure $query): static
    {
        $this->unions[] = new UnionQuery('UNION ALL', $query);
        return $this;
    }

    /**
     * Add INTERSECT operation.
     *
     * @param QueryBuilder|Closure(QueryBuilder): void $query Query to intersect.
     *
     * @return static The current instance.
     */
    public function intersect(QueryBuilder|Closure $query): static
    {
        $this->unions[] = new UnionQuery('INTERSECT', $query);
        return $this;
    }

    /**
     * Add EXCEPT operation.
     *
     * @param QueryBuilder|Closure(QueryBuilder): void $query Query to except.
     *
     * @return static The current instance.
     */
    public function except(QueryBuilder|Closure $query): static
    {
        $this->unions[] = new UnionQuery('EXCEPT', $query);
        return $this;
    }

    /**
     * Get union operations.
     *
     * @return array<UnionQuery>
     */
    public function getUnions(): array
    {
        return $this->unions;
    }

    /**
     * Enable DISTINCT for the query.
     *
     * @return static The current instance.
     */
    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Enable DISTINCT ON for specific columns.
     * Note: Currently supported only in PostgreSQL. Will throw exception for other databases.
     *
     * @param string|array<string> $columns Column(s) for DISTINCT ON.
     *
     * @return static The current instance.
     * @throws RuntimeException If database does not support DISTINCT ON.
     */
    public function distinctOn(string|array $columns): static
    {
        if (!$this->dialect->supportsDistinctOn()) {
            throw new RuntimeException(
                'DISTINCT ON is not supported by ' . get_class($this->dialect)
            );
        }

        $this->distinctOn = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    /**
     * Check if DISTINCT is enabled.
     *
     * @return bool
     */
    public function isDistinct(): bool
    {
        return $this->distinct;
    }

    /**
     * Get DISTINCT ON columns.
     *
     * @return array<string>
     */
    public function getDistinctOn(): array
    {
        return $this->distinctOn;
    }

    /**
     * Apply a query scope.
     *
     * Scopes allow you to encapsulate query logic into reusable callables.
     * Can accept either a callable directly or a scope name (requires scope registry).
     *
     * @param callable(QueryBuilder, mixed...): QueryBuilder|string $scope Scope callable or scope name
     * @param mixed ...$args Additional arguments to pass to the scope
     *
     * @return static The current instance
     */
    public function scope(callable|string $scope, mixed ...$args): static
    {
        if (is_string($scope)) {
            throw new RuntimeException(
                'Named scopes are not supported directly in QueryBuilder. ' .
                'Use scope callable or define scopes in Model class.'
            );
        }

        // Call the scope with this query builder instance
        $result = $scope($this, ...$args);

        // Ensure we return the same instance (fluent interface)
        if ($result instanceof static) {
            return $result;
        }

        return $this;
    }

    /**
     * Temporarily disable a global scope.
     *
     * @param string $scopeName Name of the global scope to disable
     *
     * @return static The current instance
     */
    public function withoutGlobalScope(string $scopeName): static
    {
        $this->disabledGlobalScopes[] = $scopeName;
        return $this;
    }

    /**
     * Temporarily disable multiple global scopes.
     *
     * @param array<string> $scopeNames Names of the global scopes to disable
     *
     * @return static The current instance
     */
    public function withoutGlobalScopes(array $scopeNames): static
    {
        $this->disabledGlobalScopes = array_merge($this->disabledGlobalScopes, $scopeNames);
        return $this;
    }

    /**
     * Check if a global scope is disabled.
     *
     * @param string $scopeName Name of the global scope
     *
     * @return bool True if disabled
     */
    public function isGlobalScopeDisabled(string $scopeName): bool
    {
        return in_array($scopeName, $this->disabledGlobalScopes, true);
    }

    /**
     * Get list of disabled global scopes.
     *
     * @return array<string> Disabled global scope names
     */
    public function getDisabledGlobalScopes(): array
    {
        return $this->disabledGlobalScopes;
    }

    /**
     * Set global scopes for this query builder.
     *
     * @param array<string, callable> $scopes Global scopes (callable accepts QueryBuilder and optional args, returns QueryBuilder)
     *
     * @phpstan-param array<string, callable(QueryBuilder, mixed...): QueryBuilder> $scopes
     *
     * @return static
     */
    public function setGlobalScopes(array $scopes): static
    {
        $this->globalScopes = $scopes;
        return $this;
    }

    /**
     * Apply global scopes lazily when query is executed.
     */
    protected function applyGlobalScopes(): void
    {
        if ($this->globalScopesApplied || empty($this->globalScopes)) {
            return;
        }

        foreach ($this->globalScopes as $scopeName => $scope) {
            // Skip if this scope is disabled
            if ($this->isGlobalScopeDisabled($scopeName)) {
                continue;
            }

            // Apply the scope
            $this->scope($scope);
        }

        $this->globalScopesApplied = true;
    }

    /**
     * Sets the prefix for table names.
     *
     * @param string $prefix The prefix for table names.
     *
     * @return static The current instance.
     */
    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;
        $this->setPrefix($prefix);
        return $this;
    }

    /**
     * Set prefix for all components.
     *
     * @param string|null $prefix
     */
    protected function setPrefix(?string $prefix): void
    {
        $this->selectQueryBuilder->setPrefix($prefix);
        $this->dmlQueryBuilder->setPrefix($prefix);
        $this->conditionBuilder->setPrefix($prefix);
        $this->joinBuilder->setPrefix($prefix);
        $this->fileLoader->setPrefix($prefix);
    }

    /* ---------------- Select / projection ---------------- */

    /**
     * Adds columns to the SELECT clause.
     *
     * @param RawValue|callable(QueryBuilderInterface): void|string|array<int|string, string|RawValue|callable(QueryBuilderInterface): void> $cols The columns to add.
     *
     * @return static The current instance.
     */
    public function select(RawValue|callable|string|array $cols): static
    {
        $this->selectQueryBuilder->select($cols);
        return $this;
    }

    /**
     * Execute SELECT statement and return all rows.
     *
     * @return array<int|string, array<string, mixed>>
     * @throws PDOException
     */
    public function get(): array
    {
        $this->applyGlobalScopes();
        $originalConnection = $this->switchToReadConnection();

        try {
            // Integrate JSON selections before executing query
            $jsonSelects = $this->jsonQueryBuilder->getJsonSelects();
            if (!empty($jsonSelects)) {
                $this->selectQueryBuilder->select($jsonSelects);
                // Clear JSON selections after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonSelects();
            }

            // Integrate JSON order expressions before executing query
            $jsonOrders = $this->jsonQueryBuilder->getJsonOrders();
            if (!empty($jsonOrders)) {
                foreach ($jsonOrders as $orderExpr) {
                    // JSON order expressions already contain direction, so add them directly
                    $this->selectQueryBuilder->addOrderExpression($orderExpr);
                }
                // Clear JSON orders after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonOrders();
            }

            // Set CTE manager before building query
            if ($this->cteManager !== null) {
                $this->selectQueryBuilder->setCteManager($this->cteManager);
            }

            // Set UNION operations
            if (!empty($this->unions)) {
                $this->selectQueryBuilder->setUnions($this->unions);
            }

            // Set DISTINCT
            if ($this->distinct) {
                $this->selectQueryBuilder->setDistinct(true);
            }

            // Set DISTINCT ON
            if (!empty($this->distinctOn)) {
                $this->selectQueryBuilder->setDistinctOn($this->distinctOn);
            }

            // Prepare query context for error reporting
            $this->prepareQueryContext();

            return $this->selectQueryBuilder->get();
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Execute SELECT statement and return first row.
     *
     * @return mixed
     * @throws PDOException
     */
    public function getOne(): mixed
    {
        $this->applyGlobalScopes();
        $originalConnection = $this->switchToReadConnection();

        try {
            // Integrate JSON selections before executing query
            $jsonSelects = $this->jsonQueryBuilder->getJsonSelects();
            if (!empty($jsonSelects)) {
                $this->selectQueryBuilder->select($jsonSelects);
                // Clear JSON selections after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonSelects();
            }

            // Integrate JSON order expressions before executing query
            $jsonOrders = $this->jsonQueryBuilder->getJsonOrders();
            if (!empty($jsonOrders)) {
                foreach ($jsonOrders as $orderExpr) {
                    // JSON order expressions already contain direction, so add them directly
                    $this->selectQueryBuilder->addOrderExpression($orderExpr);
                }
                // Clear JSON orders after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonOrders();
            }

            // Set CTE manager before building query
            if ($this->cteManager !== null) {
                $this->selectQueryBuilder->setCteManager($this->cteManager);
            }

            return $this->selectQueryBuilder->getOne();
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Execute SELECT statement and return column values.
     *
     * @return array<int, mixed>
     * @throws PDOException
     */
    public function getColumn(): array
    {
        $this->applyGlobalScopes();
        $originalConnection = $this->switchToReadConnection();

        try {
            // Integrate JSON selections before executing query
            $jsonSelects = $this->jsonQueryBuilder->getJsonSelects();
            if (!empty($jsonSelects)) {
                $this->selectQueryBuilder->select($jsonSelects);
                // Clear JSON selections after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonSelects();
            }

            // Integrate JSON order expressions before executing query
            $jsonOrders = $this->jsonQueryBuilder->getJsonOrders();
            if (!empty($jsonOrders)) {
                foreach ($jsonOrders as $orderExpr) {
                    // JSON order expressions already contain direction, so add them directly
                    $this->selectQueryBuilder->addOrderExpression($orderExpr);
                }
                // Clear JSON orders after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonOrders();
            }

            // Set CTE manager before building query
            if ($this->cteManager !== null) {
                $this->selectQueryBuilder->setCteManager($this->cteManager);
            }

            return $this->selectQueryBuilder->getColumn();
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Execute SELECT statement and return single value.
     *
     * @return mixed
     * @throws PDOException
     */
    public function getValue(): mixed
    {
        $this->applyGlobalScopes();
        $originalConnection = $this->switchToReadConnection();

        try {
            // Integrate JSON selections before executing query
            $jsonSelects = $this->jsonQueryBuilder->getJsonSelects();
            if (!empty($jsonSelects)) {
                $this->selectQueryBuilder->select($jsonSelects);
                // Clear JSON selections after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonSelects();
            }

            // Integrate JSON order expressions before executing query
            $jsonOrders = $this->jsonQueryBuilder->getJsonOrders();
            if (!empty($jsonOrders)) {
                foreach ($jsonOrders as $orderExpr) {
                    // JSON order expressions already contain direction, so add them directly
                    $this->selectQueryBuilder->addOrderExpression($orderExpr);
                }
                // Clear JSON orders after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonOrders();
            }

            // Set CTE manager before building query
            if ($this->cteManager !== null) {
                $this->selectQueryBuilder->setCteManager($this->cteManager);
            }

            return $this->selectQueryBuilder->getValue();
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Get the first row ordered by the specified field.
     *
     * This is an alias for orderBy($orderByField, 'ASC')->limit(1)->getOne().
     * Returns the first row matching the query conditions, or null if no rows found.
     *
     * @param string|array<int|string, string>|RawValue $orderByField Field(s) to order by (default: 'id')
     *
     * @return array<string, mixed>|null First row or null if no rows found
     * @throws PDOException
     */
    public function first(string|array|RawValue $orderByField = 'id'): ?array
    {
        $result = $this->orderBy($orderByField, 'ASC')->limit(1)->getOne();
        return $result !== false ? $result : null;
    }

    /**
     * Get the last row ordered by the specified field.
     *
     * This is an alias for orderBy($orderByField, 'DESC')->limit(1)->getOne().
     * Returns the last row matching the query conditions, or null if no rows found.
     *
     * @param string|array<int|string, string>|RawValue $orderByField Field(s) to order by (default: 'id')
     *
     * @return array<string, mixed>|null Last row or null if no rows found
     * @throws PDOException
     */
    public function last(string|array|RawValue $orderByField = 'id'): ?array
    {
        $result = $this->orderBy($orderByField, 'DESC')->limit(1)->getOne();
        return $result !== false ? $result : null;
    }

    /**
     * Index query results by the specified column.
     *
     * When calling get(), the result array will be indexed by the values of the specified column
     * instead of using numeric keys. If multiple rows have the same column value, only the last one will be kept.
     *
     * @param string $columnName Column name to use as array keys (default: 'id')
     *
     * @return static The current instance.
     */
    public function index(string $columnName = 'id'): static
    {
        $this->selectQueryBuilder->setIndexColumn($columnName);
        return $this;
    }

    /* ---------------- DML: insert / update / delete / replace ---------------- */

    /**
     * Insert data into the table.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data The data to insert.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insert(array $data, array $onDuplicate = []): int
    {
        $originalConnection = $this->switchToWriteConnection();

        try {
            $this->prepareQueryContext();
            return $this->dmlQueryBuilder->insert($data, $onDuplicate);
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Insert multiple rows into the table.
     *
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows The rows to insert.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insertMulti(array $rows, array $onDuplicate = []): int
    {
        $originalConnection = $this->switchToWriteConnection();

        try {
            $this->prepareQueryContext();
            return $this->dmlQueryBuilder->insertMulti($rows, $onDuplicate);
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Replace data into the table.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data The data to replace.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the replace operation.
     */
    public function replace(array $data, array $onDuplicate = []): int
    {
        $originalConnection = $this->switchToWriteConnection();

        try {
            $this->prepareQueryContext();
            return $this->dmlQueryBuilder->replace($data, $onDuplicate);
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Replace multiple rows into the table.
     *
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows The rows to replace.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the replace operation.
     */
    public function replaceMulti(array $rows, array $onDuplicate = []): int
    {
        $originalConnection = $this->switchToWriteConnection();

        try {
            $this->prepareQueryContext();
            return $this->dmlQueryBuilder->replaceMulti($rows, $onDuplicate);
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Execute UPDATE statement.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data
     *
     * @return int
     * @throws PDOException
     */
    public function update(array $data): int
    {
        $originalConnection = $this->switchToWriteConnection();

        try {
            $this->prepareQueryContext();
            return $this->dmlQueryBuilder->update($data);
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Execute DELETE statement.
     *
     * @return int
     * @throws PDOException
     */
    public function delete(): int
    {
        $originalConnection = $this->switchToWriteConnection();

        try {
            $this->prepareQueryContext();
            return $this->dmlQueryBuilder->delete();
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /**
     * Execute TRUNCATE statement.
     *
     * @return bool
     * @throws PDOException
     */
    public function truncate(): bool
    {
        return $this->dmlQueryBuilder->truncate();
    }

    /**
     * Execute MERGE statement (INSERT/UPDATE/DELETE based on match conditions).
     *
     * @param string|Closure(QueryBuilder): void|SelectQueryBuilderInterface $source Source table/subquery for MERGE
     * @param string|array<string> $onConditions ON clause conditions
     * @param array<string, string|int|float|bool|null|RawValue> $whenMatched Update columns when matched
     * @param array<string, string|int|float|bool|null|RawValue> $whenNotMatched Insert columns when not matched
     * @param bool $whenNotMatchedBySourceDelete Delete when not matched by source
     *
     * @return int Number of affected rows
     */
    public function merge(
        string|Closure|SelectQueryBuilderInterface $source,
        string|array $onConditions,
        array $whenMatched = [],
        array $whenNotMatched = [],
        bool $whenNotMatchedBySourceDelete = false
    ): int {
        $originalConnection = $this->switchToWriteConnection();

        try {
            if ($this->table === null) {
                throw new RuntimeException('Table must be set before calling merge()');
            }
            $this->dmlQueryBuilder->setTable($this->table);
            $this->dmlQueryBuilder->setPrefix($this->prefix);
            return $this->dmlQueryBuilder->merge($source, $onConditions, $whenMatched, $whenNotMatched, $whenNotMatchedBySourceDelete);
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /* ---------------- Batch processing methods ---------------- */

    /**
     * Execute query and return iterator for batch processing.
     *
     * Processes data in batches of specified size, yielding arrays of records.
     * Useful for processing large datasets without loading everything into memory.
     *
     * @param int $batchSize Number of records per batch (default: 100)
     *
     * @return Generator<int, array<int, array<string, mixed>>, mixed, void>
     * @throws InvalidArgumentException If batch size is invalid
     * @throws PDOException
     */
    public function batch(int $batchSize = 100): Generator
    {
        $query = $this->selectQueryBuilder->getQuery();
        return $this->batchProcessor->batch($query['sql'], $query['params'], $batchSize);
    }

    /**
     * Execute query and return iterator for individual record processing.
     *
     * Processes data one record at a time, but loads them from database in batches
     * for efficiency. Useful when you need to process each record individually
     * but want to avoid memory issues with large datasets.
     *
     * @param int $batchSize Internal batch size for database queries (default: 100)
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     * @throws InvalidArgumentException If batch size is invalid
     * @throws PDOException
     */
    public function each(int $batchSize = 100): Generator
    {
        $query = $this->selectQueryBuilder->getQuery();
        return $this->batchProcessor->each($query['sql'], $query['params'], $batchSize);
    }

    /**
     * Stream query results without loading into memory.
     *
     * Most memory efficient method for very large datasets. Uses database cursor
     * to stream results row by row without loading them into memory. Best for simple
     * sequential processing of large datasets.
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     * @throws PDOException
     */
    public function stream(): Generator
    {
        $originalConnection = $this->switchToReadConnection();

        try {
            // Integrate JSON selections before executing query
            $jsonSelects = $this->jsonQueryBuilder->getJsonSelects();
            if (!empty($jsonSelects)) {
                $this->selectQueryBuilder->select($jsonSelects);
                // Clear JSON selections after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonSelects();
            }

            // Integrate JSON order expressions before executing query
            $jsonOrders = $this->jsonQueryBuilder->getJsonOrders();
            if (!empty($jsonOrders)) {
                foreach ($jsonOrders as $orderExpr) {
                    // JSON order expressions already contain direction, so add them directly
                    $this->selectQueryBuilder->addOrderExpression($orderExpr);
                }
                // Clear JSON orders after integration to avoid duplication
                $this->jsonQueryBuilder->clearJsonOrders();
            }

            // Set CTE manager before building query
            if ($this->cteManager !== null) {
                $this->selectQueryBuilder->setCteManager($this->cteManager);
            }

            $query = $this->selectQueryBuilder->getQuery();
            return $this->batchProcessor->stream($query['sql'], $query['params']);
        } finally {
            $this->restoreConnection($originalConnection);
        }
    }

    /* ---------------- Conditions: where / having / logical variants ---------------- */

    /**
     * Add WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static
    {
        // Handle the old signature: where(array $conditions, array $params)
        if (is_array($exprOrColumn) && is_array($value) && $operator === '=') {
            // This is the old signature: where(['age' => ':age'], ['age' => 30])
            foreach ($exprOrColumn as $column => $placeholder) {
                if (is_string($placeholder) && str_starts_with($placeholder, ':')) {
                    // Add the parameter to the parameter manager with the placeholder name
                    $this->parameterManager->setParam($placeholder, $value[$column] ?? null);
                    // Use whereRaw to handle the placeholder directly
                    $this->conditionBuilder->whereRaw("{$column} = {$placeholder}");
                } else {
                    // Regular array condition
                    $this->conditionBuilder->where($column, $placeholder);
                }
            }
            return $this;
        }

        // Handle the new signature: where(string|array|RawValue $exprOrColumn, mixed $value, string $operator)
        $this->conditionBuilder->where($exprOrColumn, $value, $operator);
        return $this;
    }

    /**
     * Add AND WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static
    {
        $this->conditionBuilder->andWhere($exprOrColumn, $value, $operator);
        return $this;
    }

    /**
     * Add OR WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static
    {
        $this->conditionBuilder->orWhere($exprOrColumn, $value, $operator);
        return $this;
    }

    /**
     * Add HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static
    {
        $this->conditionBuilder->having($exprOrColumn, $value, $operator);
        return $this;
    }

    /**
     * Add OR HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static
    {
        $this->conditionBuilder->orHaving($exprOrColumn, $value, $operator);
        return $this;
    }

    /**
     * Add WHERE IN clause with subquery or array.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilderInterface): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereIn(string $column, callable|array $subqueryOrArray, string $boolean = 'AND'): static
    {
        $this->conditionBuilder->whereIn($column, $subqueryOrArray, $boolean);
        return $this;
    }

    /**
     * Add WHERE NOT IN clause with subquery or array.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilderInterface): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNotIn(string $column, callable|array $subqueryOrArray, string $boolean = 'AND'): static
    {
        $this->conditionBuilder->whereNotIn($column, $subqueryOrArray, $boolean);
        return $this;
    }

    /**
     * Add WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNull(string $column, string $boolean = 'AND'): static
    {
        $this->conditionBuilder->whereNull($column, $boolean);
        return $this;
    }

    /**
     * Add WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNotNull(string $column, string $boolean = 'AND'): static
    {
        $this->conditionBuilder->whereNotNull($column, $boolean);
        return $this;
    }

    /**
     * Add WHERE column BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): static
    {
        $this->conditionBuilder->whereBetween($column, $min, $max, $boolean);
        return $this;
    }

    /**
     * Add WHERE column NOT BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): static
    {
        $this->conditionBuilder->whereNotBetween($column, $min, $max, $boolean);
        return $this;
    }

    /**
     * Add WHERE column comparison with another column.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator (=, !=, <, >, <=, >=, etc.)
     * @param string $second The second column
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): static
    {
        $this->conditionBuilder->whereColumn($first, $operator, $second, $boolean);
        return $this;
    }

    /**
     * Add OR WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function orWhereNull(string $column): static
    {
        $this->conditionBuilder->orWhereNull($column);
        return $this;
    }

    /**
     * Add OR WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function orWhereNotNull(string $column): static
    {
        $this->conditionBuilder->orWhereNotNull($column);
        return $this;
    }

    /**
     * Add OR WHERE column BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function orWhereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->conditionBuilder->orWhereBetween($column, $min, $max);
        return $this;
    }

    /**
     * Add OR WHERE column NOT BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function orWhereNotBetween(string $column, mixed $min, mixed $max): static
    {
        $this->conditionBuilder->orWhereNotBetween($column, $min, $max);
        return $this;
    }

    /**
     * Add OR WHERE column IN clause.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilderInterface): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function orWhereIn(string $column, callable|array $subqueryOrArray): static
    {
        $this->conditionBuilder->orWhereIn($column, $subqueryOrArray);
        return $this;
    }

    /**
     * Add OR WHERE column NOT IN clause.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilderInterface): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function orWhereNotIn(string $column, callable|array $subqueryOrArray): static
    {
        $this->conditionBuilder->orWhereNotIn($column, $subqueryOrArray);
        return $this;
    }

    /**
     * Add AND WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function andWhereNull(string $column): static
    {
        $this->conditionBuilder->andWhereNull($column);
        return $this;
    }

    /**
     * Add AND WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function andWhereNotNull(string $column): static
    {
        $this->conditionBuilder->andWhereNotNull($column);
        return $this;
    }

    /**
     * Add AND WHERE column BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function andWhereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->conditionBuilder->andWhereBetween($column, $min, $max);
        return $this;
    }

    /**
     * Add AND WHERE column NOT BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function andWhereNotBetween(string $column, mixed $min, mixed $max): static
    {
        $this->conditionBuilder->andWhereNotBetween($column, $min, $max);
        return $this;
    }

    /**
     * Add AND WHERE column IN clause.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilderInterface): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function andWhereIn(string $column, callable|array $subqueryOrArray): static
    {
        $this->conditionBuilder->andWhereIn($column, $subqueryOrArray);
        return $this;
    }

    /**
     * Add AND WHERE column NOT IN clause.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilderInterface): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function andWhereNotIn(string $column, callable|array $subqueryOrArray): static
    {
        $this->conditionBuilder->andWhereNotIn($column, $subqueryOrArray);
        return $this;
    }

    /**
     * Add AND WHERE column comparison with another column.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator (=, !=, <, >, <=, >=, etc.)
     * @param string $second The second column
     *
     * @return static The current instance
     */
    public function andWhereColumn(string $first, string $operator, string $second): static
    {
        $this->conditionBuilder->andWhereColumn($first, $operator, $second);
        return $this;
    }

    /**
     * Add OR WHERE column comparison with another column.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator (=, !=, <, >, <=, >=, etc.)
     * @param string $second The second column
     *
     * @return static The current instance
     */
    public function orWhereColumn(string $first, string $operator, string $second): static
    {
        $this->conditionBuilder->orWhereColumn($first, $operator, $second);
        return $this;
    }

    /**
     * Add WHERE EXISTS clause.
     *
     * @param callable(QueryBuilderInterface): void $subquery The subquery callback
     *
     * @return static The current instance
     */
    public function whereExists(callable $subquery): static
    {
        $this->conditionBuilder->whereExists($subquery);
        return $this;
    }

    /**
     * Add WHERE NOT EXISTS clause.
     *
     * @param callable(QueryBuilderInterface): void $subquery The subquery callback
     *
     * @return static The current instance
     */
    public function whereNotExists(callable $subquery): static
    {
        $this->conditionBuilder->whereNotExists($subquery);
        return $this;
    }

    /**
     * Add raw WHERE clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return static The current instance
     */
    public function whereRaw(string $sql, array $params = []): static
    {
        $this->conditionBuilder->whereRaw($sql, $params);
        return $this;
    }

    /**
     * Add raw HAVING clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return static The current instance
     */
    public function havingRaw(string $sql, array $params = []): static
    {
        $this->conditionBuilder->havingRaw($sql, $params);
        return $this;
    }

    /* ---------------- Existence helpers ---------------- */

    /**
     * Return true if at least one row matches the current WHERE conditions.
     *
     * @return bool
     * @throws PDOException
     */
    public function exists(): bool
    {
        return $this->conditionBuilder->exists();
    }

    /**
     * Return true if no rows match the current WHERE conditions.
     *
     * @return bool
     * @throws PDOException
     */
    public function notExists(): bool
    {
        return $this->conditionBuilder->notExists();
    }

    /**
     * Checks if a table exists.
     *
     * @return bool True if the table exists, false otherwise.
     */
    public function tableExists(): bool
    {
        return $this->conditionBuilder->tableExists();
    }

    /* ---------------- Joins ---------------- */

    /**
     * Add JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     * @param string $type JOIN type, e.g. INNER, LEFT, RIGHT
     *
     * @return static The current instance.
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): static
    {
        $this->joinBuilder->join($tableAlias, $condition, $type);
        return $this;
    }

    /**
     * Add LEFT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return static The current instance.
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): static
    {
        $this->joinBuilder->leftJoin($tableAlias, $condition);
        return $this;
    }

    /**
     * Add RIGHT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return static The current instance.
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): static
    {
        $this->joinBuilder->rightJoin($tableAlias, $condition);
        return $this;
    }

    /**
     * Add INNER JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return static The current instance.
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): static
    {
        $this->joinBuilder->innerJoin($tableAlias, $condition);
        return $this;
    }

    /**
     * Add LATERAL JOIN clause.
     *
     * LATERAL JOINs allow correlated subqueries in FROM clause,
     * where the subquery can reference columns from preceding tables.
     *
     * @param string|callable(QueryBuilder): void $tableOrSubquery Table name or callable for subquery
     * @param string|RawValue|null $condition Optional ON condition
     * @param string $type JOIN type (default: LEFT)
     * @param string|null $alias Optional alias for LATERAL subquery
     *
     * @return static
     */
    public function lateralJoin(
        string|callable $tableOrSubquery,
        string|RawValue|null $condition = null,
        string $type = 'LEFT',
        ?string $alias = null
    ): static {
        $this->joinBuilder->lateralJoin($tableOrSubquery, $condition, $type, $alias);
        return $this;
    }

    /* ---------------- Ordering / grouping / pagination / options ---------------- */

    /**
     * Add ORDER BY clause.
     *
     * @param string|array<int|string, string>|RawValue $expr The expression(s) to order by.
     * @param string $direction The direction of the ordering (ASC or DESC).
     *
     * @return static The current instance.
     */
    public function orderBy(string|array|RawValue $expr, string $direction = 'ASC'): static
    {
        $this->selectQueryBuilder->orderBy($expr, $direction);
        return $this;
    }

    /**
     * Add GROUP BY clause.
     *
     * @param string|array<int, string|RawValue>|RawValue $cols The columns to group by.
     *
     * @return static The current instance.
     */
    public function groupBy(string|array|RawValue $cols): static
    {
        $this->selectQueryBuilder->groupBy($cols);
        return $this;
    }

    /**
     * Enable caching for this query.
     *
     * @param int $ttl Time-to-live in seconds
     * @param string|null $key Custom cache key (null = auto-generate)
     *
     * @return static The current instance.
     */
    public function cache(int $ttl = 3600, ?string $key = null): static
    {
        $this->selectQueryBuilder->cache($ttl, $key);
        return $this;
    }

    /**
     * Disable caching for this query.
     *
     * @return static The current instance.
     */
    public function noCache(): static
    {
        $this->selectQueryBuilder->noCache();
        return $this;
    }

    /**
     * Add LIMIT clause.
     *
     * @param int $number The number of rows to limit.
     *
     * @return static The current instance.
     */
    public function limit(int $number): static
    {
        $this->selectQueryBuilder->limit($number);
        $this->dmlQueryBuilder->setLimit($number);
        return $this;
    }

    /**
     * Add OFFSET clause.
     *
     * @param int $number The number of rows to offset.
     *
     * @return static The current instance.
     */
    public function offset(int $number): static
    {
        $this->selectQueryBuilder->offset($number);
        return $this;
    }

    /**
     * Sets the query options.
     *
     * @param string|array<int|string, mixed> $options The query options.
     *
     * @return static The current object.
     */
    public function option(string|array $options): static
    {
        $this->selectQueryBuilder->option($options);

        // Also add options for DML operations
        $this->dmlQueryBuilder->addOption($options);

        return $this;
    }

    /**
     * Set fetch mode to return objects.
     *
     * @return static
     */
    public function asObject(): static
    {
        $this->selectQueryBuilder->asObject();
        return $this;
    }

    /* ---------------- ON DUPLICATE / upsert helpers ---------------- */

    /**
     * Add ON DUPLICATE clause.
     *
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return static The current instance.
     */
    public function onDuplicate(array $onDuplicate): static
    {
        $this->dmlQueryBuilder->onDuplicate($onDuplicate);
        return $this;
    }

    /* ---------------- Introspect ---------------- */

    /**
     * Convert query to SQL string and parameters.
     *
     * @param bool $formatted Whether to format SQL for readability
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(bool $formatted = false): array
    {
        // Set CTE manager before building SQL
        if ($this->cteManager !== null) {
            $this->selectQueryBuilder->setCteManager($this->cteManager);
        }

        return $this->selectQueryBuilder->toSQL($formatted);
    }

    /**
     * Execute EXPLAIN query to analyze query execution plan.
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function explain(): array
    {
        return $this->selectQueryBuilder->explain();
    }

    /**
     * Execute EXPLAIN ANALYZE query (PostgreSQL) or EXPLAIN FORMAT=JSON (MySQL).
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function explainAnalyze(): array
    {
        return $this->selectQueryBuilder->explainAnalyze();
    }

    /**
     * Analyze EXPLAIN output with optimization recommendations.
     *
     * @param string|null $tableName Optional table name for index suggestions
     *
     * @return ExplainAnalysis Analysis result with recommendations
     */
    public function explainAdvice(?string $tableName = null): ExplainAnalysis
    {
        return $this->selectQueryBuilder->explainAdvice($tableName);
    }

    /**
     * Execute DESCRIBE query to get table structure.
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function describe(): array
    {
        return $this->selectQueryBuilder->describe();
    }

    /**
     * Get indexes for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function indexes(): array
    {
        return $this->selectQueryBuilder->indexes();
    }

    /**
     * Get foreign keys for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function keys(): array
    {
        return $this->selectQueryBuilder->keys();
    }

    /**
     * Get constraints for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function constraints(): array
    {
        return $this->selectQueryBuilder->constraints();
    }

    /* ---------------- Execution primitives (pass-through helpers) ---------------- */

    /**
     * Execute statement.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return PDOStatement
     * @throws PDOException
     */
    public function executeStatement(string|RawValue $sql, array $params = []): PDOStatement
    {
        return $this->executionEngine->executeStatement($sql, $params);
    }

    /**
     * Fetch all rows.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function fetchAll(string|RawValue $sql, array $params = []): array
    {
        return $this->executionEngine->fetchAll($sql, $params);
    }

    /**
     * Fetch column.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     * @throws PDOException
     */
    public function fetchColumn(string|RawValue $sql, array $params = []): mixed
    {
        return $this->executionEngine->fetchColumn($sql, $params);
    }

    /**
     * Fetch row.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     * @throws PDOException
     */
    public function fetch(string|RawValue $sql, array $params = []): mixed
    {
        return $this->executionEngine->fetch($sql, $params);
    }

    /* ---------------- CSV / XML loaders ---------------- */

    /**
     * Loads data from a CSV file into a table.
     *
     * @param string $filePath The path to the CSV file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadCsv(string $filePath, array $options = []): bool
    {
        return $this->fileLoader->loadCsv($filePath, $options);
    }

    /**
     * Loads data from an XML file into a table.
     *
     * @param string $filePath The path to the XML file.
     * @param string $rowTag The tag that identifies a row.
     * @param int|null $linesToIgnore The number of lines to ignore at the beginning of the file.
     *
     * @return bool True on success, false on failure.
     */
    public function loadXml(string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool
    {
        return $this->fileLoader->loadXml($filePath, $rowTag, $linesToIgnore);
    }

    /**
     * Loads data from a JSON file into a table.
     *
     * @param string $filePath The path to the JSON file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadJson(string $filePath, array $options = []): bool
    {
        return $this->fileLoader->loadJson($filePath, $options);
    }

    /* ---------------- JSON methods ---------------- */

    /**
     * Add SELECT expression extracting JSON value by path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string|null $alias
     * @param bool $asText
     *
     * @return static
     */
    public function selectJson(string $col, array|string $path, ?string $alias = null, bool $asText = true): static
    {
        $this->jsonQueryBuilder->selectJson($col, $path, $alias, $asText);
        return $this;
    }

    /**
     * Add WHERE condition comparing JSON value at path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $operator
     * @param mixed $value
     * @param string $cond
     *
     * @return static
     */
    public function whereJsonPath(
        string $col,
        array|string $path,
        string $operator,
        mixed $value,
        string $cond = 'AND'
    ): static {
        $this->jsonQueryBuilder->whereJsonPath($col, $path, $operator, $value, $cond);
        return $this;
    }

    /**
     * Add WHERE JSON contains (col contains value).
     *
     * @param string $col
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     * @param string $cond
     *
     * @return static
     */
    public function whereJsonContains(
        string $col,
        mixed $value,
        array|string|null $path = null,
        string $cond = 'AND'
    ): static {
        $this->jsonQueryBuilder->whereJsonContains($col, $value, $path, $cond);
        return $this;
    }

    /**
     * Update JSON field: set value at path (create missing).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return RawValue
     */
    public function jsonSet(string $col, array|string $path, mixed $value): RawValue
    {
        return $this->jsonQueryBuilder->jsonSet($col, $path, $value);
    }

    /**
     * Remove JSON path from column (returns RawValue to use in update).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return RawValue
     */
    public function jsonRemove(string $col, array|string $path): RawValue
    {
        return $this->jsonQueryBuilder->jsonRemove($col, $path);
    }

    /**
     * Add ORDER BY expression based on JSON path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $direction
     *
     * @return static
     */
    public function orderByJson(string $col, array|string $path, string $direction = 'ASC'): static
    {
        $this->jsonQueryBuilder->orderByJson($col, $path, $direction);
        return $this;
    }

    /**
     * Check existence of JSON path (returns boolean condition).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $cond
     *
     * @return static
     */
    public function whereJsonExists(string $col, array|string $path, string $cond = 'AND'): static
    {
        $this->jsonQueryBuilder->whereJsonExists($col, $path, $cond);
        return $this;
    }

    /* ---------------- Pagination methods ---------------- */

    /**
     * Paginate query results with metadata.
     *
     * @param int $perPage
     * @param int|null $page
     * @param array<string, mixed> $options
     *
     * @return pagination\PaginationResult
     * @throws PDOException
     */
    public function paginate(int $perPage = 15, ?int $page = null, array $options = []): pagination\PaginationResult
    {
        // Integrate JSON selections and orders before paginating
        $this->integrateJsonSelectionsAndOrders();

        return $this->selectQueryBuilder->paginate($perPage, $page, $options);
    }

    /**
     * Simple pagination without total count.
     *
     * @param int $perPage
     * @param int|null $page
     * @param array<string, mixed> $options
     *
     * @return pagination\SimplePaginationResult
     * @throws PDOException
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null, array $options = []): pagination\SimplePaginationResult
    {
        // Integrate JSON selections and orders before paginating
        $this->integrateJsonSelectionsAndOrders();

        return $this->selectQueryBuilder->simplePaginate($perPage, $page, $options);
    }

    /**
     * Cursor-based pagination.
     *
     * @param int $perPage
     * @param string|pagination\Cursor|null $cursor
     * @param array<string, mixed> $options
     *
     * @return pagination\CursorPaginationResult
     * @throws PDOException
     */
    public function cursorPaginate(
        int $perPage = 15,
        string|pagination\Cursor|null $cursor = null,
        array $options = []
    ): pagination\CursorPaginationResult {
        // Integrate JSON selections and orders before paginating
        $this->integrateJsonSelectionsAndOrders();

        return $this->selectQueryBuilder->cursorPaginate($perPage, $cursor, $options);
    }

    /**
     * Integrate JSON selections and orders into select query builder.
     */
    protected function integrateJsonSelectionsAndOrders(): void
    {
        // Integrate JSON selections
        $jsonSelects = $this->jsonQueryBuilder->getJsonSelects();
        if (!empty($jsonSelects)) {
            $this->selectQueryBuilder->select($jsonSelects);
            $this->jsonQueryBuilder->clearJsonSelects();
        }

        // Integrate JSON order expressions
        $jsonOrders = $this->jsonQueryBuilder->getJsonOrders();
        if (!empty($jsonOrders)) {
            foreach ($jsonOrders as $orderExpr) {
                $this->selectQueryBuilder->addOrderExpression($orderExpr);
            }
            $this->jsonQueryBuilder->clearJsonOrders();
        }
    }

    /**
     * Get debug information about the current query state.
     *
     * Returns comprehensive information about the query builder state,
     * including table, conditions, joins, parameters, and SQL structure.
     * Useful for debugging and error diagnostics.
     *
     * @return array<string, mixed> Debug information including:
     *                              - table: Table name
     *                              - operation: Query operation type (SELECT, INSERT, UPDATE, DELETE)
     *                              - sql: Generated SQL (if available)
     *                              - params: Query parameters
     *                              - where: WHERE conditions info
     *                              - joins: JOIN information
     *                              - select: SELECT columns
     *                              - order: ORDER BY clauses
     *                              - limit: LIMIT value
     *                              - offset: OFFSET value
     *                              - group: GROUP BY clause
     *                              - having: HAVING conditions
     *                              - dialect: Database dialect name
     *                              - prefix: Table prefix
     */
    public function getDebugInfo(): array
    {
        $info = [
            'dialect' => $this->dialect->getDriverName(),
            'prefix' => $this->prefix,
        ];

        // Get table if set
        try {
            $info['table'] = $this->table;
        } catch (RuntimeException) {
            $info['table'] = null;
        }

        // Get SQL and params if query is built
        try {
            $sqlData = $this->toSQL();
            $info['sql'] = $sqlData['sql'];
            $info['params'] = $sqlData['params'];
            $info['operation'] = $this->extractOperationFromSql($sqlData['sql']);
        } catch (\Throwable) {
            $info['sql'] = null;
            $info['params'] = $this->parameterManager->getParams();
            $info['operation'] = 'UNKNOWN';
        }

        // Get condition builder info
        $whereInfo = $this->conditionBuilder->getDebugInfo();
        if (!empty($whereInfo)) {
            $info['where'] = $whereInfo;
        }

        // Get join info
        $joinInfo = $this->joinBuilder->getDebugInfo();
        if (!empty($joinInfo)) {
            $info['joins'] = $joinInfo;
        }

        // Get select query builder info
        $selectInfo = $this->selectQueryBuilder->getDebugInfo();
        if (!empty($selectInfo)) {
            $info = array_merge($info, $selectInfo);
        }

        // Get DML query builder info
        $dmlInfo = $this->dmlQueryBuilder->getDebugInfo();
        if (!empty($dmlInfo)) {
            $info = array_merge($info, $dmlInfo);
        }

        return $info;
    }

    /**
     * Extract operation type from SQL string.
     */
    protected function extractOperationFromSql(string $sql): string
    {
        $sql = trim($sql);
        $firstToken = strtok($sql, ' ');
        if ($firstToken === false) {
            return 'UNKNOWN';
        }
        $firstWord = strtoupper($firstToken);

        return match ($firstWord) {
            'SELECT' => 'SELECT',
            'INSERT' => 'INSERT',
            'UPDATE' => 'UPDATE',
            'DELETE' => 'DELETE',
            'REPLACE' => 'REPLACE',
            'MERGE' => 'MERGE',
            default => 'UNKNOWN',
        };
    }

    /* ---------------- Macros ---------------- */

    /**
     * Register a custom query method macro.
     *
     * Macros allow you to extend QueryBuilder with custom methods.
     * The macro callable receives the QueryBuilder instance as the first argument,
     * followed by any additional arguments passed when calling the macro.
     *
     * @param string $name Macro name (method name)
     * @param callable $macro Macro callable that accepts QueryBuilder and optional arguments
     *
     * @phpstan-param callable(QueryBuilder, mixed...): mixed $macro
     */
    public static function macro(string $name, callable $macro): void
    {
        MacroRegistry::register($name, $macro);
    }

    /**
     * Check if a macro exists.
     *
     * @param string $name Macro name
     *
     * @return bool True if macro exists, false otherwise
     */
    public static function hasMacro(string $name): bool
    {
        return MacroRegistry::has($name);
    }

    /**
     * Get table name.
     *
     * @return string|null Table name or null if not set
     */
    public function getTableName(): ?string
    {
        try {
            return $this->table;
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Extract shard key value from WHERE conditions.
     *
     * @param string $shardKeyColumn Column name to search for
     *
     * @return mixed|null Shard key value or null if not found
     */
    public function extractShardKeyValue(string $shardKeyColumn): mixed
    {
        return $this->conditionBuilder->extractShardKeyValue($shardKeyColumn);
    }

    /**
     * Handle dynamic method calls.
     *
     * If the method doesn't exist on QueryBuilder, it checks for registered macros
     * and executes them if found. Otherwise, throws a RuntimeException.
     *
     * @param string $name Method name
     * @param array<mixed> $arguments Method arguments
     *
     * @return mixed Result of macro execution or QueryBuilder instance for chaining
     * @throws RuntimeException If method doesn't exist and is not a registered macro
     */
    public function __call(string $name, array $arguments): mixed
    {
        $macro = MacroRegistry::get($name);

        if ($macro !== null) {
            $result = $macro($this, ...$arguments);

            // If macro returns QueryBuilder instance, return it for chaining
            // Otherwise, return the result as-is
            return $result instanceof static ? $result : $result;
        }

        throw new RuntimeException(
            'Call to undefined method ' . static::class . '::' . $name . '(). ' .
            'If you intended to call a macro, make sure it is registered using QueryBuilder::macro().'
        );
    }
}
