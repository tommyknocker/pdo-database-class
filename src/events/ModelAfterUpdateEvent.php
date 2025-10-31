<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired after a model is successfully updated in the database.
 */
final class ModelAfterUpdateEvent implements StoppableEventInterface
{
    /**
     * @param \tommyknocker\pdodb\orm\Model $model The model that was updated
     * @param array<string, mixed> $changedAttributes The attributes that were changed
     * @param int $rowsAffected Number of rows affected
     */
    public function __construct(
        private \tommyknocker\pdodb\orm\Model $model,
        private array $changedAttributes,
        private int $rowsAffected
    ) {
    }

    /**
     * Get the model that was updated.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    public function getModel(): \tommyknocker\pdodb\orm\Model
    {
        return $this->model;
    }

    /**
     * Get the attributes that were changed.
     *
     * @return array<string, mixed>
     */
    public function getChangedAttributes(): array
    {
        return $this->changedAttributes;
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
