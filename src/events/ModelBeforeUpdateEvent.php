<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired before a model is updated in the database.
 *
 * This event can be stopped to prevent the update operation.
 */
final class ModelBeforeUpdateEvent implements StoppableEventInterface
{
    protected bool $stopPropagation = false;

    /**
     * @param \tommyknocker\pdodb\orm\Model $model The model being updated
     * @param array<string, mixed> $dirtyAttributes The attributes that changed
     */
    public function __construct(
        private \tommyknocker\pdodb\orm\Model $model,
        private array $dirtyAttributes
    ) {
    }

    /**
     * Get the model being updated.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    public function getModel(): \tommyknocker\pdodb\orm\Model
    {
        return $this->model;
    }

    /**
     * Get the attributes that changed (dirty attributes).
     *
     * @return array<string, mixed>
     */
    public function getDirtyAttributes(): array
    {
        return $this->dirtyAttributes;
    }

    /**
     * Stop event propagation to prevent the update operation.
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
