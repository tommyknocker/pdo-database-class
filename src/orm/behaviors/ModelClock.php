<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\behaviors;

use Closure;
use tommyknocker\pdodb\helpers\values\NowValue;
use tommyknocker\pdodb\orm\Model;

/**
 * Resolves the value a behavior writes into a timestamp column.
 *
 * Without a custom value the database clock is used, so every writer shares one time source.
 * A custom value may be a closure `fn (Model $model, string $column): mixed`, a `RawValue`
 * expression, or a literal. The model reloads database-evaluated columns after saving.
 */
final class ModelClock
{
    public static function value(Model $model, string $column, mixed $custom = null): mixed
    {
        if ($custom instanceof Closure) {
            return $custom($model, $column);
        }

        return $custom ?? new NowValue();
    }

    public static function assign(Model $model, string $column, mixed $custom = null): void
    {
        $model->setAttribute($column, self::value($model, $column, $custom));
    }
}
