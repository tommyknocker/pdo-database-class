<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired before a model is deleted from the database.
 *
 * This event can be stopped to prevent the delete operation.
 */
final class ModelBeforeDeleteEvent implements StoppableEventInterface
{
    protected bool $stopPropagation = false;

    /**
     * @param \tommyknocker\pdodb\orm\Model $model The model being deleted
     */
    public function __construct(
        private \tommyknocker\pdodb\orm\Model $model
    ) {
    }

    /**
     * Get the model being deleted.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    public function getModel(): \tommyknocker\pdodb\orm\Model
    {
        return $this->model;
    }

    /**
     * Stop event propagation to prevent the delete operation.
     */
    public function stopPropagation(): void
    {
        $this->stopPropagation = true;
    }

    /**
     * Check if event propagation is stopped.
     *
     * @return bool
     */
    public function isPropagationStopped(): bool
    {
        return $this->stopPropagation;
    }
}
