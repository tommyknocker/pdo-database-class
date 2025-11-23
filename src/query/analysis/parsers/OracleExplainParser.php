<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis\parsers;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;

/**
 * Parser for Oracle EXPLAIN PLAN output.
 *
 * Oracle uses DBMS_XPLAN.DISPLAY() to show execution plans.
 * The output format is different from MySQL/PostgreSQL.
 */
class OracleExplainParser implements ExplainParserInterface
{
    public function parse(array $explainResults): ParsedExplainPlan
    {
        $plan = new ParsedExplainPlan();
        $plan->nodes = $explainResults;

        // Oracle EXPLAIN PLAN output is typically a single column with formatted text
        // The output from DBMS_XPLAN.DISPLAY() is usually in PLAN_TABLE format
        // or as formatted text in a single column

        $queryPlan = '';
        foreach ($explainResults as $row) {
            // Oracle output can be in different formats
            // Check for common column names
            $planText = $row['PLAN_TABLE_OUTPUT'] ?? $row['PLAN_TABLE_OUTPUT'] ?? null;
            if ($planText === null) {
                // Try to get first column value
                $values = array_values($row);
                if (!empty($values) && is_string($values[0])) {
                    $planText = $values[0];
                }
            }
            if ($planText !== null && is_string($planText)) {
                $queryPlan .= $planText . "\n";
            }
        }

        if ($queryPlan === '') {
            return $plan;
        }

        // Parse TABLE ACCESS FULL (full table scan)
        if (preg_match_all('/TABLE ACCESS FULL\s+(\S+)/i', $queryPlan, $matches)) {
            foreach ($matches[1] as $table) {
                // @phpstan-ignore-next-line
                if (!is_string($table)) {
                    continue;
                }
                $tableStr = trim($table);
                if ($tableStr === '') {
                    continue;
                }
                // Remove schema prefix if present (e.g., "SCHEMA"."TABLE" -> TABLE)
                $tableStr = preg_replace('/^[^"]*"\."/', '', $tableStr);
                $tableStrTrimmed = is_string($tableStr) ? trim($tableStr, '"\'') : '';
                if ($tableStrTrimmed !== '' && !in_array($tableStrTrimmed, $plan->tableScans, true)) {
                    $plan->tableScans[] = $tableStrTrimmed;
                }
            }
            $plan->accessType = 'TABLE ACCESS FULL';
        }

        // Parse INDEX RANGE SCAN
        if (preg_match_all('/INDEX\s+(?:RANGE|UNIQUE|FULL)\s+SCAN\s+.*?(\S+)/i', $queryPlan, $matches)) {
            if (!empty($matches[1])) {
                $indexNameRaw = $matches[1][0];
                // @phpstan-ignore-next-line
                $indexName = is_string($indexNameRaw) ? trim($indexNameRaw) : '';
                if ($indexName !== '') {
                    // Remove schema prefix if present
                    $indexName = preg_replace('/^[^"]*"\."/', '', $indexName);
                    $indexNameTrimmed = is_string($indexName) ? trim($indexName, '"\'') : '';
                    if ($indexNameTrimmed !== '') {
                        $plan->usedIndex = $indexNameTrimmed;
                        $plan->accessType = 'INDEX SCAN';
                    }
                }
            }
        }

        // Parse INDEX FAST FULL SCAN
        if (preg_match_all('/INDEX\s+FAST\s+FULL\s+SCAN\s+.*?(\S+)/i', $queryPlan, $matches)) {
            if (!empty($matches[1])) {
                $indexNameRaw = $matches[1][0];
                // @phpstan-ignore-next-line
                $indexName = is_string($indexNameRaw) ? trim($indexNameRaw) : '';
                if ($indexName !== '') {
                    $indexName = preg_replace('/^[^"]*"\."/', '', $indexName);
                    $indexNameTrimmed = is_string($indexName) ? trim($indexName, '"\'') : '';
                    if ($indexNameTrimmed !== '') {
                        $plan->usedIndex = $indexNameTrimmed;
                        $plan->accessType = 'INDEX FAST FULL SCAN';
                    }
                }
            }
        }

        // Extract cost (Oracle uses cost-based optimizer)
        if (preg_match_all('/Cost=\s*(\d+)/i', $queryPlan, $matches)) {
            if (!empty($matches[1])) {
                $costs = array_map('intval', $matches[1]);
                $plan->totalCost = max($costs);
            }
        }

        // Extract cardinality (estimated rows)
        if (preg_match_all('/Cardinality=\s*(\d+)/i', $queryPlan, $matches)) {
            if (!empty($matches[1])) {
                $cardinalities = array_map('intval', $matches[1]);
                $plan->estimatedRows = max($cardinalities);
            }
        }

        // Parse JOIN types
        if (preg_match_all('/(NESTED LOOPS|HASH JOIN|MERGE JOIN|CARTESIAN JOIN)/i', $queryPlan, $matches)) {
            $joinTypes = array_map('trim', $matches[1]);
            $plan->joinTypes = array_values(array_unique($joinTypes));
        }

        // Detect warnings: full table scan without index
        if (!empty($plan->tableScans) && $plan->usedIndex === null) {
            foreach ($plan->tableScans as $table) {
                $plan->warnings[] = sprintf(
                    'Full table scan on "%s" without index usage',
                    $table
                );
            }
        }

        // Detect missing index usage
        if ($plan->accessType === 'TABLE ACCESS FULL' && $plan->usedIndex === null) {
            $plan->warnings[] = 'Query performs full table scan - consider adding an index';
        }

        // Parse SORT operations
        if (preg_match_all('/SORT\s+(?:ORDER BY|GROUP BY|AGGREGATE)/i', $queryPlan, $matches)) {
            $plan->warnings[] = 'Query requires sorting operation';
        }

        return $plan;
    }
}
