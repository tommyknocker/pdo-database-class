<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\behaviors;

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Optional model behavior.
 *
 * Behaviors keep timestamps, soft deletes, and generated keys out of the base
 * ActiveRecord class. A model opts in by returning them from behaviors().
 */
abstract class ModelBehavior
{
    /** @var array<string, mixed> */
    protected array $config = [];

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function beforeInsert(Model $model): void
    {
    }

    public function beforeUpdate(Model $model): void
    {
    }

    /**
     * @return array<string, callable(QueryBuilder): QueryBuilder>
     */
    public function globalScopes(): array
    {
        return [];
    }
}
