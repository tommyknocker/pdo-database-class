<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired after a model is successfully saved (insert or update).
 */
final class ModelAfterSaveEvent implements StoppableEventInterface
{
    /**
     * @param \tommyknocker\pdodb\orm\Model $model The model that was saved
     * @param bool $isNewRecord Whether this was a new record (insert) or existing (update)
     */
    public function __construct(
        private \tommyknocker\pdodb\orm\Model $model,
        private bool $isNewRecord
    ) {
    }

    /**
     * Get the model that was saved.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    public function getModel(): \tommyknocker\pdodb\orm\Model
    {
        return $this->model;
    }

    /**
     * Check if this was a new record (insert) or existing (update).
     *
     * @return bool True if new record, false if update
     */
    public function isNewRecord(): bool
    {
        return $this->isNewRecord;
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
