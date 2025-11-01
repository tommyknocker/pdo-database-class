<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\MySQLExplainParser;
use tommyknocker\pdodb\query\analysis\parsers\PostgreSQLExplainParser;
use tommyknocker\pdodb\query\analysis\parsers\SqliteExplainParser;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;

/**
 * Analyzes EXPLAIN output and provides optimization recommendations.
 */
class ExplainAnalyzer
{
    protected RecommendationGenerator $recommendationGenerator;

    public function __construct(
        protected DialectInterface $dialect,
        protected ExecutionEngineInterface $executionEngine
    ) {
        $this->recommendationGenerator = new RecommendationGenerator(
            $this->executionEngine,
            $this->dialect
        );
    }

    /**
     * Analyze EXPLAIN output with recommendations.
     *
     * @param array<int, array<string, mixed>> $explainResults Raw EXPLAIN output
     * @param string|null $tableName Optional table name for index suggestions
     *
     * @return ExplainAnalysis Result with recommendations
     */
    public function analyze(array $explainResults, ?string $tableName = null): ExplainAnalysis
    {
        $parser = $this->getParser();
        $parsedPlan = $parser->parse($explainResults);

        $issues = $this->detectIssues($parsedPlan);
        $recommendations = $this->recommendationGenerator->generate($parsedPlan, $tableName);

        return new ExplainAnalysis(
            $explainResults,
            $parsedPlan,
            $issues,
            $recommendations
        );
    }

    /**
     * Get appropriate parser for current dialect.
     */
    protected function getParser(): ExplainParserInterface
    {
        $driverName = $this->dialect->getDriverName();

        return match ($driverName) {
            'mysql' => new MySQLExplainParser(),
            'mariadb' => new MySQLExplainParser(), // MariaDB uses MySQL-compatible EXPLAIN format
            'pgsql' => new PostgreSQLExplainParser(),
            'sqlite' => new SqliteExplainParser(),
            default => throw new \RuntimeException(
                sprintf('Unsupported dialect for EXPLAIN analysis: %s', $driverName)
            ),
        };
    }

    /**
     * Detect issues from parsed plan.
     *
     * @return array<Issue> List of detected issues
     */
    protected function detectIssues(ParsedExplainPlan $plan): array
    {
        $issues = [];

        // Full table scan issue
        if (!empty($plan->tableScans)) {
            foreach ($plan->tableScans as $table) {
                $issues[] = new Issue(
                    severity: 'warning',
                    type: 'full_table_scan',
                    description: sprintf('Full table scan detected on table "%s"', $table),
                    table: $table
                );
            }
        }

        // Missing index issue
        if (!empty($plan->warnings)) {
            foreach ($plan->warnings as $warning) {
                if (str_contains($warning, 'without index usage')) {
                    $table = $this->extractTableFromWarning($warning);
                    $issues[] = new Issue(
                        severity: 'info',
                        type: 'missing_index',
                        description: $warning,
                        table: $table
                    );
                }
            }
        }

        // High row estimate issue
        if ($plan->estimatedRows > 10000) {
            $issues[] = new Issue(
                severity: 'info',
                type: 'high_row_estimate',
                description: sprintf(
                    'Query estimates scanning %d rows, which may indicate performance issues',
                    $plan->estimatedRows
                ),
                table: null
            );
        }

        return $issues;
    }

    /**
     * Extract table name from warning message.
     */
    protected function extractTableFromWarning(string $warning): ?string
    {
        if (preg_match('/"([^"]+)"/', $warning, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
