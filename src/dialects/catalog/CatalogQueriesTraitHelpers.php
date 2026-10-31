<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\catalog;

use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * Shared helpers for catalog query traits.
 */
trait CatalogQueriesTraitHelpers
{
    abstract protected function catalogDialect(): DialectInterface;

    protected function quoteCatalogIdentifier(string $name): string
    {
        return $this->catalogDialect()->quoteIdentifier($name);
    }

    /**
     * @param callable(string, array<int|string, mixed>, int): list<array<string, mixed>> $fetchRows
     */
    public function resolveOracleObjectName(
        callable $fetchRows,
        string $schema,
        string $table,
    ): string {
        return $table;
    }
}
