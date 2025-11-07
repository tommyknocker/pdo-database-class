<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;
use tommyknocker\pdodb\orm\Model;

/**
 * Event fired after a model is successfully inserted into the database.
 */
final class ModelAfterInsertEvent implements StoppableEventInterface
{
    /**
     * @param Model $model The model that was inserted
     * @param mixed $insertId The inserted ID (primary key value)
     */
    public function __construct(
        private Model $model,
        private mixed $insertId
    ) {
    }

    /**
     * Get the model that was inserted.
     *
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Get the inserted ID (primary key value).
     *
     * @return mixed
     */
    public function getInsertId(): mixed
    {
        return $this->insertId;
    }

    /**
     * Check if event propagation is stopped.
     *
     * @return bool
     */
    public function isPropagationStopped(): bool
    {
        return false;
    }
}
