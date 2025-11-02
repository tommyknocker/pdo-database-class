<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use RuntimeException;
use tommyknocker\pdodb\helpers\Db;

/**
 * MiscTests for mysql.
 */
final class MiscTests extends BaseMySQLTestCase
{
    public function testEscape(): void
    {
        $id = self::$db->find()
            ->table('users')
            ->insert([
                'name' => Db::escape("O'Reilly"),
                'age' => 30,
            ]);
        $this->assertIsInt($id);

        $row = self::$db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();
        $this->assertEquals("O'Reilly", $row['name']);
        $this->assertEquals(30, $row['age']);
    }

    public function testExistsAndNotExists(): void
    {
        $db = self::$db;

        // Insert one record
        $db->find()->table('users')->insert([
            'name' => 'Existy',
            'company' => 'CheckCorp',
            'age' => 42,
            'status' => 'active',
        ]);

        // Check exists() - should return true
        $exists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->exists();

        $this->assertTrue($exists, 'Expected exists() to return true for matching row');

        // Check notExists() - should return false
        $notExists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->notExists();

        $this->assertFalse($notExists, 'Expected notExists() to return false for matching row');

        // Check exists() - for non-existent value
        $noMatchExists = $db->find()
            ->from('users')
            ->where(['name' => 'Ghosty'])
            ->exists();

        $this->assertFalse($noMatchExists, 'Expected exists() to return false for non-matching row');

        // Check notExists() - for non-existent value
        $noMatchNotExists = $db->find()
            ->from('users')
            ->where(['name' => 'Ghosty'])
            ->notExists();

        $this->assertTrue($noMatchNotExists, 'Expected notExists() to return true for non-matching row');
    }

    public function testFulltextMatchHelper(): void
    {
        $fulltext = Db::match('title, content', 'search term', 'natural');
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\values\FulltextMatchValue::class, $fulltext);
    }

    // Edge case: DISTINCT ON not supported on MySQL
    public function testDistinctOnThrowsExceptionOnMySQL(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DISTINCT ON is not supported');

        self::$db->find()
            ->from('test_users')
            ->distinctOn('email')
            ->get();
    }

    public function testMaterializedCteBasic(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS test_materialized_cte');
        $db->rawQuery('CREATE TABLE test_materialized_cte (id INT PRIMARY KEY, value INT)');
        $db->find()->table('test_materialized_cte')->insertMulti([
            ['id' => 1, 'value' => 100],
            ['id' => 2, 'value' => 200],
            ['id' => 3, 'value' => 300],
        ]);

        $results = $db->find()
            ->withMaterialized('high_values', function ($q) {
                $q->from('test_materialized_cte')->where('value', 150, '>');
            })
            ->from('high_values')
            ->orderBy('value')
            ->get();

        $this->assertCount(2, $results);
        $this->assertEquals(200, $results[0]['value']);
        $this->assertEquals(300, $results[1]['value']);

        // Verify SQL contains MATERIALIZE hint
        $sqlData = $db->find()
            ->withMaterialized('high_values', function ($q) {
                $q->from('test_materialized_cte')->where('value', 150, '>');
            })
            ->from('high_values')
            ->toSQL();

        $this->assertStringContainsString('MATERIALIZE', $sqlData['sql']);
    }

    public function testMaterializedCteWithColumns(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS test_materialized_cols');
        $db->rawQuery('CREATE TABLE test_materialized_cols (id INT PRIMARY KEY, val INT)');
        $db->find()->table('test_materialized_cols')->insertMulti([
            ['id' => 1, 'val' => 10],
            ['id' => 2, 'val' => 20],
        ]);

        $results = $db->find()
            ->withMaterialized('renamed', function ($q) {
                $q->from('test_materialized_cols')->select(['id', 'val']);
            }, ['record_id', 'value'])
            ->from('renamed')
            ->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('record_id', $results[0]);
        $this->assertArrayHasKey('value', $results[0]);
    }

    public function testMaterializedCteWithQueryBuilder(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS test_materialized_qb');
        $db->rawQuery('CREATE TABLE test_materialized_qb (id INT PRIMARY KEY, amount INT)');
        $db->find()->table('test_materialized_qb')->insertMulti([
            ['id' => 1, 'amount' => 150],
            ['id' => 2, 'amount' => 250],
        ]);

        $subQuery = $db->find()
            ->from('test_materialized_qb')
            ->where('amount', 200, '>');

        $results = $db->find()
            ->withMaterialized('filtered', $subQuery)
            ->from('filtered')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals(250, $results[0]['amount']);
    }

    public function testMaterializedCteMultiple(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS test_materialized_multi');
        $db->rawQuery('CREATE TABLE test_materialized_multi (id INT PRIMARY KEY, category VARCHAR(50), value INT)');
        $db->find()->table('test_materialized_multi')->insertMulti([
            ['id' => 1, 'category' => 'A', 'value' => 100],
            ['id' => 2, 'category' => 'A', 'value' => 200],
            ['id' => 3, 'category' => 'B', 'value' => 150],
            ['id' => 4, 'category' => 'B', 'value' => 250],
        ]);

        $results = $db->find()
            ->withMaterialized('cat_a', function ($q) {
                $q->from('test_materialized_multi')->where('category', 'A');
            })
            ->withMaterialized('cat_b', function ($q) {
                $q->from('test_materialized_multi')->where('category', 'B');
            })
            ->with('combined', Db::raw('SELECT * FROM cat_a UNION ALL SELECT * FROM cat_b'))
            ->from('combined')
            ->orderBy('value')
            ->get();

        $this->assertCount(4, $results);
        $this->assertEquals(100, $results[0]['value']);
        $this->assertEquals(250, $results[3]['value']);
    }
}
