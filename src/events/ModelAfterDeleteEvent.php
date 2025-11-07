<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;
use tommyknocker\pdodb\orm\Model;

/**
 * Event fired after a model is successfully deleted from the database.
 */
final class ModelAfterDeleteEvent implements StoppableEventInterface
{
    /**
     * @param Model $model The model that was deleted
     * @param int $rowsAffected Number of rows affected
     */
    public function __construct(
        private Model $model,
        private int $rowsAffected
    ) {
    }

    /**
     * Get the model that was deleted.
     *
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Get number of rows affected.
     *
     * @return int
     */
    public function getRowsAffected(): int
    {
        return $this->rowsAffected;
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
