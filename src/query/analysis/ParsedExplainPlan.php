<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis;

/**
 * Structured representation of parsed EXPLAIN plan.
 */
class ParsedExplainPlan
{
    /**
     * @param array<int, array<string, mixed>> $nodes Execution plan nodes
     * @param string|null $accessType Access type (ALL, index, ref, etc.)
     * @param string|null $usedIndex Currently used index
     * @param array<string> $usedColumns Columns used in WHERE/ORDER BY
     * @param array<string> $tableScans Tables with full table scans
     * @param int $estimatedRows Estimated number of rows to examine
     * @param array<string> $warnings Warnings and issues detected
     * @param array<string> $possibleKeys Possible indexes that could be used
     * @param float $filtered Percentage of rows filtered after index lookup (MySQL, 0-100)
     * @param array<string> $joinTypes Types of JOIN operations detected
     * @param bool $hasLimit Whether query has LIMIT clause
     * @param bool $hasOrderBy Whether query has ORDER BY clause
     * @param float|null $totalCost Total query cost (PostgreSQL)
     * @param float|null $executionTime Actual execution time in milliseconds (EXPLAIN ANALYZE)
     * @param int|null $indexCardinality Cardinality of used index
     */
    public function __construct(
        public array $nodes = [],
        public ?string $accessType = null,
        public ?string $usedIndex = null,
        public array $usedColumns = [],
        public array $tableScans = [],
        public int $estimatedRows = 0,
        public array $warnings = [],
        public array $possibleKeys = [],
        public float $filtered = 100.0,
        public array $joinTypes = [],
        public bool $hasLimit = false,
        public bool $hasOrderBy = false,
        public ?float $totalCost = null,
        public ?float $executionTime = null,
        public ?int $indexCardinality = null
    ) {
    }
}
