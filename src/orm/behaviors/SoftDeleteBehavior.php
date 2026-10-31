<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\behaviors;

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Hides rows with a non-null deletion timestamp and turns delete() into an update.
 *
 * @phpstan-type SoftDeleteConfig array{column?: string}
 */
class SoftDeleteBehavior extends ModelBehavior
{
    public const string SCOPE = 'softDelete';

    protected string $column;

    /** @param SoftDeleteConfig $config */
    public function __construct(array $config = [])
    {
        $this->column = $config['column'] ?? 'deleted_at';
    }

    public function column(): string
    {
        return $this->column;
    }

    public function isDeleted(Model $model): bool
    {
        $value = $model->getAttributes()[$this->column] ?? null;

        return $value !== null && $value !== '';
    }

    public function globalScopes(): array
    {
        $column = $this->column;

        return [
            self::SCOPE => static function (QueryBuilder $query) use ($column): QueryBuilder {
                $query->whereNull($column);

                return $query;
            },
        ];
    }
}
