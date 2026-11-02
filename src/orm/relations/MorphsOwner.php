<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use InvalidArgumentException;
use tommyknocker\pdodb\orm\ActiveQuery;

/**
 * The type column handling shared by {@see MorphMany} and {@see MorphOne}.
 */
trait MorphsOwner
{
    protected string $typeColumn = '';

    protected ?string $explicitType = null;

    /**
     * Read `morphName`, `typeColumn` and `type` from the relation config, and return the config completed with the
     * foreign key the morph name implies (`commentable` => `commentable_id`).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected function morphConfig(array $config): array
    {
        $name = $config['morphName'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException("A morph relation needs 'morphName', the prefix of its type and id columns (for example 'commentable').");
        }
        $this->typeColumn = (string)($config['typeColumn'] ?? $name . '_type');
        $this->explicitType = isset($config['type']) ? (string)$config['type'] : null;
        $config['foreignKey'] ??= $name . '_id';

        return $config;
    }

    public function getTypeColumn(): string
    {
        return $this->typeColumn;
    }

    public function getMorphType(): string
    {
        if ($this->explicitType !== null) {
            return $this->explicitType;
        }

        if ($this->model === null) {
            throw new \RuntimeException('Model instance must be set before the morph type is known.');
        }

        return MorphMap::alias($this->model::class);
    }

    public function constrainByType(ActiveQuery $query): void
    {
        $query->where($this->typeColumn, $this->getMorphType());
    }
}
