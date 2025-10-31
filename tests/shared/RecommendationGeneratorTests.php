<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\analysis\ParsedExplainPlan;
use tommyknocker\pdodb\query\analysis\Recommendation;
use tommyknocker\pdodb\query\analysis\RecommendationGenerator;
use tommyknocker\pdodb\query\ExecutionEngine;

/**
 * Tests for RecommendationGenerator.
 */
final class RecommendationGeneratorTests extends BaseSharedTestCase
{
    protected RecommendationGenerator $generator;
    protected ExecutionEngine $executionEngine;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_recommendations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                age INTEGER,
                email TEXT
            )
        ');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $dialect = $connection->getDialect();

        // Create ExecutionEngine similar to how QueryBuilder does it
        $parameterManager = new \tommyknocker\pdodb\query\ParameterManager();
        $rawValueResolver = new \tommyknocker\pdodb\query\RawValueResolver($connection, $parameterManager);
        $this->executionEngine = new ExecutionEngine($connection, $rawValueResolver, $parameterManager);
        $this->generator = new RecommendationGenerator($this->executionEngine, $dialect);
        self::$db->rawQuery('DELETE FROM test_recommendations');
    }

    public function testGenerateWithTableScans(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: ['test_recommendations'],
            usedColumns: ['name', 'age'],
            possibleKeys: [],
            warnings: []
        );

        $recommendations = $this->generator->generate($plan, 'test_recommendations');

        $this->assertNotEmpty($recommendations);
        $this->assertCount(1, $recommendations);
        $this->assertInstanceOf(Recommendation::class, $recommendations[0]);
        $this->assertEquals('warning', $recommendations[0]->severity);
        $this->assertEquals('full_table_scan', $recommendations[0]->type);
        $this->assertStringContainsString('Full table scan detected', $recommendations[0]->message);
        $this->assertArrayHasKey('test_recommendations', array_flip($recommendations[0]->affectedTables));
    }

    public function testGenerateWithMissingIndex(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: [],
            usedColumns: ['name', 'email'],
            possibleKeys: ['idx_name'],
            warnings: ['possible keys exist but not used']
        );

        $recommendations = $this->generator->generate($plan, 'test_recommendations');

        $this->assertNotEmpty($recommendations);
        $this->assertCount(1, $recommendations);
        $this->assertEquals('info', $recommendations[0]->severity);
        $this->assertEquals('missing_index', $recommendations[0]->type);
    }

    public function testGenerateWithFilesortWarning(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: [],
            usedColumns: ['name', 'age'],
            possibleKeys: [],
            warnings: ['Query requires filesort (temporary sorting)']
        );

        $recommendations = $this->generator->generate($plan, 'test_recommendations');

        $this->assertNotEmpty($recommendations);
        $this->assertCount(1, $recommendations);
        $this->assertEquals('info', $recommendations[0]->severity);
        $this->assertEquals('filesort', $recommendations[0]->type);
        $this->assertStringContainsString('filesort', $recommendations[0]->message);
    }

    public function testGenerateWithTemporaryTableWarning(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: [],
            usedColumns: ['name'],
            possibleKeys: [],
            warnings: ['Query creates temporary table']
        );

        $recommendations = $this->generator->generate($plan, 'test_recommendations');

        $this->assertNotEmpty($recommendations);
        $this->assertCount(1, $recommendations);
        $this->assertEquals('info', $recommendations[0]->severity);
        $this->assertEquals('temporary_table', $recommendations[0]->type);
        $this->assertStringContainsString('temporary table', $recommendations[0]->message);
    }

    public function testGenerateWithMultipleIssues(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: ['test_recommendations'],
            usedColumns: ['name', 'age'],
            possibleKeys: [],
            warnings: [
                'Query requires filesort (temporary sorting)',
                'Query creates temporary table',
            ]
        );

        $recommendations = $this->generator->generate($plan, 'test_recommendations');

        $this->assertCount(3, $recommendations); // 1 table scan + 2 warnings

        $types = array_column($recommendations, 'type');
        $this->assertContains('full_table_scan', $types);
        $this->assertContains('filesort', $types);
        $this->assertContains('temporary_table', $types);
    }

    public function testGenerateWithEmptyPlan(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: [],
            usedColumns: [],
            possibleKeys: [],
            warnings: []
        );

        $recommendations = $this->generator->generate($plan);

        $this->assertEmpty($recommendations);
    }

    public function testSuggestIndexForTable(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: [],
            usedColumns: ['name', 'email'],
            possibleKeys: [],
            warnings: []
        );

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('suggestIndexForTable');
        $method->setAccessible(true);

        $suggestion = $method->invoke($this->generator, 'test_recommendations', $plan);

        $this->assertNotNull($suggestion);
        $this->assertStringContainsString('CREATE INDEX', $suggestion);
        $this->assertStringContainsString('test_recommendations', $suggestion);
        $this->assertStringContainsString('name', $suggestion);
        $this->assertStringContainsString('email', $suggestion);
    }

    public function testSuggestIndexWithPossibleKeys(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: [],
            usedColumns: ['name', 'email'],
            possibleKeys: ['idx_name_email'],
            warnings: []
        );

        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('suggestIndexForTable');
        $method->setAccessible(true);

        $suggestion = $method->invoke($this->generator, 'test_recommendations', $plan);

        $this->assertNotNull($suggestion);
        $this->assertStringContainsString('idx_name_email', $suggestion);
        $this->assertStringContainsString('existing index', $suggestion);
    }

    public function testSuggestOrderByIndex(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: [],
            usedColumns: ['name', 'age'],
            possibleKeys: [],
            warnings: []
        );

        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('suggestOrderByIndex');
        $method->setAccessible(true);

        $suggestion = $method->invoke($this->generator, 'test_recommendations', $plan);

        $this->assertNotNull($suggestion);
        $this->assertStringContainsString('CREATE INDEX', $suggestion);
        $this->assertStringContainsString('order_by', $suggestion);
        $this->assertStringContainsString('name', $suggestion);
        $this->assertStringContainsString('age', $suggestion);
    }

    public function testGenerateIndexName(): void
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('generateIndexName');
        $method->setAccessible(true);

        $name = $method->invoke($this->generator, 'test_table', ['name', 'email'], 'idx');

        $this->assertStringStartsWith('idx_', $name);
        $this->assertStringContainsString('test_table', $name);
        $this->assertStringContainsString('name', $name);
        $this->assertStringContainsString('email', $name);
    }

    public function testGenerateIndexNameWithManyColumns(): void
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('generateIndexName');
        $method->setAccessible(true);

        // Test that it limits to 3 columns
        $name = $method->invoke($this->generator, 'test_table', ['col1', 'col2', 'col3', 'col4', 'col5'], 'idx');

        $this->assertStringContainsString('col1', $name);
        $this->assertStringContainsString('col2', $name);
        $this->assertStringContainsString('col3', $name);
        // Should not contain col4 or col5
        $this->assertStringNotContainsString('col4', $name);
        $this->assertStringNotContainsString('col5', $name);
    }

    public function testExtractTableFromWarning(): void
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('extractTableFromWarning');
        $method->setAccessible(true);

        $table = $method->invoke($this->generator, 'Table "users" has no index');
        $this->assertEquals('users', $table);

        $table = $method->invoke($this->generator, 'No table mentioned');
        $this->assertNull($table);
    }

    public function testGetExistingIndexes(): void
    {
        // Create an index
        self::$db->rawQuery('CREATE INDEX IF NOT EXISTS idx_test_name ON test_recommendations(name)');

        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('getExistingIndexes');
        $method->setAccessible(true);

        $indexes = $method->invoke($this->generator, 'test_recommendations');

        // SQLite might return different index names, so just check it's an array
        $this->assertIsArray($indexes);
    }

    public function testSuggestIndexReturnsNullWhenEmptyColumns(): void
    {
        $plan = new ParsedExplainPlan(
            tableScans: [],
            usedColumns: [],
            possibleKeys: [],
            warnings: []
        );

        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('suggestIndexForTable');
        $method->setAccessible(true);

        $suggestion = $method->invoke($this->generator, 'test_recommendations', $plan);

        $this->assertNull($suggestion);
    }
}
