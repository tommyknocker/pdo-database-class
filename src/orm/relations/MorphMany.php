<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use tommyknocker\pdodb\orm\ActiveQuery;

/**
 * A has-many relation where the related table serves several kinds of owner.
 *
 * ```php
 * // comments(commentable_type, commentable_id, ...) belong to posts and to videos
 * 'comments' => ['morphMany', 'modelClass' => Comment::class, 'morphName' => 'commentable'],
 * ```
 *
 * Options: `morphName` (required; `{name}_type` and `{name}_id` are the columns), `typeColumn`, `foreignKey`,
 * `localKey`, and `type` for the value stored in the type column (default: the owner's {@see MorphMap} alias or class
 * name).
 */
class MorphMany extends HasMany implements MorphRelation
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
