<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Detects redundant indexes (indexes that are covered by other indexes).
 */
class RedundantIndexDetector
{
    protected PdoDb $db;

    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    /**
     * Detect redundant indexes in a table.
     *
     * @param string $table Table name
     * @param array<string, array<int, string>> $indexes Index name => array of columns
     *
     * @return array<int, array<string, mixed>> Array of redundant index info
     */
    public function detect(string $table, array $indexes): array
    {
        $redundant = [];

        foreach ($indexes as $indexName => $indexColumns) {
            foreach ($indexes as $otherName => $otherColumns) {
                // Skip same index
                if ($indexName === $otherName) {
                    continue;
                }

                // Check if index is covered by another index
                if ($this->isCovered($indexColumns, $otherColumns)) {
                    $redundant[] = [
                        'index' => $indexName,
                        'columns' => $indexColumns,
                        'covered_by' => $otherName,
                        'covering_columns' => $otherColumns,
                    ];
                    // Only report once per index
                    break;
                }
            }
        }

        return $redundant;
    }

    /**
     * Check if index A is covered by index B.
     *
     * Index A is covered by index B if:
     * - B starts with all columns from A (in the same order)
     * - B has the same or more columns
     *
     * Example:
     * - Index A: (name)
     * - Index B: (name, status) -> A is covered by B
     *
     * @param array<int, string> $indexColumns Columns of the index to check
     * @param array<int, string> $otherColumns Columns of the potentially covering index
     *
     * @return bool True if index is covered
     */
    protected function isCovered(array $indexColumns, array $otherColumns): bool
    {
        // If other index has fewer columns, it can't cover
        if (count($otherColumns) < count($indexColumns)) {
            return false;
        }

        // Check if other index starts with all columns from this index
        for ($i = 0; $i < count($indexColumns); $i++) {
            if (!isset($otherColumns[$i]) || $otherColumns[$i] !== $indexColumns[$i]) {
                return false;
            }
        }

        return true;
    }
}
