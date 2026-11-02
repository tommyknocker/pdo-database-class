<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use tommyknocker\pdodb\orm\ActiveQuery;
use tommyknocker\pdodb\orm\Model;

/**
 * A relation whose rows are found by a foreign key and a type column: the related table holds rows of several
 * kinds of owner, and the type says which one this relation's owner is.
 */
interface MorphRelation
{
    /**
     * Name of the column that holds the owner type.
     */
    public function getTypeColumn(): string;

    /**
     * The value that marks the owner's rows in the type column.
     */
    public function getMorphType(): string;

    /**
     * Limit a query on the related table to the owner's type.
     *
     * @param ActiveQuery<Model> $query
     */
    public function constrainByType(ActiveQuery $query): void;
}
