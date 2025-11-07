<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis\parsers;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;

/**
 * Parser for PostgreSQL EXPLAIN output.
 */
class PostgreSQLExplainParser implements ExplainParserInterface
{
    public function parse(array $explainResults): ParsedExplainPlan
    {
        $plan = new ParsedExplainPlan();
        $plan->nodes = $explainResults;

        $queryPlan = '';
        foreach ($explainResults as $row) {
            $queryPlanValue = $row['QUERY PLAN'] ?? '';
            $queryPlan .= (is_string($queryPlanValue) ? $queryPlanValue : '') . "\n";
        }

        if ($queryPlan === '') {
            return $plan;
        }

        // Parse Sequential Scan (full table scan)
        if (preg_match_all('/Seq Scan\s+on\s+(\S+)/i', $queryPlan, $matches)) {
            foreach ($matches[1] as $table) {
                $table = trim($table);
                if (!in_array($table, $plan->tableScans, true)) {
                    $plan->tableScans[] = $table;
                }
            }
            $plan->accessType = 'Seq Scan';
        }

        // Parse Index Scan
        if (preg_match_all('/Index Scan\s+.*?on\s+\S+\s+using\s+(\S+)/i', $queryPlan, $matches)) {
            if (!empty($matches[1])) {
                $plan->usedIndex = trim($matches[1][0]);
                $plan->accessType = 'Index Scan';
            }
        }

        // Parse Index Only Scan
        if (preg_match_all('/Index Only Scan\s+.*?on\s+\S+\s+using\s+(\S+)/i', $queryPlan, $matches)) {
            if (!empty($matches[1])) {
                $plan->usedIndex = trim($matches[1][0]);
                $plan->accessType = 'Index Only Scan';
            }
        }

        // Parse Bitmap Index Scan
        if (preg_match_all('/Bitmap Index Scan\s+on\s+(\S+)/i', $queryPlan, $matches)) {
            if (!empty($matches[1])) {
                $plan->usedIndex = trim($matches[1][0]);
                $plan->accessType = 'Bitmap Index Scan';
            }
        }

        // Extract row estimates
        if (preg_match_all('/rows=(\d+)/', $queryPlan, $matches)) {
            $rowEstimates = array_map('intval', $matches[1]);
            if (!empty($rowEstimates)) {
                $plan->estimatedRows = max($rowEstimates);
            }
        }

        // Extract total cost (PostgreSQL)
        if (preg_match_all('/cost=([\d.]+)\.\.([\d.]+)/', $queryPlan, $matches)) {
            if (!empty($matches[2])) {
                $costs = array_map('floatval', $matches[2]);
                $plan->totalCost = max($costs);
            }
        }

        // Extract execution time from EXPLAIN ANALYZE (if available)
        if (preg_match_all('/actual time=([\d.]+)\.\.([\d.]+)/', $queryPlan, $matches)) {
            if (!empty($matches[2])) {
                $times = array_map('floatval', $matches[2]);
                $plan->executionTime = max($times);
            }
        }

        // Detect JOIN types
        if (preg_match_all('/(Nested Loop|Hash Join|Merge Join)\s+Join/i', $queryPlan, $matches)) {
            $plan->joinTypes = array_map('trim', $matches[1]);
            $plan->joinTypes = array_values(array_unique($plan->joinTypes));
        }

        // Detect warnings: no index used on sequential scan
        if (!empty($plan->tableScans) && $plan->usedIndex === null) {
            foreach ($plan->tableScans as $table) {
                $plan->warnings[] = sprintf(
                    'Sequential scan on "%s" without index usage',
                    $table
                );
            }
        }

        // Extract index names from query plan for possible keys
        if (preg_match_all('/using\s+(\S+)/i', $queryPlan, $matches)) {
            $plan->possibleKeys = array_map('trim', $matches[1]);
            $plan->possibleKeys = array_values(array_unique($plan->possibleKeys));
        }

        // Detect dependent subquery (PostgreSQL)
        // SubPlan typically indicates a correlated/dependent subquery
        if (preg_match('/SubPlan\s+\d+/i', $queryPlan)) {
            $plan->warnings[] = 'Dependent subquery detected';
        }

        return $plan;
    }
}
