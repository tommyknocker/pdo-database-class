<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalyzer;
use tommyknocker\pdodb\query\ExecutionEngine;
use tommyknocker\pdodb\query\ParameterManager;
use tommyknocker\pdodb\query\RawValueResolver;

/** Collects database evidence without calling an AI provider or executing ANALYZE. */
class QueryInspectionService
{
    public function __construct(protected PdoDb $db)
    {
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return array{source: string, analysis: ExplainAnalysis|null, warnings: list<string>}
     */
    public function inspect(string $sql, array $params = [], ?string $table = null): array
    {
        $connection = $this->db->connection;
        $dialect = $connection->getDialect();
        $availability = $dialect->resolvePlanExplainAvailability($params);
        if (!$availability->collectPlan) {
            return [
                'source' => 'unavailable',
                'analysis' => null,
                'warnings' => $availability->unavailableReasons,
            ];
        }

        if (!$availability->realPlan) {
            return [
                'source' => 'unavailable',
                'analysis' => null,
                'warnings' => $availability->unavailableReasons !== []
                    ? $availability->unavailableReasons
                    : $availability->caveats,
            ];
        }

        $manager = new ParameterManager();
        $engine = new ExecutionEngine($connection, new RawValueResolver($connection, $manager), $manager);
        $raw = $dialect->executePlanExplain($connection->getPdo(), $sql, $params);
        if ($raw === []) {
            return [
                'source' => 'unavailable',
                'analysis' => null,
                'warnings' => ['The database returned no execution plan. No execution measurements are available.'],
            ];
        }
        $analysis = (new ExplainAnalyzer($dialect, $engine))->analyze($raw, $table);

        return [
            'source' => 'database',
            'analysis' => $analysis,
            'warnings' => $availability->caveats,
        ];
    }

    /** @return array<string, mixed> */
    public static function format(?ExplainAnalysis $analysis): array
    {
        if ($analysis === null) {
            return ['raw_explain' => [], 'plan' => null, 'issues' => [], 'recommendations' => []];
        }

        return [
            'raw_explain' => $analysis->rawExplain,
            'plan' => get_object_vars($analysis->plan),
            'issues' => array_map(static fn ($issue) => get_object_vars($issue), $analysis->issues),
            'recommendations' => array_map(static fn ($item) => get_object_vars($item), $analysis->recommendations),
        ];
    }
}
