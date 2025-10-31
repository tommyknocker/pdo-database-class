<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired after a model is successfully deleted from the database.
 */
final class ModelAfterDeleteEvent implements StoppableEventInterface
{
    /**
     * @param \tommyknocker\pdodb\orm\Model $model The model that was deleted
     * @param int $rowsAffected Number of rows affected
     */
    public function __construct(
        private \tommyknocker\pdodb\orm\Model $model,
        private int $rowsAffected
    ) {
    }

    /**
     * Get the model that was deleted.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    public function getModel(): \tommyknocker\pdodb\orm\Model
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
