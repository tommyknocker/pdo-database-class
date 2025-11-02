<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\FulltextMatchValue;

/**
 * Trait for full-text search operations.
 */
trait FulltextSearchHelpersTrait
{
    /**
     * Returns a FulltextMatchValue for full-text search.
     *
     * @param string|array<string> $columns Column name(s) to search in.
     * @param string $searchTerm The search term.
     * @param string|null $mode Search mode: 'natural', 'boolean', 'expansion' (MySQL only).
     * @param bool $withQueryExpansion Enable query expansion (MySQL only).
     *
     * @return FulltextMatchValue The FulltextMatchValue instance.
     */
    public static function match(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): FulltextMatchValue
    {
        return new FulltextMatchValue($columns, $searchTerm, $mode, $withQueryExpansion);
    }
}
