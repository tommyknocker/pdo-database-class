<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use InvalidArgumentException;
use RuntimeException;
use tommyknocker\pdodb\orm\ActiveQuery;
use tommyknocker\pdodb\orm\Model;

/**
 * The owning side of a polymorphic relation: the model holds the type and the id of a row that can be in any of
 * several tables.
 *
 * ```php
 * // Comment has commentable_type ('post' or 'video') and commentable_id
 * 'commentable' => ['morphTo', 'morphName' => 'commentable'],
 * ```
 *
 * The class comes from the type column through the {@see MorphMap}. Options: `morphName` (required; `{name}_type` and
 * `{name}_id` are the columns), `typeColumn`, `idColumn`.
 */
class MorphTo implements RelationInterface
{
    protected string $typeColumn;

    protected string $idColumn;

    protected ?Model $model = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $name = $config['morphName'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException("morphTo needs 'morphName', the prefix of its type and id columns (for example 'commentable').");
        }
        $this->typeColumn = (string)($config['typeColumn'] ?? $name . '_type');
        $this->idColumn = (string)($config['idColumn'] ?? $name . '_id');
    }

    public function setModel(Model $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getModel(): ?Model
    {
        return $this->model;
    }

    public function getTypeColumn(): string
    {
        return $this->typeColumn;
    }

    public function getIdColumn(): string
    {
        return $this->idColumn;
    }

    /**
     * The related model class this owner points to, or null when it points nowhere.
     *
     * @return class-string<Model>|null
     */
    public function resolveClass(Model $owner): ?string
    {
        $type = $owner->{$this->typeColumn} ?? null;
        $id = $owner->{$this->idColumn} ?? null;
        if ($type === null || $type === '' || $id === null) {
            return null;
        }

        return MorphMap::resolve((string)$type);
    }

    public function getValue(): mixed
    {
        if ($this->model === null) {
            throw new RuntimeException('Model instance must be set before accessing relationship.');
        }
        if ($this->resolveClass($this->model) === null) {
            return null;
        }

        return $this->prepareQuery()->one();
    }

    public function getEagerValue(mixed $data): mixed
    {
        if ($data === null || $data instanceof Model) {
            return $data;
        }
        if (!is_array($data) || $this->model === null) {
            throw new RuntimeException('Eager-loaded data for MorphTo must be a Model instance or null.');
        }
        $class = $this->resolveClass($this->model);
        if ($class === null) {
            return null;
        }

        $related = new $class();
        $db = $this->model->propagatesConnection() ? $this->model->getModelDb() : $class::getDb();
        if ($db !== null) {
            $related->bindConnection($db, $this->model->propagatesConnection());
        }

        return $related->hydrate($data);
    }

    public function prepareQuery(): ActiveQuery
    {
        if ($this->model === null) {
            throw new RuntimeException('Model instance must be set before preparing query.');
        }
        $class = $this->resolveClass($this->model);
        if ($class === null) {
            throw new RuntimeException('The owner has no type and id, so there is no related table to query.');
        }

        $query = $this->model->relationQuery($class);
        $query->where($class::primaryKey()[0] ?? 'id', $this->model->{$this->idColumn});

        return $query;
    }
}
