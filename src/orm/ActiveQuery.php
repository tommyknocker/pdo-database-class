<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use RuntimeException;
use tommyknocker\pdodb\orm\relations\EagerLoader;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * ActiveQuery provides a query builder interface for ActiveRecord models.
 *
 * This class wraps QueryBuilder and adds methods to return model instances
 * instead of raw arrays.
 */
class ActiveQuery
{
    /** @var string Model class name */
    protected string $modelClass;

    /** @var QueryBuilder QueryBuilder instance */
    protected QueryBuilder $queryBuilder;

    /** @var array<int|string, string|array<string>> Relationships to eager load */
    protected array $eagerLoad = [];

    /** @var bool Whether global scopes have been applied */
    protected bool $globalScopesApplied = false;

    /**
     * ActiveQuery constructor.
     *
     * @param string $modelClass Model class name
     */
    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
        $db = $modelClass::getDb();
        if ($db === null) {
            throw new RuntimeException('Database connection not set. Use Model::setDb() to set connection.');
        }

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

        $globalScopes = $this->modelClass::globalScopes();

        foreach ($globalScopes as $scopeName => $scope) {
            // Skip if this scope is disabled
            if ($this->queryBuilder->isGlobalScopeDisabled($scopeName)) {
                continue;
            }

            // Apply the scope
            $this->queryBuilder->scope($scope);
        }

        $this->globalScopesApplied = true;
    }

    /**
     * Ensure global scopes are applied before query execution.
     */
    protected function ensureGlobalScopes(): void
    {
        $this->applyGlobalScopes();
    }

    /**
     * Delegate all QueryBuilder methods.
     *
     * @param string $name Method name
     * @param array<mixed> $arguments Method arguments
     *
     * @return static ActiveQuery instance for chaining
     */
    public function __call(string $name, array $arguments): static
    {
        // Handle scope() method specially - support named scopes from model
        if ($name === 'scope') {
            return $this->applyScope($arguments);
        }

        $result = $this->queryBuilder->$name(...$arguments);

        // If QueryBuilder method returns self, we return self
        if ($result === $this->queryBuilder) {
            return $this;
        }

        // For methods that return other types, return as-is
        // This allows accessing QueryBuilder methods directly if needed
        return $this;
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
     * @return Model|null Model instance or null if not found
     */
    public function one(): ?Model
    {
        $this->ensureGlobalScopes();
        $data = $this->queryBuilder->getOne();
        if (!is_array($data)) {
            return null;
        }

        $model = $this->populateModel($data);

        // Eager load relationships if requested
        if (!empty($this->eagerLoad)) {
            $this->loadRelations([$model], $this->eagerLoad);
        }

        return $model;
    }

    /**
     * Get all model instances.
     * Uses QueryBuilder::get() internally.
     *
     * @return array<int, Model> Array of model instances
     */
    public function all(): array
    {
        $this->ensureGlobalScopes();
        $data = $this->queryBuilder->get();
        $models = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $models[] = $this->populateModel($row);
            }
        }

        // Eager load relationships if requested
        if (!empty($this->eagerLoad) && !empty($models)) {
            $this->loadRelations($models, $this->eagerLoad);
        }

        return $models;
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
     * @return Model Model instance
     */
    protected function populateModel(array $data): Model
    {
        /** @var Model $model */
        $model = new $this->modelClass();
        $model->populate($data);

        // Use reflection to set protected properties
        $reflection = new \ReflectionClass($model);
        $isNewRecordProp = $reflection->getProperty('isNewRecord');
        $isNewRecordProp->setAccessible(true);
        $isNewRecordProp->setValue($model, false);

        $oldAttributesProp = $reflection->getProperty('oldAttributes');
        $oldAttributesProp->setAccessible(true);
        $oldAttributesProp->setValue($model, $data);

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
     * Load relationships for models.
     *
     * @param array<int, Model> $models Array of model instances
     * @param array<int|string, string|array<string>> $relations Relationships to load
     */
    protected function loadRelations(array $models, array $relations): void
    {
        if (empty($models) || empty($relations)) {
            return;
        }

        $loader = new EagerLoader();
        $loader->load($models, $relations);
    }
}
