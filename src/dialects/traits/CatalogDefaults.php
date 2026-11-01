<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\catalog\CatalogQueriesInterface;
use tommyknocker\pdodb\dialects\roles\DialectCatalogInterface;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;

/**
 * Default implementation of the table and column discovery part of the dialect ({@see DialectCatalogInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait CatalogDefaults
{
    /**
     * {@inheritDoc}
     */
    public function catalogQueries(): CatalogQueriesInterface
    {
        throw new UnsupportedOperationException('Catalog queries are not implemented for ' . $this->getDriverName());
    }

    /**
     * Check if column is array type (PostgreSQL arrays).
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return bool
     */
    public function isArrayColumn(array $columnMetadata): bool
    {
        $type = $this->getColumnType($columnMetadata);
        return str_contains($type, '[]') || str_contains($type, 'array');
    }

    /**
     * Check if column is numeric type.
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return bool
     */
    public function isNumericColumn(array $columnMetadata): bool
    {
        $type = $this->getColumnType($columnMetadata);
        return str_contains($type, 'int') ||
               str_contains($type, 'decimal') ||
               str_contains($type, 'float') ||
               str_contains($type, 'double') ||
               str_contains($type, 'numeric') ||
               str_contains($type, 'real');
    }

    /**
     * Get column name from column metadata.
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return string|null Column name or null if not found
     */
    public function getColumnNameFromMetadata(array $columnMetadata): ?string
    {
        return $columnMetadata['Field'] ?? $columnMetadata['field'] ?? $columnMetadata['column_name'] ?? $columnMetadata['name'] ?? null;
    }
}
