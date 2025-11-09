<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis\parsers;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;

/**
 * Parser for MSSQL SHOWPLAN_ALL output.
 */
class MSSQLExplainParser implements ExplainParserInterface
{
    public function parse(array $explainResults): ParsedExplainPlan
    {
        $plan = new ParsedExplainPlan();
        $plan->nodes = $explainResults;

        foreach ($explainResults as $row) {
            // SHOWPLAN_ALL columns: StmtText, LogicalOp, PhysicalOp, Table, EstimateRows, etc.
            $stmtTextValue = $row['StmtText'] ?? '';
            $stmtText = is_string($stmtTextValue) ? $stmtTextValue : '';
            $logicalOpValue = $row['LogicalOp'] ?? '';
            $logicalOp = is_string($logicalOpValue) ? $logicalOpValue : '';
            $physicalOpValue = $row['PhysicalOp'] ?? '';
            $physicalOp = is_string($physicalOpValue) ? $physicalOpValue : '';
            $tableValue = $row['Table'] ?? null;
            $table = ($tableValue !== null && is_string($tableValue)) ? trim($tableValue) : null;
            $estimateRowsValue = $row['EstimateRows'] ?? null;
            $estimateRows = ($estimateRowsValue !== null && is_numeric($estimateRowsValue)) ? (float)$estimateRowsValue : null;
            $indexValue = $row['Index'] ?? null;
            $index = ($indexValue !== null && is_string($indexValue)) ? trim($indexValue) : null;
            $indexKindValue = $row['IndexKind'] ?? null;
            $indexKind = ($indexKindValue !== null && is_string($indexKindValue)) ? trim($indexKindValue) : null;
            $totalSubtreeCostValue = $row['TotalSubtreeCost'] ?? null;
            $totalSubtreeCost = ($totalSubtreeCostValue !== null && is_numeric($totalSubtreeCostValue)) ? (float)$totalSubtreeCostValue : null;

            // Track estimated rows
            if ($estimateRows !== null && $estimateRows > 0) {
                $plan->estimatedRows = max($plan->estimatedRows, (int)$estimateRows);
            }

            // Track total cost
            if ($totalSubtreeCost !== null && $totalSubtreeCost > 0) {
                if ($plan->totalCost === null || $totalSubtreeCost > $plan->totalCost) {
                    $plan->totalCost = $totalSubtreeCost;
                }
            }

            // Detect table scans
            // Clustered Index Scan, Table Scan, Index Scan (without WHERE)
            if (
                ($physicalOp === 'Clustered Index Scan' || $physicalOp === 'Table Scan' || $physicalOp === 'Index Scan')
                && $table !== null
                && $table !== ''
            ) {
                // Check if it's a full scan (no index or clustered index scan without WHERE)
                if ($physicalOp === 'Table Scan' || ($physicalOp === 'Clustered Index Scan' && $index === null)) {
                    if (!in_array($table, $plan->tableScans, true)) {
                        $plan->tableScans[] = $table;
                    }
                    $plan->accessType = $physicalOp;
                }
            }

            // Track used index
            if ($index !== null && $index !== '' && $physicalOp !== 'Table Scan') {
                $plan->usedIndex = $index;
                if ($plan->accessType === null) {
                    $plan->accessType = $physicalOp;
                }
            }

            // Track possible indexes from IndexKind
            if ($indexKind !== null && $indexKind !== '') {
                if ($index !== null && $index !== '') {
                    if (!in_array($index, $plan->possibleKeys, true)) {
                        $plan->possibleKeys[] = $index;
                    }
                }
            }

            // Detect JOIN types from LogicalOp
            if ($logicalOp !== '') {
                if (str_contains($logicalOp, 'Join')) {
                    $joinType = str_replace('Join', '', $logicalOp);
                    if ($joinType !== '' && !in_array($joinType, $plan->joinTypes, true)) {
                        $plan->joinTypes[] = $joinType;
                    }
                }
            }

            // Extract columns from StmtText (WHERE conditions, etc.)
            if ($stmtText !== '') {
                // Try to extract column names from WHERE clauses
                if (preg_match_all('/WHERE\s+\[?(\w+)\]?\s*[=<>!]/i', $stmtText, $matches)) {
                    foreach ($matches[1] as $column) {
                        $column = trim($column);
                        if ($column !== '' && !in_array($column, $plan->usedColumns, true)) {
                            $plan->usedColumns[] = $column;
                        }
                    }
                }
            }
        }

        // Detect warnings for full table scans without index
        if (!empty($plan->tableScans) && $plan->usedIndex === null) {
            foreach ($plan->tableScans as $table) {
                $plan->warnings[] = sprintf(
                    'Full table scan on "%s" without index usage',
                    $table
                );
            }
        }

        // Detect missing index (possible keys exist but none is used)
        if (!empty($plan->possibleKeys) && $plan->usedIndex === null && !empty($plan->tableScans)) {
            foreach ($plan->tableScans as $table) {
                $plan->warnings[] = sprintf(
                    'Table "%s" has possible keys but none is used',
                    $table
                );
            }
        }

        // Detect dependent subquery (Nested Loops with high cost)
        if (!empty($plan->joinTypes)) {
            foreach ($plan->joinTypes as $joinType) {
                if ($joinType === 'Nested Loops' && $plan->estimatedRows > 1000) {
                    $plan->warnings[] = 'Dependent subquery detected (Nested Loops join)';
                    break;
                }
            }
        }

        // Clean up arrays
        $plan->usedColumns = array_values(array_unique($plan->usedColumns));
        $plan->possibleKeys = array_values(array_unique($plan->possibleKeys));
        $plan->joinTypes = array_values(array_unique($plan->joinTypes));

        return $plan;
    }
}
