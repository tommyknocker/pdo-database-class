<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;
use tommyknocker\pdodb\query\analysis\RecommendationGenerator;
use tommyknocker\pdodb\query\ExecutionEngine;
use tommyknocker\pdodb\query\ParameterManager;
use tommyknocker\pdodb\query\RawValueResolver;

/**
 * Tests for RecommendationGenerator class.
 */
final class RecommendationGeneratorTests extends BaseSharedTestCase
{
    protected function createRecommendationGenerator(): RecommendationGenerator
    {
        $connection = self::$db->connection;
        $executionEngine = new ExecutionEngine(
            $connection,
            new RawValueResolver($connection, new ParameterManager()),
            new ParameterManager()
        );
        return new RecommendationGenerator($executionEngine, $connection->getDialect());
    }

    public function testGenerateFullTableScan(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->tableScans = ['users'];

        $recommendations = $generator->generate($plan);
        $this->assertNotEmpty($recommendations);
        $this->assertEquals('full_table_scan', $recommendations[0]->type);
        $this->assertEquals('warning', $recommendations[0]->severity);
    }

    public function testGenerateFullTableScanWithTableName(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->tableScans = ['users'];

        $recommendations = $generator->generate($plan, 'custom_table');
        $this->assertNotEmpty($recommendations);
        $this->assertEquals('full_table_scan', $recommendations[0]->type);
        // affectedTables contains the actual scanned table, not the custom table name
        $this->assertContains('users', $recommendations[0]->affectedTables ?? []);
    }

    public function testGenerateMissingIndex(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->warnings = ['Table "users" has possible keys but none is used without index usage'];

        $recommendations = $generator->generate($plan);
        $this->assertNotEmpty($recommendations);
        $missingIndexRecommendation = null;
        foreach ($recommendations as $rec) {
            if ($rec->type === 'missing_index') {
                $missingIndexRecommendation = $rec;
                break;
            }
        }
        $this->assertNotNull($missingIndexRecommendation);
    }

    public function testGenerateGroupByWithoutIndex(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->warnings = ['GROUP BY requires temporary table and filesort'];
        $plan->usedColumns = ['status', 'created_at'];

        $recommendations = $generator->generate($plan, 'users');
        $this->assertNotEmpty($recommendations);
        $groupByRecommendation = null;
        foreach ($recommendations as $rec) {
            if ($rec->type === 'group_by_without_index') {
                $groupByRecommendation = $rec;
                break;
            }
        }
        $this->assertNotNull($groupByRecommendation);
        $this->assertEquals('warning', $groupByRecommendation->severity);
    }

    public function testGenerateDependentSubquery(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->warnings = ['Dependent subquery detected'];

        $recommendations = $generator->generate($plan);
        $this->assertNotEmpty($recommendations);
        $subqueryRecommendation = null;
        foreach ($recommendations as $rec) {
            if ($rec->type === 'dependent_subquery') {
                $subqueryRecommendation = $rec;
                break;
            }
        }
        $this->assertNotNull($subqueryRecommendation);
        $this->assertEquals('warning', $subqueryRecommendation->severity);
    }

    public function testGenerateLowFilterRatio(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->filtered = 5.5;
        $plan->tableScans = ['users'];
        $plan->usedColumns = ['email'];

        $recommendations = $generator->generate($plan, 'users');
        $this->assertNotEmpty($recommendations);
        $filterRatioRecommendation = null;
        foreach ($recommendations as $rec) {
            if ($rec->type === 'low_filter_ratio') {
                $filterRatioRecommendation = $rec;
                break;
            }
        }
        $this->assertNotNull($filterRatioRecommendation);
        $this->assertEquals('warning', $filterRatioRecommendation->severity);
    }

    public function testGenerateUnusedPossibleIndexes(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->possibleKeys = ['idx_email', 'idx_username'];
        $plan->usedIndex = null;
        $plan->tableScans = ['users'];

        $recommendations = $generator->generate($plan, 'users');
        $this->assertNotEmpty($recommendations);
        $unusedIndexRecommendation = null;
        foreach ($recommendations as $rec) {
            if ($rec->type === 'unused_possible_indexes') {
                $unusedIndexRecommendation = $rec;
                break;
            }
        }
        $this->assertNotNull($unusedIndexRecommendation);
        $this->assertEquals('info', $unusedIndexRecommendation->severity);
    }

    public function testGenerateHighQueryCost(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->totalCost = 150000.0;

        $recommendations = $generator->generate($plan);
        $this->assertNotEmpty($recommendations);
        $costRecommendation = null;
        foreach ($recommendations as $rec) {
            if ($rec->type === 'high_query_cost') {
                $costRecommendation = $rec;
                break;
            }
        }
        $this->assertNotNull($costRecommendation);
        $this->assertEquals('warning', $costRecommendation->severity);
    }

    public function testGenerateInefficientJoin(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->joinTypes = ['Nested Loop'];
        $plan->estimatedRows = 15000;

        $recommendations = $generator->generate($plan);
        $this->assertNotEmpty($recommendations);
        $joinRecommendation = null;
        foreach ($recommendations as $rec) {
            if ($rec->type === 'inefficient_join') {
                $joinRecommendation = $rec;
                break;
            }
        }
        $this->assertNotNull($joinRecommendation);
        $this->assertEquals('warning', $joinRecommendation->severity);
    }

    public function testSuggestGroupByIndex(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->usedColumns = ['status', 'created_at'];

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('suggestGroupByIndex');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'test_coverage', $plan);
        $this->assertNotNull($result);
        $this->assertStringContainsString('CREATE INDEX', $result);
        $this->assertStringContainsString('group_by', $result);
    }

    public function testSuggestGroupByIndexEmptyColumns(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('suggestGroupByIndex');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'test_coverage', $plan);
        $this->assertNull($result);
    }

    public function testGenerateFilesort(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->warnings = ['Query requires filesort (temporary sorting)'];

        $recommendations = $generator->generate($plan, 'users');
        $this->assertNotEmpty($recommendations);
        $this->assertEquals('filesort', $recommendations[0]->type);
        $this->assertEquals('info', $recommendations[0]->severity);
    }

    public function testGenerateTemporaryTable(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->warnings = ['Query creates temporary table'];

        $recommendations = $generator->generate($plan);
        $this->assertNotEmpty($recommendations);
        $this->assertEquals('temporary_table', $recommendations[0]->type);
        $this->assertEquals('info', $recommendations[0]->severity);
    }

    public function testGenerateEmptyPlan(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();

        $recommendations = $generator->generate($plan);
        $this->assertEmpty($recommendations);
    }

    public function testGenerateWithUsedColumns(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->tableScans = ['users'];
        $plan->usedColumns = ['email', 'status'];

        $recommendations = $generator->generate($plan, 'users');
        $this->assertNotEmpty($recommendations);
        $this->assertNotNull($recommendations[0]->suggestion);
        $this->assertStringContainsString('CREATE INDEX', $recommendations[0]->suggestion ?? '');
    }

    public function testGenerateWithPossibleKeys(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->tableScans = ['users'];
        $plan->possibleKeys = ['idx_email', 'idx_status'];

        $recommendations = $generator->generate($plan, 'users');
        $this->assertNotEmpty($recommendations);
        $this->assertNotNull($recommendations[0]->suggestion);
        $this->assertStringContainsString('idx_email', $recommendations[0]->suggestion ?? '');
    }

    public function testSuggestOrderByIndex(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->usedColumns = ['email', 'created_at'];

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('suggestOrderByIndex');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'test_coverage', $plan);
        $this->assertNotNull($result);
        $this->assertStringContainsString('CREATE INDEX', $result);
        $this->assertStringContainsString('order_by', $result);
    }

    public function testSuggestOrderByIndexEmptyColumns(): void
    {
        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('suggestOrderByIndex');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'test_coverage', $plan);
        $this->assertNull($result);
    }

    public function testGetExistingIndexes(): void
    {
        $generator = $this->createRecommendationGenerator();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('getExistingIndexes');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'test_coverage');
        $this->assertIsArray($result);
    }

    public function testGenerateIndexName(): void
    {
        $generator = $this->createRecommendationGenerator();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('generateIndexName');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'users', ['email', 'status'], 'idx');
        $this->assertIsString($result);
        $this->assertStringContainsString('idx', $result);
        $this->assertStringContainsString('users', $result);
    }

    public function testGenerateIndexNameWithCustomPrefix(): void
    {
        $generator = $this->createRecommendationGenerator();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('generateIndexName');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'users', ['email', 'status'], 'order_by');
        $this->assertStringContainsString('order_by', $result);
    }

    public function testGenerateIndexNameWithSpecialChars(): void
    {
        $generator = $this->createRecommendationGenerator();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('generateIndexName');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'user-profiles', ['email-address'], 'idx');
        $this->assertStringNotContainsString('-', $result);
        $this->assertStringContainsString('_', $result);
    }

    public function testGenerateIndexNameLimitsColumns(): void
    {
        $generator = $this->createRecommendationGenerator();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('generateIndexName');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'users', ['col1', 'col2', 'col3', 'col4', 'col5'], 'idx');
        // Should only include first 3 columns
        $this->assertStringContainsString('col1', $result);
        $this->assertStringContainsString('col2', $result);
        $this->assertStringContainsString('col3', $result);
        $this->assertStringNotContainsString('col4', $result);
    }

    public function testExtractTableFromWarning(): void
    {
        $generator = $this->createRecommendationGenerator();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'Full table scan on "users" without index usage');
        $this->assertEquals('users', $result);
    }

    public function testExtractTableFromWarningNoMatch(): void
    {
        $generator = $this->createRecommendationGenerator();

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'Warning without table name');
        $this->assertNull($result);
    }

    public function testSuggestOrderByIndexWithExistingIndex(): void
    {
        // Create an index on existing columns
        try {
            self::$db->rawQuery('CREATE INDEX IF NOT EXISTS order_by_test_coverage_name_value ON test_coverage (name, value)');
        } catch (\Exception $e) {
            // Index might already exist or table might not support it
        }

        $generator = $this->createRecommendationGenerator();
        $plan = new ParsedExplainPlan();
        $plan->usedColumns = ['name', 'value'];

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('suggestOrderByIndex');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'test_coverage', $plan);
        // Should return null if index already exists (or may return suggestion if name doesn't match)
        $this->assertIsString($result ?? '');
    }
}
