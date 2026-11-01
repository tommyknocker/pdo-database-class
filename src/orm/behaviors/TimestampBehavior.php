<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\behaviors;

use tommyknocker\pdodb\orm\Model;

/**
 * Fills created-at and updated-at columns during insert and update.
 *
 * `value` overrides the database clock: a closure `fn (Model $model, string $column): mixed`, a `RawValue`
 * expression or a literal.
 *
 * @phpstan-type TimestampConfig array{createdAt?: string|null, updatedAt?: string|null, value?: mixed}
 */
class TimestampBehavior extends ModelBehavior
{
    protected ?string $createdAt;

    protected ?string $updatedAt;

    protected mixed $value;

    /** @param TimestampConfig $config */
    public function __construct(array $config = [])
    {
        $this->createdAt = array_key_exists('createdAt', $config) ? $config['createdAt'] : 'created_at';
        $this->updatedAt = array_key_exists('updatedAt', $config) ? $config['updatedAt'] : 'updated_at';
        $this->value = $config['value'] ?? null;
    }

    public function createdAtColumn(): ?string
    {
        return $this->createdAt;
    }

    public function updatedAtColumn(): ?string
    {
        return $this->updatedAt;
    }

    public function beforeInsert(Model $model): void
    {
        if ($this->createdAt !== null) {
            $current = $model->getAttributes()[$this->createdAt] ?? null;
            if ($current === null || $current === '') {
                ModelClock::assign($model, $this->createdAt, $this->value);
            }
        }
        if ($this->updatedAt !== null) {
            ModelClock::assign($model, $this->updatedAt, $this->value);
        }
    }

    public function beforeUpdate(Model $model): void
    {
        if ($this->updatedAt !== null) {
            ModelClock::assign($model, $this->updatedAt, $this->value);
        }
    }
}
