<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\traits;

use tommyknocker\pdodb\helpers\values\RawValue;

trait ExternalReferenceProcessingTrait
{
    /**
     * Check if a string represents an external table reference.
     *
     * @param string $reference The reference to check (e.g., 'users.id')
     *
     * @return bool True if it's an external reference
     */
    protected function isExternalReference(string $reference): bool
    {
        // Check if it matches table.column pattern
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $reference)) {
            return false;
        }

        $table = explode('.', $reference)[0];
        return !$this->isTableInCurrentQuery($table);
    }

    /**
     * Check if a table is referenced in the current query.
     *
     * @param string $tableName The table name to check
     *
     * @return bool True if table is in current query
     */
    protected function isTableInCurrentQuery(string $tableName): bool
    {
        return $this->table === $tableName;
    }

    /**
     * Automatically convert external references to RawValue.
     *
     * @param mixed $value The value to process
     *
     * @return mixed Processed value
     */
    protected function processExternalReferences(mixed $value): mixed
    {
        if (is_string($value) && $this->isExternalReference($value)) {
            return new RawValue($value);
        }

        return $value;
    }
}
