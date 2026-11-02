<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use Generator;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\orm\relations\EagerLoader;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\pagination\Cursor;
use tommyknocker\pdodb\query\pagination\CursorPaginationResult;
use tommyknocker\pdodb\query\pagination\PaginationResult;
use tommyknocker\pdodb\query\pagination\SimplePaginationResult;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * ActiveQuery provides a query builder interface for ActiveRecord models.
 *
 * This class wraps QueryBuilder and adds methods to return model instances
 * instead of raw arrays.
 *
 * @template TModel of Model
 *
 * @mixin QueryBuilder
 */
class ActiveQuery
{
    /** @var class-string<TModel> Model class name */
    protected string $modelClass;
    protected PdoDb $database;
    protected ConnectionInterface $boundConnection;
    protected bool $explicitConnection;

    /** @var QueryBuilder QueryBuilder instance */
    protected QueryBuilder $queryBuilder;

    /** @var array<int|string, string|array<string>> Relationships to eager load */
    protected array $eagerLoad = [];

    /** @var array<int|string, string|callable(ActiveQuery<Model>): void> Relations whose rows are counted: name, or name => constraint */
    protected array $countRelations = [];

    /** @var bool Whether global scopes have been applied */
    protected bool $globalScopesApplied = false;

    /** @var array<string, string|int|float|bool|null> */
    protected array $queryParameters = [];

    /**
     * ActiveQuery constructor.
     *
     * @param class-string<TModel> $modelClass Model class name
     */
    public function __construct(string $modelClass, ?PdoDb $db = null)
    {
        $this->modelClass = $modelClass;
        $this->explicitConnection = $db !== null;
        $db ??= $modelClass::getDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

        $this->database = $db;
        $this->boundConnection = $db->connection;
        $tableName = $modelClass::tableName();
        $this->queryBuilder = $db->find()->from($tableName);
    }

    /**
     * Apply global scopes defined in the model.
     * Called lazily when query is executed.
     */
    protected function applyGlobalScopes(): void
    {
        // Only apply once
        if ($this->globalScopesApplied) {
            return;
        }

        $globalScopes = $this->modelClass::resolvedGlobalScopes();

        foreach ($globalScopes as $scopeName => $scope) {
            // Skip if this scope is disabled
            if ($this->queryBuilder->isGlobalScopeDisabled($scopeName)) {
                continue;
            }

            // Apply the scope
            $this->queryBuilder->isolatedScope($scope);
        }

        $this->globalScopesApplied = true;
    }

    /**
     * Ensure global scopes are applied before query execution.
     */
    protected function ensureGlobalScopes(): void
    {
        if ($this->database->connection !== $this->boundConnection) {
            throw new RuntimeException('The query connection changed after it was created.');
        }
        $parameters = $this->queryBuilder->getParameterManager();
        $current = $parameters->getParams();
        foreach ($this->queryParameters as $name => $value) {
            if (!array_key_exists($name, $current)) {
                $parameters->setParam($name, $value);
            }
        }
        $this->applyGlobalScopes();
        $this->queryParameters = $parameters->getParams();
    }

    /**
     * Delegate all QueryBuilder methods.
     *
     * @param string $name Method name
     * @param array<mixed> $arguments Method arguments
     *
     * @return mixed ActiveQuery for fluent methods, otherwise the delegated result
     */
    public function __call(string $name, array $arguments): mixed
    {
        // Handle scope() method specially - support named scopes from model
        if ($name === 'scope') {
            return $this->applyScope($arguments);
        }

        if (in_array($name, ['truncate', 'replace', 'replaceMulti', 'merge'], true)) {
            foreach ($this->modelClass::resolvedGlobalScopes() as $scopeName => $_scope) {
                if (!$this->queryBuilder->isGlobalScopeDisabled($scopeName)) {
                    throw new RuntimeException("Cannot use {$name} while a global scope is enabled; disable it explicitly if intended.");
                }
            }
        }
        if (in_array($name, ['exists', 'notExists', 'first', 'last', 'batch', 'stream', 'update', 'delete', 'toSQL', 'explain', 'explainAnalyze',
            'explainAdvice', 'explainAiAdvice', 'explainAiContext'], true)) {
            $this->ensureGlobalScopes();
        }
        $result = $this->queryBuilder->$name(...$arguments);

        // If QueryBuilder method returns self, we return self
        if ($result === $this->queryBuilder) {
            return $this;
        }

        return $result;
    }

    /**
     * Apply a scope (named or callable).
     *
     * @param array<mixed> $arguments Scope name or callable, plus any additional arguments
     *
     * @return static ActiveQuery instance for chaining
     */
    protected function applyScope(array $arguments): static
    {
        if (empty($arguments)) {
            throw new RuntimeException('scope() method requires at least one argument.');
        }

        $scope = $arguments[0];
        $scopeArgs = array_slice($arguments, 1);

        // If it's a string, try to find it in model's local scopes
        if (is_string($scope)) {
            $localScopes = $this->modelClass::scopes();
            if (isset($localScopes[$scope])) {
                $this->queryBuilder->scope($localScopes[$scope], ...$scopeArgs);
                return $this;
            }

            // If not found in local scopes, try as callable name (for QueryBuilder)
            throw new RuntimeException(
                "Scope '{$scope}' not found in model " . $this->modelClass . '. ' .
                'Define it in scopes() method or use a callable.'
            );
        }

        // If it's a callable, apply it directly
        if (is_callable($scope)) {
            $this->queryBuilder->scope($scope, ...$scopeArgs);
            return $this;
        }

        throw new RuntimeException('scope() method requires a string (scope name) or callable.');
    }

    /**
     * Get one model instance.
     * Uses QueryBuilder::getOne() internally.
     *
     * @return TModel|null Model instance or null if not found
     */
    public function one(): ?Model
    {
        $this->ensureGlobalScopes();
        $this->modelClass::validateCastConnection($this->database);
        $data = $this->queryBuilder->getOne();
        if (!is_array($data)) {
            return null;
        }

        $model = $this->populateModel($data);

        // Eager load relationships if requested
        $this->loadRelated([$model]);

        return $model;
    }

    /**
     * Get all model instances.
     * Uses QueryBuilder::get() internally.
     *
     * @return array<int, TModel> Array of model instances
     */
    public function all(): array
    {
        $this->ensureGlobalScopes();
        $this->modelClass::validateCastConnection($this->database);
        $data = $this->queryBuilder->get();
        $models = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $models[] = $this->populateModel($row);
            }
        }

        // Eager load relationships if requested
        $this->loadRelated($models);

        return $models;
    }

    /**
     * Yield model instances in database batches.
     *
     * Eager relations configured with with() are loaded once per batch.
     * QueryBuilder::each() still returns raw rows; call getQueryBuilder()->each()
     * when the rows should stay arrays.
     *
     * @param string|null $key Follow this unique, increasing column instead of an OFFSET, so rows may change while
     *                         iterating; the query must then have no ORDER BY of its own
     *
     * @return Generator<int, TModel>
     */
    public function each(int $batchSize = 100, ?string $key = null): Generator
    {
        $this->ensureGlobalScopes();
        $this->modelClass::validateCastConnection($this->database);
        foreach ($this->queryBuilder->batch($batchSize, $key) as $rows) {
            $models = $this->modelsFromRows($rows);
            foreach ($models as $model) {
                yield $model;
            }
        }
    }

    /**
     * Pass each batch of models to a callback. Returning false stops iteration.
     *
     * @param callable(array<int, TModel>): (bool|null) $callback
     */
    public function chunk(int $count, callable $callback): void
    {
        if ($count < 1) {
            throw new RuntimeException('Chunk size must be at least 1.');
        }

        $this->ensureGlobalScopes();
        $this->modelClass::validateCastConnection($this->database);
        foreach ($this->queryBuilder->batch($count) as $rows) {
            $models = $this->modelsFromRows($rows);
            if ($models === []) {
                continue;
            }
            if ($callback($models) === false) {
                return;
            }
        }
    }

    /**
     * Stream one model at a time.
     *
     * Relations requested with with() are loaded for each model separately.
     * Prefer each() or chunk() when those relations should be loaded in batches.
     *
     * @return Generator<int, TModel>
     */
    public function cursor(): Generator
    {
        $this->ensureGlobalScopes();
        $this->modelClass::validateCastConnection($this->database);
        foreach ($this->queryBuilder->stream() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $model = $this->populateModel($row);
            $this->loadRelated([$model]);
            yield $model;
        }
    }

    /**
     * @param mixed $rows
     *
     * @return array<int, TModel>
     */
    protected function modelsFromRows(mixed $rows): array
    {
        $models = [];
        if (!is_array($rows)) {
            return $models;
        }
        foreach ($rows as $row) {
            if (is_array($row)) {
                $models[] = $this->populateModel($row);
            }
        }
        $this->loadRelated($models);

        return $models;
    }

    /**
     * Paginate raw query results with model global scopes applied.
     *
     * @param array<string, mixed> $options
     */
    public function paginate(int $perPage = 15, ?int $page = null, array $options = []): PaginationResult
    {
        $this->ensureGlobalScopes();
        return $this->queryBuilder->paginate($perPage, $page, $options);
    }

    /**
     * Paginate models and eager-load requested relations without changing paginate().
     *
     * @param array<string, mixed> $options
     *
     * @return ModelPage<TModel>
     */
    public function paginateModels(int $perPage = 15, ?int $page = null, array $options = []): ModelPage
    {
        $this->ensureGlobalScopes();
        $this->modelClass::validateCastConnection($this->database);
        $pagination = $this->queryBuilder->paginate($perPage, $page, $options);
        $models = [];
        foreach ($pagination->items() as $row) {
            $models[] = $this->populateModel($row);
        }
        $this->loadRelated($models);
        return new ModelPage($models, $pagination);
    }

    /** @param array<string, mixed> $options */
    public function simplePaginate(int $perPage = 15, ?int $page = null, array $options = []): SimplePaginationResult
    {
        $this->ensureGlobalScopes();
        return $this->queryBuilder->simplePaginate($perPage, $page, $options);
    }

    /** @param array<string, mixed> $options */
    public function cursorPaginate(int $perPage = 15, string|Cursor|null $cursor = null, array $options = []): CursorPaginationResult
    {
        $this->ensureGlobalScopes();
        return $this->queryBuilder->cursorPaginate($perPage, $cursor, $options);
    }

    /**
     * Get raw QueryBuilder result (array of arrays).
     * Direct access to QueryBuilder::get().
     *
     * @return array<int, array<string, mixed>> Raw query results
     */
    public function get(): array
    {
        $this->ensureGlobalScopes();
        $this->modelClass::validateCastConnection($this->database);
        return array_values($this->queryBuilder->get());
    }

    /**
     * Get raw QueryBuilder result (single array).
     * Direct access to QueryBuilder::getOne().
     *
     * @return mixed Raw query result (array or null)
     */
    public function getOne(): mixed
    {
        $this->ensureGlobalScopes();
        return $this->queryBuilder->getOne();
    }

    /**
     * Get column values.
     * Direct access to QueryBuilder::getColumn().
     *
     * @param string|null $name Column name to extract (optional, uses first column from select() if not provided)
     *
     * @return array<int, mixed> Column values
     */
    public function getColumn(?string $name = null): array
    {
        $this->ensureGlobalScopes();
        return $this->queryBuilder->getColumn($name);
    }

    /**
     * Get single value.
     * Direct access to QueryBuilder::getValue().
     *
     * @return mixed Single value or null
     */
    public function getValue(): mixed
    {
        $this->ensureGlobalScopes();
        return $this->queryBuilder->getValue();
    }

    /**
     * Populate model from data array.
     *
     * @param array<string, mixed> $data Data array
     *
     * @return TModel Model instance
     */
    protected function populateModel(array $data): Model
    {
        /** @var TModel $model */
        $model = new $this->modelClass();
        $model->bindConnection($this->database, $this->explicitConnection);
        $model->hydrate($data);

        return $model;
    }

    /**
     * Get QueryBuilder instance (for advanced usage).
     *
     * @return QueryBuilder QueryBuilder instance
     */
    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    /**
     * Temporarily disable a global scope.
     *
     * @param string $scopeName Name of the global scope to disable
     *
     * @return static ActiveQuery instance for chaining
     */
    public function withoutGlobalScope(string $scopeName): static
    {
        $this->queryBuilder->withoutGlobalScope($scopeName);
        return $this;
    }

    /**
     * Temporarily disable multiple global scopes.
     *
     * @param array<string> $scopeNames Names of the global scopes to disable
     *
     * @return static ActiveQuery instance for chaining
     */
    public function withoutGlobalScopes(array $scopeNames): static
    {
        $this->queryBuilder->withoutGlobalScopes($scopeNames);
        return $this;
    }

    /**
     * Add the number of related rows as `{relation}_count` to each model, with one query per relation.
     *
     * ```php
     * $users = User::find()->withCount('posts')->all();
     * echo $users[0]->posts_count;
     *
     * // count only some of the related rows
     * User::find()->withCount(['posts' => fn ($query) => $query->where('published', 1)])->all();
     * ```
     *
     * A constraint closure works for has-many and has-one relations. The count is not an attribute of the table: it is
     * readable on the model but never saved.
     *
     * @param array<int|string, string|callable(ActiveQuery<Model>): void>|string $relations Relation name, or
     *                                                                                       name => constraint closure
     *
     * @return static Self instance for chaining
     */
    public function withCount(array|string $relations): static
    {
        foreach ((array)$relations as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('withCount() takes relation names, or relation name => constraint closure.');
                }
                $this->countRelations[] = $value;
            } else {
                $this->countRelations[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Specify relationships to eager load.
     *
     * @param array<int|string, string|array<string>>|string $relations Relationship name(s) or array of relationships
     *                                                                  Supports nested: ['posts' => ['author', 'comments']]
     *
     * @return static Self instance for chaining
     */
    public function with(array|string $relations): static
    {
        if (is_string($relations)) {
            $this->eagerLoad[] = $relations;
        } else {
            $this->eagerLoad = array_merge($this->eagerLoad, $relations);
        }

        return $this;
    }

    /**
     * Load what the query asked for next to its rows: eager-loaded relations and relation counts.
     *
     * @param array<int, TModel> $models
     */
    protected function loadRelated(array $models): void
    {
        if ($models === []) {
            return;
        }

        $loader = new EagerLoader();
        if ($this->eagerLoad !== []) {
            $loader->load($models, $this->eagerLoad);
        }
        if ($this->countRelations !== []) {
            $loader->loadCounts($models, $this->countRelations);
        }
    }
}
