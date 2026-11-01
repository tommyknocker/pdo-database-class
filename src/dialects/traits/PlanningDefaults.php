<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use PDO;
use tommyknocker\pdodb\dialects\PlanExplainAvailability;
use tommyknocker\pdodb\dialects\roles\DialectPlanningInterface;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * Default implementation of the EXPLAIN and plan analysis part of the dialect ({@see DialectPlanningInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait PlanningDefaults
{
    /**
     * {@inheritDoc}
     */
    public function executeExplain(PDO $pdo, string $sql, array $params = []): array
    {
        // Default implementation: build explain SQL and execute it
        $explainSql = $this->buildExplainSql($sql);
        $stmt = $pdo->prepare($explainSql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function executePlanExplain(PDO $pdo, string $sql, array $params = []): array
    {
        return $this->executeExplain($pdo, $sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    public function resolvePlanExplainAvailability(array $params = []): PlanExplainAvailability
    {
        return PlanExplainAvailability::available();
    }

    /**
     * {@inheritDoc}
     */
    public function buildAiExplainPlanSection(?ExplainAnalysis $analysis, array $settings): array
    {
        $availability = $this->resolvePlanExplainAvailability();
        if ($analysis === null || $analysis->rawExplain === [] || !$availability->realPlan) {
            return [
                'source' => 'unavailable',
                'kind' => 'estimated',
                'facts' => [],
                'warnings' => ['No real execution plan is available. Do not infer scan types, cost or latency.'],
            ];
        }

        $plan = $analysis->plan;
        $driverFacts = $this->buildAiExplainPlanFacts($analysis);
        $facts = [
            'table_scans' => $plan->tableScans,
            'used_index' => $plan->usedIndex,
            'used_columns' => $plan->usedColumns,
            'access_type' => $plan->accessType,
            'estimated_rows' => $driverFacts['estimated_rows'],
            'possible_keys' => $plan->possibleKeys,
            'filtered_percent' => $driverFacts['filtered_percent'],
            'join_types' => $plan->joinTypes,
            'estimated_cost' => $plan->totalCost,
            'execution_time_ms' => null,
            'index_cardinality' => $plan->indexCardinality,
        ];
        $nodes = [];
        foreach (array_slice($analysis->rawExplain, 0, (int)$settings['max_plan_rows']) as $row) {
            $node = $this->formatAiExplainPlanNode($row, $settings);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }
        $formatted = [
            'source' => 'database',
            'kind' => 'estimated',
            'facts' => $facts,
            'nodes' => $nodes,
            'parser_warnings' => $plan->warnings,
            'heuristics' => [
                'source' => 'library_rules_not_database_facts',
                'issues' => array_map(static fn ($issue) => get_object_vars($issue), $analysis->issues),
                'recommendations' => array_map(static fn ($rec) => get_object_vars($rec), $analysis->recommendations),
            ],
            'truncated' => count($analysis->rawExplain) > (int)$settings['max_plan_rows'],
        ];
        if (!empty($settings['include_raw_plan'])) {
            $formatted['raw_explain'] = array_slice($analysis->rawExplain, 0, (int)$settings['max_plan_rows']);
        }

        return $formatted;
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplainAnalyze(PDO $pdo, string $sql, array $params = []): array
    {
        // Default implementation: execute the explain analyze SQL directly
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $result;
    }
}
