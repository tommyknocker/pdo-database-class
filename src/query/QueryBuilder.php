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
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
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

    /** @var ConnectionRouter|null Connection router for read/write splitting */
    protected ?ConnectionRouter $connectionRouter = null;

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
     */
    public function __construct(
        ConnectionInterface $connection,
        string $prefix = '',
        ?CacheManager $cacheManager = null
    ) {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->prefix = $prefix;
        $this->cacheManager = $cacheManager;

        // Initialize components with shared parameter manager and raw value resolver
        $this->parameterManager = new ParameterManager();
        $rawValueResolver = new RawValueResolver($connection, $this->parameterManager);
        $this->executionEngine = new ExecutionEngine($connection, $rawValueResolver, $this->parameterManager);
        $this->conditionBuilder = new ConditionBuilder(
            $connection,
            $this->parameterManager,
            $this->executionEngine,
            $rawValueResolver
        );
        $this->joinBuilder = new JoinBuilder($connection, $rawValueResolver);
        $this->selectQueryBuilder = new SelectQueryBuilder(
            $connection,
            $this->parameterManager,
            $this->executionEngine,
            $this->conditionBuilder,
            $this->joinBuilder,
            $rawValueResolver,
            $cacheManager
        );
        $this->dmlQueryBuilder = new DmlQueryBuilder(
            $connection,
            $this->parameterManager,
            $this->executionEngine,
            $this->conditionBuilder,
            $rawValueResolver
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
     * @return self
     */
    public function setConnectionRouter(?ConnectionRouter $router): self
    {
        $this->connectionRouter = $router;
        return $this;
    }

    /**
     * Force next read query to use write connection.
     *
     * @return self
     */
    public function forceWrite(): self
    {
        $this->forceWriteConnection = true;
        return $this;
    }

    /**
     * Switch to read connection if router is available.
     *
     * @return ConnectionInterface|null Original connection before switch
     */
    protected function switchToReadConnection(): ?ConnectionInterface
    {
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
     * @return self The current instance.
     */
    public function table(string $table): self
    {
        return $this->from($table);
    }

    /**
     * Sets the table to query.
     *
     * @param string $table The table to query.
     *
     * @return self The current instance.
     */
    public function from(string $table): self
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
     * @return self The current instance.
     */
    public function with(
        string $name,
        QueryBuilder|Closure|string|RawValue $query,
        array $columns = []
    ): self {
        if ($this->cteManager === null) {
            $this->cteManager = new CteManager($this->connection);
        }

        // Convert RawValue to string
        if ($query instanceof RawValue) {
            $query = $query->getValue();
        }

        $cte = new CteDefinition($name, $query, false, $columns);
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
     * @return self The current instance.
     */
    public function withRecursive(
        string $name,
        QueryBuilder|Closure|string|RawValue $query,
        array $columns = []
    ): self {
        if ($this->cteManager === null) {
            $this->cteManager = new CteManager($this->connection);
        }

        // Convert RawValue to string
        if ($query instanceof RawValue) {
            $query = $query->getValue();
        }

        $cte = new CteDefinition($name, $query, true, $columns);
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
     * @return self The current instance.
     */
    public function union(QueryBuilder|Closure $query): self
    {
        $this->unions[] = new UnionQuery('UNION', $query);
        return $this;
    }

    /**
     * Add UNION ALL operation.
     *
     * @param QueryBuilder|Closure(QueryBuilder): void $query Query to union.
     *
     * @return self The current instance.
     */
    public function unionAll(QueryBuilder|Closure $query): self
    {
        $this->unions[] = new UnionQuery('UNION ALL', $query);
        return $this;
    }

    /**
     * Add INTERSECT operation.
     *
     * @param QueryBuilder|Closure(QueryBuilder): void $query Query to intersect.
     *
     * @return self The current instance.
     */
    public function intersect(QueryBuilder|Closure $query): self
    {
        $this->unions[] = new UnionQuery('INTERSECT', $query);
        return $this;
    }

    /**
     * Add EXCEPT operation.
     *
     * @param QueryBuilder|Closure(QueryBuilder): void $query Query to except.
     *
     * @return self The current instance.
     */
    public function except(QueryBuilder|Closure $query): self
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
     * @return self The current instance.
     */
    public function distinct(): self
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
     * @return self The current instance.
     * @throws RuntimeException If database does not support DISTINCT ON.
     */
    public function distinctOn(string|array $columns): self
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
     * Sets the prefix for table names.
     *
     * @param string $prefix The prefix for table names.
     *
     * @return self The current instance.
     */
    public function prefix(string $prefix): self
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
     * @return self The current instance.
     */
    public function select(RawValue|callable|string|array $cols): self
    {
        $this->selectQueryBuilder->select($cols);
        return $this;
    }

    /**
     * Execute SELECT statement and return all rows.
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function get(): array
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
     * @return self The current instance.
     */
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
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
     * @return self The current instance.
     */
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
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
     * @return self The current instance.
     */
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
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
     * @return self The current instance.
     */
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
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
     * @return self The current instance.
     */
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        $this->conditionBuilder->orHaving($exprOrColumn, $value, $operator);
        return $this;
    }

    /**
     * Add WHERE IN clause with subquery.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilderInterface): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereIn(string $column, callable $subquery): self
    {
        $this->conditionBuilder->whereIn($column, $subquery);
        return $this;
    }

    /**
     * Add WHERE NOT IN clause with subquery.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilderInterface): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereNotIn(string $column, callable $subquery): self
    {
        $this->conditionBuilder->whereNotIn($column, $subquery);
        return $this;
    }

    /**
     * Add WHERE EXISTS clause.
     *
     * @param callable(QueryBuilderInterface): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereExists(callable $subquery): self
    {
        $this->conditionBuilder->whereExists($subquery);
        return $this;
    }

    /**
     * Add WHERE NOT EXISTS clause.
     *
     * @param callable(QueryBuilderInterface): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereNotExists(callable $subquery): self
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
     * @return self The current instance
     */
    public function whereRaw(string $sql, array $params = []): self
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
     * @return self The current instance
     */
    public function havingRaw(string $sql, array $params = []): self
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
     * @return self The current instance.
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): self
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
     * @return self The current instance.
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): self
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
     * @return self The current instance.
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): self
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
     * @return self The current instance.
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): self
    {
        $this->joinBuilder->innerJoin($tableAlias, $condition);
        return $this;
    }

    /* ---------------- Ordering / grouping / pagination / options ---------------- */

    /**
     * Add ORDER BY clause.
     *
     * @param string|array<int|string, string>|RawValue $expr The expression(s) to order by.
     * @param string $direction The direction of the ordering (ASC or DESC).
     *
     * @return self The current instance.
     */
    public function orderBy(string|array|RawValue $expr, string $direction = 'ASC'): self
    {
        $this->selectQueryBuilder->orderBy($expr, $direction);
        return $this;
    }

    /**
     * Add GROUP BY clause.
     *
     * @param string|array<int, string|RawValue>|RawValue $cols The columns to group by.
     *
     * @return self The current instance.
     */
    public function groupBy(string|array|RawValue $cols): self
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
     * @return self The current instance.
     */
    public function cache(int $ttl = 3600, ?string $key = null): self
    {
        $this->selectQueryBuilder->cache($ttl, $key);
        return $this;
    }

    /**
     * Disable caching for this query.
     *
     * @return self The current instance.
     */
    public function noCache(): self
    {
        $this->selectQueryBuilder->noCache();
        return $this;
    }

    /**
     * Add LIMIT clause.
     *
     * @param int $number The number of rows to limit.
     *
     * @return self The current instance.
     */
    public function limit(int $number): self
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
     * @return self The current instance.
     */
    public function offset(int $number): self
    {
        $this->selectQueryBuilder->offset($number);
        return $this;
    }

    /**
     * Sets the query options.
     *
     * @param string|array<int|string, mixed> $options The query options.
     *
     * @return self The current object.
     */
    public function option(string|array $options): self
    {
        $this->selectQueryBuilder->option($options);

        // Also add options for DML operations
        $this->dmlQueryBuilder->addOption($options);

        return $this;
    }

    /**
     * Set fetch mode to return objects.
     *
     * @return self
     */
    public function asObject(): self
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
     * @return self The current instance.
     */
    public function onDuplicate(array $onDuplicate): self
    {
        $this->dmlQueryBuilder->onDuplicate($onDuplicate);
        return $this;
    }

    /* ---------------- Introspect ---------------- */

    /**
     * Convert query to SQL string and parameters.
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(): array
    {
        // Set CTE manager before building SQL
        if ($this->cteManager !== null) {
            $this->selectQueryBuilder->setCteManager($this->cteManager);
        }

        return $this->selectQueryBuilder->toSQL();
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
     * @return self
     */
    public function selectJson(string $col, array|string $path, ?string $alias = null, bool $asText = true): self
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
     * @return self
     */
    public function whereJsonPath(
        string $col,
        array|string $path,
        string $operator,
        mixed $value,
        string $cond = 'AND'
    ): self {
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
     * @return self
     */
    public function whereJsonContains(
        string $col,
        mixed $value,
        array|string|null $path = null,
        string $cond = 'AND'
    ): self {
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
     * @return self
     */
    public function orderByJson(string $col, array|string $path, string $direction = 'ASC'): self
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
     * @return self
     */
    public function whereJsonExists(string $col, array|string $path, string $cond = 'AND'): self
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
}
