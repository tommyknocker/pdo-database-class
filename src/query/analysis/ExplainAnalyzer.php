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

        // Sort recommendations by severity
        usort($recommendations, function ($a, $b) {
            $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
            return ($severityOrder[$a->severity] ?? 3) <=> ($severityOrder[$b->severity] ?? 3);
        });

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

        // Unused possible indexes
        if (!empty($plan->possibleKeys) && empty($plan->usedIndex)) {
            $table = !empty($plan->tableScans) ? $plan->tableScans[0] : null;
            $issues[] = new Issue(
                severity: 'info',
                type: 'unused_possible_indexes',
                description: sprintf(
                    'Query has possible indexes but none is used: %s',
                    implode(', ', $plan->possibleKeys)
                ),
                table: $table
            );
        }

        // Low filter ratio (MySQL)
        if ($plan->filtered < 100.0 && $plan->filtered > 0.0 && $plan->filtered < 10.0) {
            $table = !empty($plan->tableScans) ? $plan->tableScans[0] : null;
            $issues[] = new Issue(
                severity: 'warning',
                type: 'low_filter_ratio',
                description: sprintf(
                    'Only %.1f%% of rows are filtered after index lookup. Consider improving index selectivity.',
                    $plan->filtered
                ),
                table: $table
            );
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

        // Inefficient JOIN (PostgreSQL)
        if (!empty($plan->joinTypes)) {
            foreach ($plan->joinTypes as $joinType) {
                if ($joinType === 'Nested Loop' && $plan->estimatedRows > 10000) {
                    $issues[] = new Issue(
                        severity: 'warning',
                        type: 'inefficient_join',
                        description: 'Nested Loop join detected on large dataset. Consider hash join or index optimization.'
                    );
                }
            }
        }

        // Dependent subquery
        foreach ($plan->warnings as $warning) {
            if (str_contains($warning, 'Dependent subquery')) {
                $issues[] = new Issue(
                    severity: 'warning',
                    type: 'dependent_subquery',
                    description: 'Dependent subquery detected. Consider rewriting as JOIN for better performance.'
                );
                break;
            }
        }

        // GROUP BY without index
        foreach ($plan->warnings as $warning) {
            if (str_contains($warning, 'GROUP BY requires temporary table and filesort')) {
                $issues[] = new Issue(
                    severity: 'warning',
                    type: 'group_by_without_index',
                    description: 'GROUP BY requires temporary table and filesort. Consider adding index on GROUP BY columns.'
                );
                break;
            }
        }

        // High query cost (PostgreSQL)
        if ($plan->totalCost !== null && $plan->totalCost > 100000) {
            $issues[] = new Issue(
                severity: 'warning',
                type: 'high_query_cost',
                description: sprintf(
                    'Query cost is very high (%.0f). Consider optimization.',
                    $plan->totalCost
                )
            );
        }

        // Access type analysis
        if ($plan->accessType === 'index' && $plan->estimatedRows > 1000) {
            $table = !empty($plan->tableScans) ? $plan->tableScans[0] : null;
            $issues[] = new Issue(
                severity: 'info',
                type: 'full_index_scan',
                description: sprintf(
                    'Full index scan detected (type: %s) on %d rows. Consider optimizing WHERE conditions.',
                    $plan->accessType,
                    $plan->estimatedRows
                ),
                table: $table
            );
        }

        return $issues;
    }

    /**
     * Extract table name from warning message.
     */
    protected function extractTableFromWarning(string $warning): ?string
    {
        // Try quoted table names: "table_name" or `table_name`
        if (preg_match('/["`]([^"`]+)["`]/', $warning, $matches)) {
            return $matches[1];
        }

        // Try after keywords: "on table_name", "from table_name"
        if (preg_match('/\b(?:on|from)\s+(\S+)/i', $warning, $matches)) {
            return trim($matches[1], '.,;');
        }

        // Try extracting from sequential scan pattern
        if (preg_match('/Sequential scan on\s+(\S+)/i', $warning, $matches)) {
            return trim($matches[1]);
        }

        // Try extracting from table scan pattern
        if (preg_match('/table scan on\s+["`]?(\S+)["`]?/i', $warning, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
