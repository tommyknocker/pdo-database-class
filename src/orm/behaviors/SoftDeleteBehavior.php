<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\behaviors;

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Hides rows with a non-null deletion timestamp and turns delete() into an update.
 *
 * `value` overrides the database clock used for the deletion time: a closure
 * `fn (Model $model, string $column): mixed`, a `RawValue` expression or a literal.
 *
 * @phpstan-type SoftDeleteConfig array{column?: string, value?: mixed}
 */
class SoftDeleteBehavior extends ModelBehavior
{
    public const string SCOPE = 'softDelete';

    protected string $column;

    protected mixed $value;

    /** @param SoftDeleteConfig $config */
    public function __construct(array $config = [])
    {
        $this->column = $config['column'] ?? 'deleted_at';
        $this->value = $config['value'] ?? null;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function deletionValue(Model $model): mixed
    {
        return ModelClock::value($model, $this->column, $this->value);
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
