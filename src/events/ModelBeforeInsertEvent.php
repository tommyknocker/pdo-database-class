<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired before a model is inserted into the database.
 *
 * This event can be stopped to prevent the insert operation.
 */
final class ModelBeforeInsertEvent implements StoppableEventInterface
{
    protected bool $stopPropagation = false;

    /**
     * @param \tommyknocker\pdodb\orm\Model $model The model being inserted
     */
    public function __construct(
        private \tommyknocker\pdodb\orm\Model $model
    ) {
    }

    /**
     * Get the model being inserted.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    public function getModel(): \tommyknocker\pdodb\orm\Model
    {
        return $this->model;
    }

    /**
     * Stop event propagation to prevent the insert operation.
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
