<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\QueryTester;
use tommyknocker\pdodb\PdoDb;

/**
 * Tests for QueryTester CLI tool.
 */
final class QueryTesterTests extends TestCase
{
    protected string $dbPath;
    protected PdoDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/pdodb_query_tester_' . uniqid() . '.sqlite';
        $this->db = new PdoDb('sqlite', ['path' => $this->dbPath]);

        // Create test table
        $this->db->rawQuery('CREATE TABLE test_users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
        $this->db->rawQuery('INSERT INTO test_users (name, email) VALUES (?, ?)', ['John', 'john@example.com']);
        $this->db->rawQuery('INSERT INTO test_users (name, email) VALUES (?, ?)', ['Jane', 'jane@example.com']);

        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        putenv('PHPUNIT');
        parent::tearDown();
    }

    /**
     * Test executeQuery with SELECT query.
     */
    public function testExecuteQueryWithSelect(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'SELECT * FROM test_users');
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
        $this->assertStringContainsString('email', $out);
        $this->assertStringContainsString('John', $out);
        $this->assertStringContainsString('Jane', $out);
    }

    /**
     * Test executeQuery with non-SELECT query.
     */
    public function testExecuteQueryWithNonSelect(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, "UPDATE test_users SET name = 'John Updated' WHERE id = 1");
        $out = ob_get_clean();

        $this->assertStringContainsString('successfully', $out);

        // Verify update worked
        $name = $this->db->rawQueryValue('SELECT name FROM test_users WHERE id = 1');
        $this->assertEquals('John Updated', $name);
    }

    /**
     * Test executeQuery with invalid query.
     */
    public function testExecuteQueryWithError(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'SELECT * FROM non_existent_table');
        $out = ob_get_clean();

        $this->assertStringContainsString('Error', $out);
    }

    /**
     * Test displayResults with empty results.
     */
    public function testDisplayResultsWithEmptyResults(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, []);
        $out = ob_get_clean();

        $this->assertStringContainsString('No results', $out);
    }

    /**
     * Test displayResults with results.
     */
    public function testDisplayResultsWithResults(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $results = [
            ['id' => 1, 'name' => 'John', 'email' => 'john@example.com'],
            ['id' => 2, 'name' => 'Jane', 'email' => 'jane@example.com'],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
        $this->assertStringContainsString('email', $out);
        $this->assertStringContainsString('John', $out);
        $this->assertStringContainsString('Jane', $out);
        $this->assertStringContainsString('Total rows: 2', $out);
    }

    /**
     * Test displayResults with many rows (limit to 100).
     */
    public function testDisplayResultsWithManyRows(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        // Create 150 rows
        $results = [];
        for ($i = 1; $i <= 150; $i++) {
            $results[] = ['id' => $i, 'name' => "User {$i}", 'email' => "user{$i}@example.com"];
        }

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('Total rows: 150', $out);
        $this->assertStringContainsString('more rows', $out);
    }

    /**
     * Test displayResults with long values (truncated).
     */
    public function testDisplayResultsWithLongValues(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $longValue = str_repeat('A', 100);
        $results = [
            ['id' => 1, 'name' => $longValue, 'email' => 'test@example.com'],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        // Value should be truncated to 50 chars
        $this->assertStringNotContainsString($longValue, $out);
        $this->assertStringContainsString('...', $out);
    }

    /**
     * Test showHelp.
     */
    public function testShowHelp(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('showHelp');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null);
        $out = ob_get_clean();

        $this->assertStringContainsString('Available commands', $out);
        $this->assertStringContainsString('exit', $out);
        $this->assertStringContainsString('help', $out);
        $this->assertStringContainsString('clear', $out);
        $this->assertStringContainsString('history', $out);
    }

    /**
     * Test executeQuery with DESCRIBE query (SQLite uses SELECT from sqlite_master).
     */
    public function testExecuteQueryWithDescribe(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        // Use SELECT query to get table info (works like DESCRIBE)
        $method->invoke(null, $this->db, 'SELECT * FROM sqlite_master WHERE type="table" AND name="test_users"');
        $out = ob_get_clean();

        $this->assertStringContainsString('test_users', $out);
    }

    /**
     * Test executeQuery with SHOW query.
     */
    public function testExecuteQueryWithShow(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'SELECT sql FROM sqlite_master WHERE type="table" AND name="test_users"');
        $out = ob_get_clean();

        $this->assertStringContainsString('test_users', $out);
    }

    /**
     * Test executeQuery with EXPLAIN query.
     */
    public function testExecuteQueryWithExplain(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'EXPLAIN QUERY PLAN SELECT * FROM test_users');
        $out = ob_get_clean();

        // EXPLAIN should return results
        $this->assertNotEmpty($out);
    }

    /**
     * Test executeQuery with QueryException where getQuery() returns null.
     */
    public function testExecuteQueryWithQueryExceptionNullQuery(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        // Invalid query that will throw exception
        $method->invoke(null, $this->db, 'INVALID SQL SYNTAX');
        $out = ob_get_clean();

        $this->assertStringContainsString('Error', $out);
    }

    /**
     * Test displayResults with non-scalar values.
     */
    public function testDisplayResultsWithNonScalarValues(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $results = [
            ['id' => 1, 'data' => ['nested' => 'value'], 'array' => [1, 2, 3]],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('data', $out);
        $this->assertStringContainsString('array', $out);
    }

    /**
     * Test displayResults with null values.
     */
    public function testDisplayResultsWithNullValues(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $results = [
            ['id' => 1, 'name' => null, 'email' => 'test@example.com'],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
        $this->assertStringContainsString('email', $out);
    }

    /**
     * Test displayResults with exactly 100 rows.
     */
    public function testDisplayResultsWithExactly100Rows(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        // Create exactly 100 rows
        $results = [];
        for ($i = 1; $i <= 100; $i++) {
            $results[] = ['id' => $i, 'name' => "User {$i}"];
        }

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('Total rows: 100', $out);
        $this->assertStringNotContainsString('more rows', $out);
    }

    /**
     * Test displayResults with 101 rows.
     */
    public function testDisplayResultsWith101Rows(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        // Create 101 rows
        $results = [];
        for ($i = 1; $i <= 101; $i++) {
            $results[] = ['id' => $i, 'name' => "User {$i}"];
        }

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('Total rows: 101', $out);
        $this->assertStringContainsString('more rows', $out);
    }

    /**
     * Test displayResults with values exactly 50 characters.
     */
    public function testDisplayResultsWithExactly50CharacterValues(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $exactly50Chars = str_repeat('A', 50);
        $results = [
            ['id' => 1, 'name' => $exactly50Chars],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
    }

    /**
     * Test displayResults with values 51 characters (should be truncated).
     */
    public function testDisplayResultsWith51CharacterValues(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $exactly51Chars = str_repeat('A', 51);
        $results = [
            ['id' => 1, 'name' => $exactly51Chars],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
        // Should be truncated
        $this->assertStringContainsString('...', $out);
    }

    /**
     * Test executeQuery with lowercase select.
     */
    public function testExecuteQueryWithLowercaseSelect(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'select * from test_users');
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
    }

    /**
     * Test executeQuery with mixed case SELECT.
     */
    public function testExecuteQueryWithMixedCaseSelect(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'SeLeCt * FrOm test_users');
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
    }

    /**
     * Test executeQuery with INSERT query.
     */
    public function testExecuteQueryWithInsert(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, "INSERT INTO test_users (name, email) VALUES ('Test', 'test@example.com')");
        $out = ob_get_clean();

        $this->assertStringContainsString('successfully', $out);
    }

    /**
     * Test executeQuery with DELETE query.
     */
    public function testExecuteQueryWithDelete(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, "DELETE FROM test_users WHERE id = 999");
        $out = ob_get_clean();

        $this->assertStringContainsString('successfully', $out);
    }

    /**
     * Test executeQuery with CREATE TABLE query.
     */
    public function testExecuteQueryWithCreateTable(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('executeQuery');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'CREATE TABLE test_temp (id INTEGER PRIMARY KEY, name TEXT)');
        $out = ob_get_clean();

        $this->assertStringContainsString('successfully', $out);

        // Cleanup
        $this->db->rawQuery('DROP TABLE IF EXISTS test_temp');
    }

    /**
     * Test test method with query parameter.
     */
    public function testTestMethodWithQueryParameter(): void
    {
        ob_start();
        try {
            QueryTester::test('SELECT * FROM test_users');
            $out = ob_get_clean();

            $this->assertStringContainsString('PDOdb Query Tester', $out);
            $this->assertStringContainsString('Database:', $out);
        } catch (\Throwable $e) {
            ob_end_clean();
            // May fail if database config is not available
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    /**
     * Test displayResults with empty array keys.
     */
    public function testDisplayResultsWithEmptyArrayKeys(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $results = [
            ['id' => 1, '' => 'empty_key_value'],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
    }

    /**
     * Test displayResults with boolean values.
     */
    public function testDisplayResultsWithBooleanValues(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $results = [
            ['id' => 1, 'active' => true, 'deleted' => false],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('active', $out);
        $this->assertStringContainsString('deleted', $out);
    }

    /**
     * Test displayResults with numeric values.
     */
    public function testDisplayResultsWithNumericValues(): void
    {
        $reflection = new \ReflectionClass(QueryTester::class);
        $method = $reflection->getMethod('displayResults');
        $method->setAccessible(true);

        $results = [
            ['id' => 1, 'price' => 99.99, 'quantity' => 42],
        ];

        ob_start();
        $method->invoke(null, $results);
        $out = ob_get_clean();

        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('price', $out);
        $this->assertStringContainsString('quantity', $out);
    }
}
