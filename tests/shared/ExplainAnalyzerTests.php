<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalyzer;
use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;
use tommyknocker\pdodb\query\analysis\parsers\MySQLExplainParser;
use tommyknocker\pdodb\query\analysis\parsers\PostgreSQLExplainParser;
use tommyknocker\pdodb\query\analysis\parsers\SqliteExplainParser;
use tommyknocker\pdodb\query\ExecutionEngine;
use tommyknocker\pdodb\query\ParameterManager;
use tommyknocker\pdodb\query\RawValueResolver;

/**
 * Tests for ExplainAnalyzer class.
 */
final class ExplainAnalyzerTests extends BaseSharedTestCase
{
    protected function createExplainAnalyzer(): ExplainAnalyzer
    {
        $connection = self::$db->connection;
        $executionEngine = new ExecutionEngine(
            $connection,
            new RawValueResolver($connection, new ParameterManager()),
            new ParameterManager()
        );
        return new ExplainAnalyzer($connection->getDialect(), $executionEngine);
    }

    public function testAnalyzeEmptyResults(): void
    {
        $analyzer = $this->createExplainAnalyzer();
        $analysis = $analyzer->analyze([]);
        $this->assertEmpty($analysis->issues);
        $this->assertEmpty($analysis->recommendations);
    }

    public function testAnalyzeWithTableScans(): void
    {
        $analyzer = $this->createExplainAnalyzer();
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';

        // Use real EXPLAIN query for current dialect
        $explainResults = self::$db->find()->from('test_coverage')->explain();

        $analysis = $analyzer->analyze($explainResults);
        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);
        $this->assertIsArray($analysis->issues);
        $this->assertIsArray($analysis->recommendations);
    }

    public function testAnalyzeWithTableName(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Use real EXPLAIN query for current dialect
        $explainResults = self::$db->find()->from('test_coverage')->explain();

        $analysis = $analyzer->analyze($explainResults, 'test_coverage');
        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);
        $this->assertIsArray($analysis->issues);
        $this->assertIsArray($analysis->recommendations);
    }

    public function testAnalyzeWithHighRowEstimate(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Create a plan with high row estimate
        $plan = new ParsedExplainPlan();
        $plan->estimatedRows = 15000;

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $highRowIssue = null;
        foreach ($issues as $issue) {
            if ($issue->type === 'high_row_estimate') {
                $highRowIssue = $issue;
                break;
            }
        }
        $this->assertNotNull($highRowIssue);
    }

    public function testAnalyzeWithMissingIndex(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Use real EXPLAIN query for current dialect
        $explainResults = self::$db->find()->from('test_coverage')->explain();

        $analysis = $analyzer->analyze($explainResults);
        $this->assertInstanceOf(ExplainAnalysis::class, $analysis);
        $this->assertIsArray($analysis->issues);
    }

    public function testAnalyzeWithLowFilterRatio(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $plan = new ParsedExplainPlan();
        $plan->filtered = 5.5;
        $plan->tableScans = ['users'];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $lowFilterIssue = null;
        foreach ($issues as $issue) {
            if ($issue->type === 'low_filter_ratio') {
                $lowFilterIssue = $issue;
                break;
            }
        }
        $this->assertNotNull($lowFilterIssue);
        $this->assertEquals('warning', $lowFilterIssue->severity);
    }

    public function testAnalyzeWithUnusedPossibleIndexes(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $plan = new ParsedExplainPlan();
        $plan->possibleKeys = ['idx_email', 'idx_username'];
        $plan->usedIndex = null;
        $plan->tableScans = ['users'];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $unusedIndexIssue = null;
        foreach ($issues as $issue) {
            if ($issue->type === 'unused_possible_indexes') {
                $unusedIndexIssue = $issue;
                break;
            }
        }
        $this->assertNotNull($unusedIndexIssue);
        $this->assertEquals('info', $unusedIndexIssue->severity);
    }

    public function testAnalyzeWithDependentSubquery(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $plan = new ParsedExplainPlan();
        $plan->warnings = ['Dependent subquery detected'];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $subqueryIssue = null;
        foreach ($issues as $issue) {
            if ($issue->type === 'dependent_subquery') {
                $subqueryIssue = $issue;
                break;
            }
        }
        $this->assertNotNull($subqueryIssue);
        $this->assertEquals('warning', $subqueryIssue->severity);
    }

    public function testAnalyzeWithGroupByWithoutIndex(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $plan = new ParsedExplainPlan();
        $plan->warnings = ['GROUP BY requires temporary table and filesort'];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $groupByIssue = null;
        foreach ($issues as $issue) {
            if ($issue->type === 'group_by_without_index') {
                $groupByIssue = $issue;
                break;
            }
        }
        $this->assertNotNull($groupByIssue);
        $this->assertEquals('warning', $groupByIssue->severity);
    }

    public function testAnalyzeWithHighQueryCost(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $plan = new ParsedExplainPlan();
        $plan->totalCost = 150000.0;

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $costIssue = null;
        foreach ($issues as $issue) {
            if ($issue->type === 'high_query_cost') {
                $costIssue = $issue;
                break;
            }
        }
        $this->assertNotNull($costIssue);
        $this->assertEquals('warning', $costIssue->severity);
    }

    public function testAnalyzeWithInefficientJoin(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $plan = new ParsedExplainPlan();
        $plan->joinTypes = ['Nested Loop'];
        $plan->estimatedRows = 15000;

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $joinIssue = null;
        foreach ($issues as $issue) {
            if ($issue->type === 'inefficient_join') {
                $joinIssue = $issue;
                break;
            }
        }
        $this->assertNotNull($joinIssue);
        $this->assertEquals('warning', $joinIssue->severity);
    }

    public function testAnalyzeWithFullIndexScan(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $plan = new ParsedExplainPlan();
        $plan->accessType = 'index';
        $plan->estimatedRows = 5000;
        $plan->tableScans = ['users'];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $indexScanIssue = null;
        foreach ($issues as $issue) {
            if ($issue->type === 'full_index_scan') {
                $indexScanIssue = $issue;
                break;
            }
        }
        $this->assertNotNull($indexScanIssue);
        $this->assertEquals('info', $indexScanIssue->severity);
    }

    public function testExtractTableFromWarningWithQuotes(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $table = $method->invoke($analyzer, 'Full table scan on "users" without index usage');
        $this->assertEquals('users', $table);
    }

    public function testExtractTableFromWarningWithBackticks(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $table = $method->invoke($analyzer, 'Full table scan on `users` without index usage');
        $this->assertEquals('users', $table);
    }

    public function testExtractTableFromWarningWithOnKeyword(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $table = $method->invoke($analyzer, 'Sequential scan on users without index');
        $this->assertEquals('users', $table);
    }

    public function testExtractTableFromWarningWithSequentialScan(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $table = $method->invoke($analyzer, 'Sequential scan on users');
        $this->assertEquals('users', $table);
    }

    public function testRecommendationsSortedBySeverity(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Create a plan that will generate multiple recommendations
        $plan = new ParsedExplainPlan();
        $plan->tableScans = ['users'];
        $plan->warnings = ['Query creates temporary table'];
        $plan->filtered = 5.0;

        $reflection = new \ReflectionClass($analyzer);
        $recommendationGenerator = $reflection->getProperty('recommendationGenerator');
        $recommendationGenerator->setAccessible(true);
        $generator = $recommendationGenerator->getValue($analyzer);

        $recommendations = $generator->generate($plan, 'users');

        // Check that recommendations are sorted by severity
        if (count($recommendations) > 1) {
            $severities = array_map(fn ($r) => $r->severity, $recommendations);
            $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $sorted = true;
            for ($i = 0; $i < count($severities) - 1; $i++) {
                $current = $severityOrder[$severities[$i]] ?? 3;
                $next = $severityOrder[$severities[$i + 1]] ?? 3;
                if ($current > $next) {
                    $sorted = false;
                    break;
                }
            }
            $this->assertTrue($sorted, 'Recommendations should be sorted by severity');
        }
    }

    public function testGetParserForCurrentDialect(): void
    {
        $analyzer = $this->createExplainAnalyzer();
        $driver = getenv('PDODB_DRIVER') ?: 'sqlite';

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('getParser');
        $method->setAccessible(true);

        $parser = $method->invoke($analyzer);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->assertInstanceOf(MySQLExplainParser::class, $parser);
        } elseif ($driver === 'pgsql') {
            $this->assertInstanceOf(PostgreSQLExplainParser::class, $parser);
        } elseif ($driver === 'sqlite') {
            $this->assertInstanceOf(SqliteExplainParser::class, $parser);
        }
    }

    public function testDetectIssuesWithTableScans(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Create a plan with table scans
        $plan = new ParsedExplainPlan();
        $plan->tableScans = ['test_coverage'];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $hasTableScanIssue = false;
        foreach ($issues as $issue) {
            if ($issue->type === 'full_table_scan') {
                $hasTableScanIssue = true;
                break;
            }
        }
        $this->assertTrue($hasTableScanIssue);
    }

    public function testExtractTableFromWarning(): void
    {
        $analyzer = $this->createExplainAnalyzer();
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $result = $method->invoke($analyzer, 'Full table scan on "users" without index usage');
        $this->assertEquals('users', $result);
    }

    public function testExtractTableFromWarningNoMatch(): void
    {
        $analyzer = $this->createExplainAnalyzer();
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $result = $method->invoke($analyzer, 'Warning without table name');
        $this->assertNull($result);
    }

    public function testDetectIssuesWithMissingIndex(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Create a plan with warnings about missing index
        $plan = new ParsedExplainPlan();
        $plan->warnings = ['Table "test_coverage" has possible keys but none is used without index usage'];

        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('detectIssues');
        $method->setAccessible(true);

        $issues = $method->invoke($analyzer, $plan);
        $hasMissingIndexIssue = false;
        foreach ($issues as $issue) {
            if ($issue->type === 'missing_index') {
                $hasMissingIndexIssue = true;
                break;
            }
        }
        $this->assertTrue($hasMissingIndexIssue);
    }
}
