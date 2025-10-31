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

            // Extract columns from WHERE conditions if available
            $refValue = $row['ref'] ?? null;
            $ref = ($refValue !== null && (is_string($refValue) || is_int($refValue))) ? (string)$refValue : null;
            if ($ref !== null && $ref !== '') {
                $plan->usedColumns = array_merge(
                    $plan->usedColumns,
                    array_map('trim', explode(',', $ref))
                );
            }

            // Try to extract columns from key_len and ref
            $keyLen = $row['key_len'] ?? null;
            if ($keyLen !== null && $key !== null && $key !== '') {
                // For composite indexes, we track the index name
                // Exact column extraction would require table schema analysis
            }
        }

        $plan->usedColumns = array_values(array_unique($plan->usedColumns));
        $plan->possibleKeys = array_values(array_unique($plan->possibleKeys));

        return $plan;
    }
}
