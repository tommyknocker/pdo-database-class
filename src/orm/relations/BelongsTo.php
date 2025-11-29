<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use RuntimeException;
use tommyknocker\pdodb\orm\ActiveQuery;
use tommyknocker\pdodb\orm\Model;

/**
 * BelongsTo relationship.
 *
 * Represents a many-to-one relationship where the current model belongs to a related model.
 * The foreign key is stored in the current model's table.
 */
class BelongsTo implements RelationInterface
{
    /** @var string Related model class name */
    protected string $modelClass;

    /** @var string Foreign key column in owner model's table */
    protected string $foreignKey;

    /** @var string Owner key column in related model's table (usually primary key) */
    protected string $ownerKey;

    /** @var Model|null Owner model instance */
    protected ?Model $model = null;

    /**
     * BelongsTo constructor.
     *
     * @param string $modelClass Related model class name
     * @param array<string, mixed> $config Relationship configuration
     *                                     - 'foreignKey': Foreign key column in owner model (default: '{modelName}_id')
     *                                     - 'ownerKey': Owner key column in related model (default: primary key)
     */
    public function __construct(string $modelClass, array $config = [])
    {
        $this->modelClass = $modelClass;

        // Auto-detect foreign key if not specified
        if (!isset($config['foreignKey'])) {
            $relatedTable = $modelClass::tableName();
            $relatedTable = preg_replace('/s$/', '', $relatedTable); // Remove plural 's'
            $this->foreignKey = $relatedTable . '_id';
        } else {
            $this->foreignKey = (string)$config['foreignKey'];
        }

        // Auto-detect owner key (primary key of related model) if not specified
        if (!isset($config['ownerKey'])) {
            $this->ownerKey = 'id'; // Default to 'id', will be resolved when model is set
        } else {
            $this->ownerKey = (string)$config['ownerKey'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;

        // Resolve owner key from related model's primary key if using default
        if ($this->ownerKey === 'id') {
            $relatedModelClass = $this->modelClass;
            $pk = $relatedModelClass::primaryKey();
            if (!empty($pk)) {
                $this->ownerKey = $pk[0];
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

        $foreignValue = $this->model->{$this->foreignKey} ?? null;
        if ($foreignValue === null) {
            return null;
        }

        $relatedModelClass = $this->modelClass;
        return $relatedModelClass::findOne([$this->ownerKey => $foreignValue]);
    }

    /**
     * {@inheritDoc}
     */
    public function getEagerValue(mixed $data): mixed
    {
        if ($data === null) {
            return null;
        }

        // If data is already a Model instance, return it
        if ($data instanceof Model) {
            return $data;
        }

        // If data is array, populate model
        if (!is_array($data)) {
            throw new RuntimeException('Eager-loaded data for BelongsTo must be a Model instance, array, or null.');
        }

        $relatedModelClass = $this->modelClass;
        /** @var Model $model */
        $model = new $relatedModelClass();
        $model->populate($data);

        // Set protected properties using public methods
        $model->setIsNewRecord(false);
        $model->setOldAttributes($data);

        return $model;
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
     * Get owner key column name.
     *
     * @return string Owner key column name
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
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
     *
     * @return ActiveQuery<Model>
     */
    public function prepareQuery(): ActiveQuery
    {
        if ($this->model === null) {
            throw new RuntimeException('Model instance must be set before preparing query.');
        }

        $relatedModelClass = $this->modelClass;
        $query = $relatedModelClass::find();

        $foreignValue = $this->model->{$this->foreignKey} ?? null;
        if ($foreignValue !== null) {
            $query->where($this->ownerKey, $foreignValue);
        } else {
            // No foreign key value - return query that will match nothing
            $queryBuilder = $query->getQueryBuilder();
            $queryBuilder->whereRaw('1 = 0'); // Always false condition
        }

        return $query;
    }
}
