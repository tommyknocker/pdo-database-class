<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\cli\TableSearcher;

/**
 * Tests for TableSearcher class.
 */
final class TableSearcherTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test table for search
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS search_test (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT,
                age INTEGER,
                meta TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Clear test data
        self::$db->rawQuery('DELETE FROM search_test');
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name='search_test'");

        // Insert test data
        self::$db->find()->table('search_test')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 30,
            'meta' => '{"city": "New York", "tags": ["developer", "php"]}',
        ]);

        self::$db->find()->table('search_test')->insert([
            'name' => 'Jane Smith',
            'email' => 'jane@test.com',
            'age' => 25,
            'meta' => '{"city": "London", "tags": ["designer"]}',
        ]);

        self::$db->find()->table('search_test')->insert([
            'name' => 'Bob Johnson',
            'email' => 'bob@example.org',
            'age' => 35,
            'meta' => '{"city": "Paris", "tags": ["manager"]}',
        ]);
    }

    public function testSearchAcrossAllColumns(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('search_test', 'john');

        $this->assertNotEmpty($results);
        $this->assertGreaterThanOrEqual(1, count($results));

        // Should find John Doe (name) and bob@example.org (email contains 'john')
        $foundNames = array_column($results, 'name');
        $this->assertContains('John Doe', $foundNames);
    }

    public function testSearchInSpecificColumn(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('search_test', 'example', ['column' => 'email']);

        $this->assertNotEmpty($results);
        $this->assertGreaterThanOrEqual(2, count($results));

        // Should find john@example.com and bob@example.org
        $foundEmails = array_column($results, 'email');
        $this->assertContains('john@example.com', $foundEmails);
        $this->assertContains('bob@example.org', $foundEmails);
    }

    public function testSearchWithLimit(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('search_test', 'example', ['limit' => 1]);

        $this->assertLessThanOrEqual(1, count($results));
    }

    public function testSearchWithNumericValue(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('search_test', '30');

        $this->assertNotEmpty($results);
        // Should find John Doe (age = 30)
        $foundAges = array_column($results, 'age');
        $this->assertContains(30, $foundAges);
    }

    public function testSearchInJsonColumn(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('search_test', 'New York', ['searchInJson' => true]);

        $this->assertNotEmpty($results);
        // Should find John Doe (meta contains "New York")
        $foundNames = array_column($results, 'name');
        $this->assertContains('John Doe', $foundNames);
    }

    public function testSearchInJsonColumnDisabled(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('search_test', 'New York', ['searchInJson' => false]);

        // Should not find anything if JSON search is disabled
        // (unless "New York" appears in other text columns)
        $this->assertIsArray($results);
    }

    public function testSearchWithNonExistentColumn(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('search_test', 'test', ['column' => 'nonexistent']);

        // Should return empty array if column doesn't exist
        $this->assertEmpty($results);
    }

    public function testSearchWithNoResults(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('search_test', 'nonexistent_value_xyz');

        $this->assertEmpty($results);
    }

    public function testSearchWithEmptyTable(): void
    {
        // Create empty table
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS empty_search_test (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            )
        ');

        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('empty_search_test', 'test');

        $this->assertEmpty($results);

        // Cleanup
        self::$db->rawQuery('DROP TABLE IF EXISTS empty_search_test');
    }

    public function testSearchWithNonExistentTable(): void
    {
        $searcher = new TableSearcher(self::$db);
        $results = $searcher->search('nonexistent_table', 'test');

        // TableManager::describe returns empty array for non-existent tables
        // So search will return empty results
        $this->assertEmpty($results);
    }
}
