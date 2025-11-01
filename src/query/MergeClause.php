<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Represents a MERGE clause configuration.
 */
class MergeClause
{
    /** @var array<string, string|int|float|bool|null|RawValue> When matched - update columns */
    public array $whenMatched = [];

    /** @var array<string, string|int|float|bool|null|RawValue> When not matched - insert columns */
    public array $whenNotMatched = [];

    /** @var bool Whether to delete when not matched by source */
    public bool $whenNotMatchedBySourceDelete = false;

    /** @var string|null Additional condition for WHEN MATCHED */
    public ?string $whenMatchedCondition = null;

    /** @var string|null Additional condition for WHEN NOT MATCHED */
    public ?string $whenNotMatchedCondition = null;
}
