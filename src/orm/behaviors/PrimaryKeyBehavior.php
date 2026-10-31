<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\behaviors;

use RuntimeException;
use tommyknocker\pdodb\helpers\Ulid;
use tommyknocker\pdodb\helpers\Uuid;
use tommyknocker\pdodb\orm\Model;

/**
 * Assigns a UUID or ULID when the primary key is empty.
 *
 * Pair this with incrementing() returning false so a missing key is an error
 * instead of an assumed auto-increment column.
 *
 * @phpstan-type PrimaryKeyConfig array{strategy?: string, column?: string|null}
 */
class PrimaryKeyBehavior extends ModelBehavior
{
    public const string UUID = 'uuid';

    public const string ULID = 'ulid';

    protected string $strategy;

    protected ?string $column;

    /** @param PrimaryKeyConfig|string $config Strategy name or a config array. */
    public function __construct(array|string $config = self::UUID)
    {
        if (is_string($config)) {
            $this->strategy = $config;
            $this->column = null;
            return;
        }

        $this->strategy = (string) ($config['strategy'] ?? self::UUID);
        $column = $config['column'] ?? null;
        $this->column = is_string($column) && $column !== '' ? $column : null;
    }

    public function beforeInsert(Model $model): void
    {
        $primaryKey = $model::primaryKey();
        $column = $this->column ?? ($primaryKey[0] ?? null);
        if (!is_string($column) || $column === '') {
            return;
        }

        $current = $model->getAttributes()[$column] ?? null;
        if ($current !== null && $current !== '') {
            return;
        }

        $model->setAttribute($column, match ($this->strategy) {
            self::UUID => Uuid::v4(),
            self::ULID => Ulid::generate(),
            default => throw new RuntimeException("Unknown primary key strategy '{$this->strategy}'."),
        });
    }
}
