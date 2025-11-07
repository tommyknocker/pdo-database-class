<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis\parsers;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;

/**
 * Parser for MySQL EXPLAIN output.
 */
class MySQLExplainParser implements ExplainParserInterface
{
    public function parse(array $explainResults): ParsedExplainPlan
    {
        $plan = new ParsedExplainPlan();
        $plan->nodes = $explainResults;

        foreach ($explainResults as $row) {
            $table = isset($row['table']) && is_string($row['table']) ? $row['table'] : null;
            $typeValue = $row['type'] ?? '';
            $type = is_string($typeValue) ? $typeValue : '';
            $keyValue = $row['key'] ?? null;
            $key = ($keyValue !== null && (is_string($keyValue) || is_int($keyValue))) ? (string)$keyValue : null;
            $possibleKeysValue = $row['possible_keys'] ?? null;
            $possibleKeys = ($possibleKeysValue !== null && (is_string($possibleKeysValue) || is_int($possibleKeysValue))) ? (string)$possibleKeysValue : null;
            $extraValue = $row['Extra'] ?? '';
            $extra = is_string($extraValue) ? $extraValue : '';
            $rowsValue = $row['rows'] ?? 0;
            $rows = is_numeric($rowsValue) ? (int)$rowsValue : 0;

            // Track estimated rows
            if ($rows > 0) {
                $plan->estimatedRows = max($plan->estimatedRows, $rows);
            }

            // Extract filtered percentage (MySQL 5.7+)
            $filteredValue = $row['filtered'] ?? null;
            if ($filteredValue !== null && is_numeric($filteredValue)) {
                $filtered = (float)$filteredValue;
                // Use minimum filtered value (worst case)
                if ($plan->filtered === 100.0 || $filtered < $plan->filtered) {
                    $plan->filtered = $filtered;
                }
            }

            // Detect full table scan
            if ($type === 'ALL' && $table !== null) {
                if (!in_array($table, $plan->tableScans, true)) {
                    $plan->tableScans[] = $table;
                }
                $plan->accessType = 'ALL';
            } elseif ($type !== '') {
                $plan->accessType = $type;
            }

            // Track used index
            if ($key !== null && $key !== '') {
                $plan->usedIndex = $key;
            }

            // Track possible keys
            if ($possibleKeys !== null && $possibleKeys !== '') {
                $keys = array_map('trim', explode(',', $possibleKeys));
                $plan->possibleKeys = array_merge($plan->possibleKeys, $keys);
                $plan->possibleKeys = array_unique($plan->possibleKeys);
            }

            // Detect missing index (possible_keys exists but key is null/empty)
            if (
                $possibleKeys !== null
                && $possibleKeys !== ''
                && ($key === null || $key === '')
                && $table !== null
            ) {
                $plan->warnings[] = sprintf(
                    'Table "%s" has possible keys but none is used',
                    $table
                );
            }

            // Detect no index usage when ALL type
            if ($type === 'ALL' && ($key === null || $key === '') && $table !== null) {
                $plan->warnings[] = sprintf(
                    'Full table scan on "%s" without index usage',
                    $table
                );
            }

            // Check for Using filesort
            if ($extra !== '' && str_contains($extra, 'Using filesort')) {
                $plan->warnings[] = 'Query requires filesort (temporary sorting)';
            }

            // Check for Using temporary
            if ($extra !== '' && str_contains($extra, 'Using temporary')) {
                $plan->warnings[] = 'Query creates temporary table';
            }

            // Detect dependent subquery
            if ($extra !== '' && str_contains($extra, 'dependent subquery')) {
                $plan->warnings[] = 'Dependent subquery detected';
            }

            // Detect GROUP BY without index
            // Check if both Using temporary and Using filesort are present in the same Extra field
            if ($extra !== '' && str_contains($extra, 'Using temporary') && str_contains($extra, 'Using filesort')) {
                // Check if we already detected this in previous rows
                $hasGroupByWarning = false;
                foreach ($plan->warnings as $warning) {
                    if (str_contains($warning, 'GROUP BY requires temporary table and filesort')) {
                        $hasGroupByWarning = true;
                        break;
                    }
                }

                if (!$hasGroupByWarning) {
                    $plan->warnings[] = 'GROUP BY requires temporary table and filesort';
                }
            }

            // Extract columns from WHERE conditions if available
            $refValue = $row['ref'] ?? null;
            $ref = ($refValue !== null && (is_string($refValue) || is_int($refValue))) ? (string)$refValue : null;
            if ($ref !== null && $ref !== '') {
                $plan->usedColumns = array_merge(
                    $plan->usedColumns,
                    array_map('trim', explode(',', $ref))
                );
            }
        }

        $plan->usedColumns = array_values(array_unique($plan->usedColumns));
        $plan->possibleKeys = array_values(array_unique($plan->possibleKeys));

        return $plan;
    }
}
