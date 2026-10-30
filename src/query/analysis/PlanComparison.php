<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis;

/**
 * Compares two plans the caller collected. It does not execute DDL or model SQL.
 */
class PlanComparison
{
    /**
     * @return array<string, mixed>
     */
    public static function compare(ExplainAnalysis $before, ExplainAnalysis $after): array
    {
        $left = static::snapshot($before);
        $right = static::snapshot($after);
        $notes = [
            'Apply schema changes yourself on a test database before collecting the second plan.',
            'This comparison does not execute generated SQL.',
        ];
        if ($before->plan->executionTime !== null || $after->plan->executionTime !== null) {
            $notes[] = 'A timing value comes from an actual execution, such as PostgreSQL EXPLAIN ANALYZE. An estimated plan does not measure latency.';
        }

        return [
            'before' => $left,
            'after' => $right,
            'scan_removed' => $left['table_scans'] !== [] && $right['table_scans'] === [],
            'index_changed' => $left['used_index'] !== $right['used_index'],
            'temporary_sort_removed' => $left['temporary_sort'] && !$right['temporary_sort'],
            'notes' => $notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function snapshot(ExplainAnalysis $analysis): array
    {
        return [
            'access_type' => $analysis->plan->accessType,
            'used_index' => $analysis->plan->usedIndex,
            'table_scans' => $analysis->plan->tableScans,
            'temporary_sort' => $analysis->plan->temporarySort,
            'estimated_rows' => $analysis->plan->estimatedRows,
            'execution_time' => $analysis->plan->executionTime,
        ];
    }
}
