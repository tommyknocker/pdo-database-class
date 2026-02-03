<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDO;
use tommyknocker\pdodb\query\ExecutionEngine;
use tommyknocker\pdodb\query\ParameterManager;
use tommyknocker\pdodb\query\QueryProfiler;
use tommyknocker\pdodb\query\RawValueResolver;

/**
 * Tests for ExecutionEngine methods that may not be fully covered.
 */
final class ExecutionEngineTests extends BaseSharedTestCase
{
    protected ExecutionEngine $executionEngine;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_execution_engine (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                age INTEGER
            )
        ');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $parameterManager = new ParameterManager();
        $rawValueResolver = new RawValueResolver($connection, $parameterManager);
        $this->executionEngine = new ExecutionEngine($connection, $rawValueResolver, $parameterManager);
        self::$db->rawQuery('DELETE FROM test_execution_engine');
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name='test_execution_engine'");

        // Insert test data using direct SQL to ensure it exists
        self::$db->rawQuery("INSERT INTO test_execution_engine (name, age) VALUES ('Alice', 25), ('Bob', 30), ('Charlie', 35)");
    }

    public function testSetAndGetProfiler(): void
    {
        $profiler = new QueryProfiler();
        $this->executionEngine->setProfiler($profiler);

        $this->assertSame($profiler, $this->executionEngine->getProfiler());
    }

    public function testGetProfilerReturnsNullWhenNotSet(): void
    {
        $engine = new ExecutionEngine(
            self::$db->find()->getConnection(),
            new RawValueResolver(self::$db->find()->getConnection(), new ParameterManager()),
            new ParameterManager()
        );

        $this->assertNull($engine->getProfiler());
    }

    public function testSetAndGetFetchMode(): void
    {
        $this->executionEngine->setFetchMode(PDO::FETCH_NUM);
        $this->assertEquals(PDO::FETCH_NUM, $this->executionEngine->getFetchMode());

        $this->executionEngine->setFetchMode(PDO::FETCH_ASSOC);
        $this->assertEquals(PDO::FETCH_ASSOC, $this->executionEngine->getFetchMode());
    }

    public function testFetchColumn(): void
    {
        // Create a fresh execution engine to ensure clean state
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $parameterManager = new ParameterManager();
        $rawValueResolver = new RawValueResolver($connection, $parameterManager);
        $engine = new ExecutionEngine($connection, $rawValueResolver, $parameterManager);

        // Verify data exists first using fetchAll
        $results = $engine->fetchAll('SELECT * FROM test_execution_engine WHERE id = 1', []);
        $this->assertNotEmpty($results, 'Test data should exist');

        // Now test fetchColumn with positional parameters
        $result = $engine->fetchColumn(
            'SELECT name FROM test_execution_engine WHERE id = ?',
            [1]
        );

        $this->assertNotFalse($result, 'fetchColumn should return a value');
        $this->assertEquals('Alice', $result);
    }

    public function testFetchColumnWithParams(): void
    {
        // Create a fresh execution engine to ensure clean state
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $parameterManager = new ParameterManager();
        $rawValueResolver = new RawValueResolver($connection, $parameterManager);
        $engine = new ExecutionEngine($connection, $rawValueResolver, $parameterManager);

        // Test fetchColumn with positional parameters
        $result = $engine->fetchColumn(
            'SELECT name FROM test_execution_engine WHERE id = ?',
            [2]
        );

        $this->assertNotFalse($result, 'fetchColumn should return a value');
        $this->assertEquals('Bob', $result);
    }

    public function testFetchAllWithFetchMode(): void
    {
        $this->executionEngine->setFetchMode(PDO::FETCH_NUM);
        $results = $this->executionEngine->fetchAll(
            'SELECT id FROM test_execution_engine ORDER BY id',
            []
        );

        $this->assertCount(3, $results);
        $this->assertIsArray($results[0]);
        // Verify that results are numeric arrays (not associative)
        $this->assertArrayHasKey(0, $results[0]);
        $this->assertArrayNotHasKey('id', $results[0]);
        // First result should contain the first id
        $firstId = $results[0][0];
        $this->assertIsNumeric($firstId);
    }

    public function testExecuteStatementWithProfiler(): void
    {
        $profiler = new QueryProfiler();
        $profiler->enable();
        $engine = new ExecutionEngine(
            self::$db->find()->getConnection(),
            new RawValueResolver(self::$db->find()->getConnection(), new ParameterManager()),
            new ParameterManager(),
            $profiler
        );

        $stmt = $engine->executeStatement(
            'SELECT * FROM test_execution_engine WHERE id = 1',
            []
        );

        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $stmt->closeCursor();

        // Check that profiler tracked the query
        $stats = $profiler->getStats();
        $this->assertNotEmpty($stats);
    }

    public function testSetQueryContextAndGetQueryContext(): void
    {
        $this->assertNull($this->executionEngine->getQueryContext());

        $context = ['sql' => 'SELECT 1', 'table' => 'test'];
        $result = $this->executionEngine->setQueryContext($context);
        $this->assertSame($this->executionEngine, $result);
        $this->assertSame($context, $this->executionEngine->getQueryContext());

        $this->executionEngine->setQueryContext(null);
        $this->assertNull($this->executionEngine->getQueryContext());
    }
}
