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
     */
    public function __construct(
        public array $nodes = [],
        public ?string $accessType = null,
        public ?string $usedIndex = null,
        public array $usedColumns = [],
        public array $tableScans = [],
        public int $estimatedRows = 0,
        public array $warnings = [],
        public array $possibleKeys = []
    ) {
    }
}
