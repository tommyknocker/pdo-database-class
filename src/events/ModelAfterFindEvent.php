<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;
use tommyknocker\pdodb\orm\Model;

/**
 * Event fired after a model was filled from a database row.
 */
final class ModelAfterFindEvent implements StoppableEventInterface
{
    /**
     * @param Model $model The model that was loaded
     */
    public function __construct(private Model $model)
    {
    }

    /**
     * Get the model that was loaded.
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Check if event propagation is stopped.
     */
    public function isPropagationStopped(): bool
    {
        return false;
    }
}
