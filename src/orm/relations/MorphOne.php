<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use tommyknocker\pdodb\orm\ActiveQuery;

/**
 * A has-one relation where the related table serves several kinds of owner. Configured like {@see MorphMany}.
 */
class MorphOne extends HasOne implements MorphRelation
{
    use MorphsOwner;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(string $modelClass, array $config = [])
    {
        parent::__construct($modelClass, $this->morphConfig($config));
    }

    public function prepareQuery(): ActiveQuery
    {
        $query = parent::prepareQuery();
        $this->constrainByType($query);

        return $query;
    }
}
