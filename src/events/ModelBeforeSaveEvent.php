<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired before a model is saved (insert or update).
 *
 * This event can be stopped to prevent the save operation.
 */
final class ModelBeforeSaveEvent implements StoppableEventInterface
{
    protected bool $stopPropagation = false;

    /**
     * @param \tommyknocker\pdodb\orm\Model $model The model being saved
     * @param bool $isNewRecord Whether this is a new record (insert) or existing (update)
     */
    public function __construct(
        private \tommyknocker\pdodb\orm\Model $model,
        private bool $isNewRecord
    ) {
    }

    /**
     * Get the model being saved.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    public function getModel(): \tommyknocker\pdodb\orm\Model
    {
        return $this->model;
    }

    /**
     * Check if this is a new record (insert) or existing (update).
     *
     * @return bool True if new record, false if update
     */
    public function isNewRecord(): bool
    {
        return $this->isNewRecord;
    }

    /**
     * Stop event propagation to prevent the save operation.
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
