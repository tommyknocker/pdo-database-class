<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm;

use RuntimeException;
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
     * Delegate all QueryBuilder methods.
     *
     * @param string $name Method name
     * @param array<mixed> $arguments Method arguments
     *
     * @return static ActiveQuery instance for chaining
     */
    public function __call(string $name, array $arguments): static
    {
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
     * Get one model instance.
     * Uses QueryBuilder::getOne() internally.
     *
     * @return Model|null Model instance or null if not found
     */
    public function one(): ?Model
    {
        $data = $this->queryBuilder->getOne();
        if (!is_array($data)) {
            return null;
        }

        return $this->populateModel($data);
    }

    /**
     * Get all model instances.
     * Uses QueryBuilder::get() internally.
     *
     * @return array<int, Model> Array of model instances
     */
    public function all(): array
    {
        $data = $this->queryBuilder->get();
        $models = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $models[] = $this->populateModel($row);
            }
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
        return $this->queryBuilder->get();
    }

    /**
     * Get raw QueryBuilder result (single array).
     * Direct access to QueryBuilder::getOne().
     *
     * @return mixed Raw query result (array or null)
     */
    public function getOne(): mixed
    {
        return $this->queryBuilder->getOne();
    }

    /**
     * Get column values.
     * Direct access to QueryBuilder::getColumn().
     *
     * @return array<int, mixed> Column values
     */
    public function getColumn(): array
    {
        return $this->queryBuilder->getColumn();
    }

    /**
     * Get single value.
     * Direct access to QueryBuilder::getValue().
     *
     * @return mixed Single value or null
     */
    public function getValue(): mixed
    {
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
}
