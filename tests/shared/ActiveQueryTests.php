<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Test model for ActiveQuery tests.
 */
class ActiveQueryTestModel extends Model
{
    public static function tableName(): string
    {
        return 'test_active_query';
    }
}

/**
 * Tests for ActiveQuery methods that may not be fully covered.
 */
final class ActiveQueryTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_active_query (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                age INTEGER
            )
        ');
        ActiveQueryTestModel::setDb(self::$db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM test_active_query');

        // Insert test data
        self::$db->rawQuery("INSERT INTO test_active_query (name, age) VALUES ('Alice', 25), ('Bob', 30), ('Charlie', 35)");
    }

    public function testGetQueryBuilder(): void
    {
        $query = ActiveQueryTestModel::find();
        $queryBuilder = $query->getQueryBuilder();

        $this->assertInstanceOf(QueryBuilder::class, $queryBuilder);
    }

    public function testGetQueryBuilderCanBeUsedDirectly(): void
    {
        $query = ActiveQueryTestModel::find();
        $queryBuilder = $query->getQueryBuilder();

        // Use QueryBuilder directly
        $queryBuilder->where('age', 30, '>');
        $results = $query->all();

        $this->assertCount(1, $results);
        $this->assertEquals('Charlie', $results[0]->name);
    }

    public function testGetColumnThroughActiveQuery(): void
    {
        $query = ActiveQueryTestModel::find();
        $columns = $query->select('name')->getColumn();

        $this->assertIsArray($columns);
        $this->assertContains('Alice', $columns);
        $this->assertContains('Bob', $columns);
        $this->assertContains('Charlie', $columns);
    }

    public function testGetValueThroughActiveQuery(): void
    {
        $query = ActiveQueryTestModel::find();
        // Use COUNT aggregate which works reliably with getValue
        $count = $query->select(['count' => 'COUNT(*)'])->getValue();
        $this->assertGreaterThan(0, $count, 'Should have records');
        $this->assertEquals(3, $count, 'Should have 3 records');

        // Test getValue with aggregate function
        $query2 = ActiveQueryTestModel::find();
        $maxAge = $query2->select(['max_age' => 'MAX(age)'])->getValue();
        $this->assertEquals(35, $maxAge);
    }

    public function testGetThroughActiveQuery(): void
    {
        $query = ActiveQueryTestModel::find();
        $results = $query->where('age', 30, '>=')->orderBy('age')->get();

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        // Results are indexed arrays (0, 1) not by id
        $names = array_column($results, 'name');
        $this->assertContains('Bob', $names);
        $this->assertContains('Charlie', $names);
    }
}
