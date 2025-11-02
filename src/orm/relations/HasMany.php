<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use RuntimeException;
use tommyknocker\pdodb\orm\ActiveQuery;
use tommyknocker\pdodb\orm\Model;

/**
 * HasMany relationship.
 *
 * Represents a one-to-many relationship where the current model has many related models.
 * The foreign key is stored in the related model's table.
 */
class HasMany implements RelationInterface
{
    /** @var string Related model class name */
    protected string $modelClass;

    /** @var string Foreign key column in related model's table */
    protected string $foreignKey;

    /** @var string Local key column in owner model's table (usually primary key) */
    protected string $localKey;

    /** @var Model|null Owner model instance */
    protected ?Model $model = null;

    /**
     * HasMany constructor.
     *
     * @param string $modelClass Related model class name
     * @param array<string, mixed> $config Relationship configuration
     *                                     - 'foreignKey': Foreign key column (default: '{modelName}_id')
     *                                     - 'localKey': Local key column (default: primary key)
     */
    public function __construct(string $modelClass, array $config = [])
    {
        $this->modelClass = $modelClass;

        // Auto-detect foreign key if not specified
        if (!isset($config['foreignKey'])) {
            // Extract owner model name from calling context
            // This will be resolved when setModel() is called
            $this->foreignKey = ''; // Will be set in setModel() if needed
        } else {
            $this->foreignKey = (string)$config['foreignKey'];
        }

        // Auto-detect local key (primary key) if not specified
        if (!isset($config['localKey'])) {
            $this->localKey = 'id'; // Default to 'id', will be resolved when model is set
        } else {
            $this->localKey = (string)$config['localKey'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;

        // Resolve foreign key if not specified
        if ($this->foreignKey === '') {
            $ownerTable = $model::tableName();
            $ownerTable = preg_replace('/s$/', '', $ownerTable); // Remove plural 's'
            $this->foreignKey = $ownerTable . '_id';
        }

        // Resolve local key from primary key if using default
        if ($this->localKey === 'id') {
            $pk = $model::primaryKey();
            if (!empty($pk)) {
                $this->localKey = $pk[0];
            }
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getModel(): ?Model
    {
        return $this->model;
    }

    /**
     * {@inheritDoc}
     */
    public function getValue(): mixed
    {
        if ($this->model === null) {
            throw new RuntimeException('Model instance must be set before accessing relationship.');
        }

        $localValue = $this->model->{$this->localKey} ?? null;
        if ($localValue === null) {
            return [];
        }

        $relatedModelClass = $this->modelClass;
        return $relatedModelClass::findAll([$this->foreignKey => $localValue]);
    }

    /**
     * {@inheritDoc}
     */
    public function getEagerValue(mixed $data): mixed
    {
        if ($data === null || (is_array($data) && empty($data))) {
            return [];
        }

        if (!is_array($data)) {
            throw new RuntimeException('Eager-loaded data for HasMany must be an array of Model instances or arrays.');
        }

        $models = [];

        foreach ($data as $item) {
            // If item is already a Model instance, use it
            if ($item instanceof Model) {
                $models[] = $item;
                continue;
            }

            // If item is array, populate model
            if (is_array($item)) {
                $relatedModelClass = $this->modelClass;
                /** @var Model $model */
                $model = new $relatedModelClass();
                $model->populate($item);

                // Use reflection to set protected properties
                $reflection = new \ReflectionClass($model);
                $isNewRecordProp = $reflection->getProperty('isNewRecord');
                $isNewRecordProp->setAccessible(true);
                $isNewRecordProp->setValue($model, false);

                $oldAttributesProp = $reflection->getProperty('oldAttributes');
                $oldAttributesProp->setAccessible(true);
                $oldAttributesProp->setValue($model, $item);

                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * Get foreign key column name.
     *
     * @return string Foreign key column name
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get local key column name.
     *
     * @return string Local key column name
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    /**
     * Get related model class name.
     *
     * @return string Related model class name
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * {@inheritDoc}
     */
    public function prepareQuery(): ActiveQuery
    {
        if ($this->model === null) {
            throw new RuntimeException('Model instance must be set before preparing query.');
        }

        $relatedModelClass = $this->modelClass;
        $query = $relatedModelClass::find();

        $localValue = $this->model->{$this->localKey} ?? null;
        if ($localValue !== null) {
            $query->where($this->foreignKey, $localValue);
        } else {
            // No local key value - return query that will match nothing
            $queryBuilder = $query->getQueryBuilder();
            $queryBuilder->whereRaw('1 = 0'); // Always false condition
        }

        return $query;
    }
}
