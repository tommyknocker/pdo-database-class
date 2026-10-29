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
        if ($dialect->getDriverName() === 'sqlsrv') {
            return ['source' => 'unavailable', 'analysis' => null,
                'warnings' => ['This MSSQL adapter does not collect a real execution plan. No query was executed.']];
        }
        if ($dialect->getDriverName() === 'oci' && $params !== []) {
            return ['source' => 'unavailable', 'analysis' => null,
                'warnings' => ['The Oracle adapter cannot safely bind EXPLAIN parameters. Parameterized plan collection is unavailable; parameters were not interpolated.']];
        }

        $manager = new ParameterManager();
        $engine = new ExecutionEngine($connection, new RawValueResolver($connection, $manager), $manager);
        if ($dialect->getDriverName() === 'sqlite') {
            $statement = $connection->getPdo()->prepare('EXPLAIN QUERY PLAN ' . $sql);
            $statement->execute($params);
            $raw = $statement->fetchAll(\PDO::FETCH_ASSOC);
            $statement->closeCursor();
        } else {
            $raw = $this->db->explain($sql, $params);
        }
        if ($raw === []) {
            return ['source' => 'unavailable', 'analysis' => null,
                'warnings' => ['The database returned no execution plan. No execution measurements are available.']];
        }
        $analysis = (new ExplainAnalyzer($dialect, $engine))->analyze($raw, $table);

        return ['source' => 'database', 'analysis' => $analysis, 'warnings' =>
            $dialect->getDriverName() === 'oci'
                ? ['Oracle EXPLAIN PLAN uses the service PLAN_TABLE and requires suitable permissions.'] : []];
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
