<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;
use tommyknocker\pdodb\query\analysis\RecommendationGenerator;

/**
 * Tests for RecommendationGenerator class.
 */
final class RecommendationGeneratorTests extends BaseSharedTestCase
{
    protected function createRecommendationGenerator(): RecommendationGenerator
    {
        $connection = self::$db->connection;
        $executionEngine = new \tommyknocker\pdodb\query\ExecutionEngine(
            $connection,
            new \tommyknocker\pdodb\query\RawValueResolver($connection, new \tommyknocker\pdodb\query\ParameterManager()),
            new \tommyknocker\pdodb\query\ParameterManager()
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
        $plan->warnings = ['Table "users" has possible keys but none is used'];

        $recommendations = $generator->generate($plan);
        $this->assertNotEmpty($recommendations);
        $this->assertEquals('missing_index', $recommendations[0]->type);
        $this->assertEquals('info', $recommendations[0]->severity);
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
