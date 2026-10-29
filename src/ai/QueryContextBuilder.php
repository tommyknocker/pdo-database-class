<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use PDO;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/** Builds the same inspectable, bounded context consumed by all query providers. */
final class QueryContextBuilder
{
    public function __construct(private PdoDb $db)
    {
    }

    /** @param array<string, mixed> $options
     * @param array<int|string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function build(string $sql, ?string $table, ?ExplainAnalysis $analysis, array $options = [], array $params = []): array
    {
        $settings = (new AiQueryOptions($options))->context;
        $driver = $this->db->connection->getDialect()->getDriverName();
        $warnings = [];

        try {
            $version = (string)$this->db->connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            $version = null;
            $warnings[] = 'Database version unavailable';
        }
        $context = ['sql' => $settings['privacy'] === 'raw' ? $sql : SqlContextTokenizer::redact($sql), 'dialect' => $driver,
            'database' => ['version' => $version, 'plan_kind' => 'estimated', 'actual_execution_measured' => false,
                'capabilities' => ['real_plan' => $driver !== 'sqlsrv', 'bound_explain' => !in_array($driver, ['sqlsrv', 'oci'], true)]],
            'privacy' => ['mode' => $settings['privacy'], 'parameter_values_included' => false, 'raw_plan_included' => $settings['include_raw_plan'],
                'note' => 'Identifiers remain visible. Redaction is lexical, not guaranteed anonymization. Raw plans/defaults may contain values.'],
            'output_format' => !empty($options['structured']) ? 'structured' : 'text',
            'parameter_types' => array_map(static fn ($value) => get_debug_type($value), $params),
            'task' => ['goal' => $settings['goal'], 'constraints' => $settings['constraints'], 'language' => $settings['language']],
            'schema' => ['tables' => []], 'explain_plan' => $this->plan($analysis, $driver, $settings),
            'collection' => ['discovery' => $settings['tables'] === null ? 'best_effort_from_join' : 'explicit_physical_tables', 'complete' => true,
                'warnings' => [], 'catalog_queries' => 0, 'limits' => array_intersect_key($settings, array_flip(['max_tables', 'max_columns', 'max_indexes', 'max_plan_rows', 'max_bytes', 'max_collection_ms', 'max_catalog_queries']))]];
        $references = $settings['tables'] ?? SqlContextTokenizer::tables($sql);
        if ($settings['tables'] === null && $table !== null && $references === []) {
            $name = SqlContextTokenizer::table($table);
            if ($name !== null) {
                $references[] = ($name['schema'] !== null ? $name['schema'] . '.' : '') . $this->db->prefix . $name['table'];
            }
        }
        if ($settings['tables'] === null && preg_match('/\b(WITH|LATERAL|UNION)\b/i', $sql)) {
            $warnings[] = 'Complex SQL discovery is best effort; use context.tables for an explicit physical table list';
        }
        if (count($references) > $settings['max_tables']) {
            $warnings[] = 'Table limit reached';
        }
        $collector = new QueryMetadataCollector($this->db, $settings);
        foreach (array_slice($references, 0, $settings['max_tables']) as $reference) {
            try {
                $metadata = $collector->collect($reference);
                $name = ($metadata['schema'] ?? '') . '.' . ($metadata['table'] ?? $reference);
                $context['schema']['tables'][$name] = $metadata;
                if (!$metadata['complete']) {
                    $warnings[] = 'Incomplete metadata for ' . $name;
                }
            } catch (\Throwable) {
                $warnings[] = 'Metadata unavailable for a requested table; catalog access or collection budget may be limited';
            }
        }
        if ($context['explain_plan']['truncated'] ?? false) {
            $warnings[] = 'Plan row limit reached';
        }
        $context['collection']['catalog_queries'] = $collector->queryCount();
        $context['collection']['warnings'] = $warnings;
        $context['collection']['complete'] = $warnings === [];
        return $this->limit($context, $settings['max_bytes']);
    }

    /** @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function plan(?ExplainAnalysis $analysis, string $driver, array $settings): array
    {
        $available = $analysis !== null && $analysis->rawExplain !== [] && $driver !== 'sqlsrv';
        if (!$available) {
            return ['source' => 'unavailable', 'kind' => 'estimated', 'facts' => [], 'warnings' => ['No real execution plan is available. Do not infer scan types, cost or latency.']];
        }
        $plan = $analysis->plan;
        $hasRows = false;
        $hasFiltered = false;
        foreach ($analysis->rawExplain as $row) {
            $hasRows = $hasRows || (isset($row['rows']) && is_numeric($row['rows'])) || (isset($row['QUERY PLAN']) && preg_match('/rows=\d+/', (string)$row['QUERY PLAN']) === 1);
            $hasFiltered = $hasFiltered || (isset($row['filtered']) && is_numeric($row['filtered']));
        }
        $facts = ['table_scans' => $plan->tableScans, 'used_index' => $plan->usedIndex, 'used_columns' => $plan->usedColumns,
            'access_type' => $plan->accessType, 'estimated_rows' => $driver !== 'sqlite' && ($hasRows || $plan->estimatedRows > 0) ? $plan->estimatedRows : null,
            'possible_keys' => $plan->possibleKeys, 'filtered_percent' => $hasFiltered ? $plan->filtered : null,
            'join_types' => $plan->joinTypes, 'estimated_cost' => $plan->totalCost, 'execution_time_ms' => null,
            'index_cardinality' => $plan->indexCardinality];
        $nodes = [];
        foreach (array_slice($analysis->rawExplain, 0, $settings['max_plan_rows']) as $row) {
            if ($driver === 'sqlite' && isset($row['detail'])) {
                // EQP detail includes identifiers and index terms, not bound parameter values.
                $nodes[] = ['detail' => $settings['privacy'] === 'raw' ? $row['detail'] : SqlContextTokenizer::redact((string)$row['detail'])];
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $nodes[] = array_intersect_key($row, array_flip(['id', 'select_type', 'table', 'type', 'possible_keys', 'key', 'key_len', 'ref', 'rows', 'filtered', 'Extra']));
            } elseif ($driver === 'pgsql') {
                $text = (string)($row['QUERY PLAN'] ?? '');
                // Only structural plan lines; predicates can embed bound values.
                if (str_contains($text, 'cost=')) {
                    $node = ['operation' => trim(preg_replace('/\s+\(cost=.*$/', '', $text) ?? '')];
                    if (preg_match('/cost=([\d.]+)\.\.([\d.]+) rows=(\d+) width=(\d+)/', $text, $numbers)) {
                        $node += ['startup_cost' => (float)$numbers[1], 'total_cost' => (float)$numbers[2], 'estimated_rows' => (int)$numbers[3], 'width' => (int)$numbers[4]];
                    }
                    $nodes[] = $node;
                }
            }
        }
        $formatted = ['source' => 'database', 'kind' => 'estimated', 'facts' => $facts, 'nodes' => $nodes,
            'parser_warnings' => $plan->warnings, 'heuristics' => ['source' => 'library_rules_not_database_facts',
                'issues' => array_map(static fn ($issue) => get_object_vars($issue), $analysis->issues),
                'recommendations' => array_map(static fn ($rec) => get_object_vars($rec), $analysis->recommendations)],
            'truncated' => count($analysis->rawExplain) > $settings['max_plan_rows']];
        if ($settings['include_raw_plan']) {
            $formatted['raw_explain'] = array_slice($analysis->rawExplain, 0, $settings['max_plan_rows']);
        }
        return $formatted;
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function limit(array $context, int $maxBytes): array
    {
        $context['collection']['payload_truncated'] = false;
        while (strlen(json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') > $maxBytes) {
            $context['collection']['payload_truncated'] = true;
            $context['collection']['complete'] = false;
            if (isset($context['explain_plan']['raw_explain'])) {
                unset($context['explain_plan']['raw_explain']);
                $context['privacy']['raw_plan_included'] = false;
            } elseif ($context['schema']['tables'] !== []) {
                array_pop($context['schema']['tables']);
            } elseif (($context['explain_plan']['nodes'] ?? []) !== []) {
                array_pop($context['explain_plan']['nodes']);
            } elseif (isset($context['explain_plan']['heuristics'])) {
                unset($context['explain_plan']['heuristics']);
            } elseif (isset($context['task'])) {
                unset($context['task']);
            } elseif ($context['sql'] !== '[SQL omitted: context byte limit]') {
                $context['sql'] = '[SQL omitted: context byte limit]';
            } else {
                $context['explain_plan'] = ['source' => $context['explain_plan']['source'], 'warnings' => ['Plan omitted: context byte limit']];
                $context['parameter_types'] = [];
                $context['collection']['warnings'] = ['Context byte limit reached; some collected evidence omitted'];
            }
        }
        return $context;
    }
}
