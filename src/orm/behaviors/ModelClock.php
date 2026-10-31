<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\behaviors;

use DateTimeImmutable;
use DateTimeZone;
use tommyknocker\pdodb\orm\Model;

/** Assigns a UTC timestamp, honoring a DateTimeImmutable cast when one is declared. */
final class ModelClock
{
    public static function assign(Model $model, string $column): void
    {
        $cast = $model::casts()[$column] ?? null;
        if ($cast === DateTimeImmutable::class) {
            $model->setAttribute($column, new DateTimeImmutable('now', new DateTimeZone('UTC')));
            return;
        }

        $model->setAttribute($column, gmdate('Y-m-d H:i:s'));
    }

    public static function databaseValue(Model $model, string $column): string
    {
        $cast = $model::casts()[$column] ?? null;
        if ($cast === DateTimeImmutable::class) {
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
