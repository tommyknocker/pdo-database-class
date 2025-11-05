<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\analysis\ExplainAnalyzer;

/**
 * Tests for ExplainAnalyzer class.
 */
final class ExplainAnalyzerTests extends BaseSharedTestCase
{
    protected function createExplainAnalyzer(): ExplainAnalyzer
    {
        $connection = self::$db->connection;
        $executionEngine = new \tommyknocker\pdodb\query\ExecutionEngine(
            $connection,
            new \tommyknocker\pdodb\query\RawValueResolver($connection, new \tommyknocker\pdodb\query\ParameterManager()),
            new \tommyknocker\pdodb\query\ParameterManager()
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
        $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\ExplainAnalysis::class, $analysis);
        $this->assertIsArray($analysis->issues);
        $this->assertIsArray($analysis->recommendations);
    }

    public function testAnalyzeWithTableName(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Use real EXPLAIN query for current dialect
        $explainResults = self::$db->find()->from('test_coverage')->explain();

        $analysis = $analyzer->analyze($explainResults, 'test_coverage');
        $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\ExplainAnalysis::class, $analysis);
        $this->assertIsArray($analysis->issues);
        $this->assertIsArray($analysis->recommendations);
    }

    public function testAnalyzeWithHighRowEstimate(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Create a plan with high row estimate
        $plan = new \tommyknocker\pdodb\query\analysis\ParsedExplainPlan();
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
        $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\ExplainAnalysis::class, $analysis);
        $this->assertIsArray($analysis->issues);
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
            $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\parsers\MySQLExplainParser::class, $parser);
        } elseif ($driver === 'pgsql') {
            $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\parsers\PostgreSQLExplainParser::class, $parser);
        } elseif ($driver === 'sqlite') {
            $this->assertInstanceOf(\tommyknocker\pdodb\query\analysis\parsers\SqliteExplainParser::class, $parser);
        }
    }

    public function testDetectIssuesWithTableScans(): void
    {
        $analyzer = $this->createExplainAnalyzer();

        // Create a plan with table scans
        $plan = new \tommyknocker\pdodb\query\analysis\ParsedExplainPlan();
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
        $plan = new \tommyknocker\pdodb\query\analysis\ParsedExplainPlan();
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
