<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired after a model is successfully inserted into the database.
 */
final class ModelAfterInsertEvent implements StoppableEventInterface
{
    /**
     * @param \tommyknocker\pdodb\orm\Model $model The model that was inserted
     * @param mixed $insertId The inserted ID (primary key value)
     */
    public function __construct(
        private \tommyknocker\pdodb\orm\Model $model,
        private mixed $insertId
    ) {
    }

    /**
     * Get the model that was inserted.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    public function getModel(): \tommyknocker\pdodb\orm\Model
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
