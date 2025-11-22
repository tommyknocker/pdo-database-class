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
}
