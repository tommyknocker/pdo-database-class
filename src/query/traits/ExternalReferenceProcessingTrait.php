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
        // If table is not in current query (FROM clause), it's an external reference
        // In LATERAL JOIN subqueries, tables from outer query are automatically external
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
        if ($this->table === $tableName) {
            return true;
        }
        // Check JOIN tables if joinBuilder is available
        if (property_exists($this, 'joinBuilder') && isset($this->joinBuilder)) {
            $joins = $this->joinBuilder->getJoins();
            foreach ($joins as $join) {
                // Extract table name/alias from JOIN clause (e.g., "INNER JOIN [tenants] AS t" or "LEFT JOIN users u")
                // Handle quoted identifiers: [tenants] AS t, "tenants" AS "t", tenants AS t, tenants t
                // Pattern: JOIN [table] AS [alias] or JOIN "table" AS "alias" or JOIN table AS alias or JOIN table alias
                // Match groups: [1]=[table], [2]="table", [3]=table, [4]=[alias], [5]="alias", [6]=alias
                if (preg_match('/JOIN\s+(?:\[([^\]]+)\]|"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))\s+(?:AS\s+)?(?:\[([^\]]+)\]|"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))/i', $join, $matches)) {
                    // Check all possible alias positions (matches[4] for [alias], matches[5] for "alias", matches[6] for alias)
                    // Filter out empty strings - check in order: [5] (double quotes), [4] (square brackets), [6] (unquoted)
                    $alias = null;
                    foreach ([5, 4, 6] as $i) {
                        if (isset($matches[$i]) && $matches[$i] !== '') {
                            $alias = $matches[$i];
                            break;
                        }
                    }
                    if ($alias && $alias === $tableName) {
                        return true;
                    }
                }
            }
        }
        return false;
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
