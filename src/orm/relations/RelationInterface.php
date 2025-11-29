<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use tommyknocker\pdodb\orm\ActiveQuery;
use tommyknocker\pdodb\orm\Model;

/**
 * Interface for all relationship types.
 *
 * Defines the contract for lazy and eager loading of related models.
 */
interface RelationInterface
{
    /**
     * Get related model(s) (lazy loading).
     *
     * Loads related data when accessed for the first time.
     *
     * @return mixed Related model(s) - single Model or array of Models
     */
    public function getValue(): mixed;

    /**
     * Get related model(s) from eager-loaded data.
     *
     * Uses pre-loaded data instead of querying the database.
     *
     * @param mixed $data Eager-loaded data
     *
     * @return mixed Related model(s) - single Model or array of Models
     */
    public function getEagerValue(mixed $data): mixed;

    /**
     * Set the owner model instance.
     *
     * @param Model $model Owner model instance
     *
     * @return static Self instance for chaining
     */
    public function setModel(Model $model): static;

    /**
     * Get the owner model instance.
     *
     * @return Model|null Owner model instance or null if not set
     */
    public function getModel(): ?Model;

    /**
     * Prepare ActiveQuery for this relationship.
     *
     * Returns an ActiveQuery instance with relationship conditions already applied.
     * Allows Yii2-like syntax: $user->posts()->where('published', 1)->all()
     *
     * @return ActiveQuery<Model> ActiveQuery instance with relationship conditions
     */
    public function prepareQuery(): ActiveQuery;
}
