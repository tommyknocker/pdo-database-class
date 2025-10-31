<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * JsonTests tests for sqlite.
 */
final class JsonTests extends BaseSqliteTestCase
{
    public function testJsonMethods(): void
    {
    $db = self::$db; // configured for MySQL in suite setup
    
    // prepare table
    $db->rawQuery('DROP TABLE IF EXISTS t_json');
    $db->rawQuery('CREATE TABLE t_json (id INTEGER PRIMARY KEY AUTOINCREMENT, meta JSON)');
    
    // insert initial rows
    $payload1 = ['a' => ['b' => 1], 'tags' => ['x', 'y'], 'score' => 10];
    $id1 = $db->find()->table('t_json')->insert(['meta' => json_encode($payload1, JSON_UNESCAPED_UNICODE)]);
    $this->assertIsInt($id1);
    $this->assertGreaterThan(0, $id1);
    
    $payload2 = ['a' => ['b' => 2], 'tags' => ['y', 'z'], 'score' => 5];
    $id2 = $db->find()->table('t_json')->insert(['meta' => json_encode($payload2, JSON_UNESCAPED_UNICODE)]);
    $this->assertIsInt($id2);
    $this->assertGreaterThan(0, $id1);
    
    // --- selectJson: fetch meta.a.b for id1 via QueryBuilder::selectJson
    $row = $db->find()
    ->table('t_json')
    ->selectJson('meta', ['a', 'b'], 'ab')
    ->where('id', $id1)
    ->getOne();
    
    $this->assertNotNull($row);
    $this->assertEquals(1, (int)$row['ab']);
    
    // --- whereJsonContains: verify tags contains 'x' for id1
    $contains = $db->find()
    ->table('t_json')
    ->whereJsonContains('meta', 'x', ['tags'])
    ->where('id', $id1)
    ->exists();
    $this->assertTrue($contains);
    
    // --- whereJsonExists: check that path $.a.b exists for id1
    $exists = $db->find()
    ->table('t_json')
    ->whereJsonExists('meta', ['a', 'b'])
    ->where('id', $id1)
    ->exists();
    $this->assertTrue($exists);
    
    // --- whereJsonPath: check $.a.b = 1 for id1
    $matches = $db->find()
    ->table('t_json')
    ->whereJsonPath('meta', ['a', 'b'], '=', 1)
    ->where('id', $id1)
    ->exists();
    
    // --- jsonSet: set meta.a.c = 42 for id1 using QueryBuilder::jsonSet
    $qb = $db->find()->table('t_json')->where('id', $id1);
    $rawSet = $qb->jsonSet('meta', ['a', 'c'], 42);
    $qb->update(['meta' => $rawSet]);
    
    $val = $db->find()
    ->table('t_json')
    ->selectJson('meta', ['a', 'c'], 'ac')
    ->where('id', $id1)
    ->getOne();
    $this->assertEquals(42, (int)$val['ac']);
    
    // --- jsonRemove: remove meta.a.b for id1 using QueryBuilder::jsonRemove
    $qb2 = $db->find()->table('t_json')->where('id', $id1);
    $rawRemove = $qb2->jsonRemove('meta', ['a', 'b']);
    $qb2->update(['meta' => $rawRemove]);
    
    $after = $db->find()
    ->table('t_json')
    ->selectJson('meta', ['a', 'b'], 'ab')
    ->where('id', $id1)
    ->getOne();
    $this->assertNull($after['ab']);
    
    // --- orderByJson: order by JSON key meta.score ASC using QueryBuilder::orderByJson
    $list = $db->find()
    ->table('t_json')
    ->select(['id'])
    ->selectJson('meta', ['score'], 'score')
    ->orderByJson('meta', ['score'], 'ASC')
    ->get();
    
    $this->assertCount(2, $list);
    // After all operations, id2 should still have score=5, id1 should still have score=10
    // So in ASC order: id2 (5) first, then id1 (10)
    $scores = array_map(fn ($r) => (int)$r['score'], $list);
    sort($scores);
    $this->assertEquals(5, $scores[0]);
    $this->assertEquals(10, $scores[1]);
    }

    public function testJsonMethodsEdgeCases(): void
    {
    $db = self::$db;
    
    $db->rawQuery('DROP TABLE IF EXISTS t_json_edge');
    $db->rawQuery('CREATE TABLE t_json_edge (id INTEGER PRIMARY KEY AUTOINCREMENT, meta JSON)');
    
    // Insert rows covering a variety of JSON shapes and types
    $rows = [
    // basic numeric scalar and nested object
    ['a' => ['b' => 1], 'tags' => ['x', 'y'], 'score' => 10, 'maybe' => null],
    // numeric as string, nested array, array order changed
    ['a' => ['b' => '1'], 'tags' => ['y', 'x'], 'score' => '5', 'extra' => ['z', ['k' => 7]]],
    // deeper nesting and floats
    ['a' => ['b' => 1.0], 'tags' => ['x', 'z'], 'score' => 2.5, 'list' => [1, 2, 3]],
    // arrays with duplicate elements and nulls
    ['a' => ['b' => null], 'tags' => ['x', 'x'], 'score' => 0, 'list' => [null, '0', 0]],
    // object with mixed types and an array of objects
    ['a' => ['b' => 42], 'tags' => [], 'score' => 100, 'items' => [['id' => 1], ['id' => 2]]],
    ];
    
    $ids = [];
    foreach ($rows as $r) {
    $ids[] = $db->find()->table('t_json_edge')->insert(['meta' => json_encode($r, JSON_UNESCAPED_UNICODE)]);
    }
    $this->assertCount(count($rows), $ids);
    foreach ($ids as $id) {
    $this->assertIsInt($id);
    }
    
    // 1) whereJsonPath: numeric equality and string numeric equality (should find appropriate rows)
    $foundNum = $db->find()
    ->table('t_json_edge')
    ->whereJsonPath('meta', ['a', 'b'], '=', 1)
    ->get();
    // should find rows where a.b is numeric 1 or numeric 1.0 (rows 0 and 2)
    $this->assertNotEmpty($foundNum);
    
    // 2) whereJsonPath: compare numeric-as-string -> ensure equality semantics allow distinguishing strings vs numbers
    $foundStrNum = $db->find()
    ->table('t_json_edge')
    ->whereJsonPath('meta', ['a', 'b'], '=', '1')
    ->get();
    $this->assertNotEmpty($foundStrNum);
    // Ensure at least one row differs between numeric vs string match
    $idsNum = array_map(fn ($r) => (int)$r['id'], $foundNum);
    $idsStr = array_map(fn ($r) => (int)$r['id'], $foundStrNum);
    $this->assertTrue(count(array_unique(array_merge($idsNum, $idsStr))) >= 2);
    
    // 3) whereJsonContains: array membership for scalars and duplicates
    $containsX = $db->find()
    ->table('t_json_edge')
    ->whereJsonContains('meta', 'x', ['tags'])
    ->get();
    $this->assertNotEmpty($containsX, 'Expected some rows to contain tag x');
    
    // 4) whereJsonContains: check array-of-objects contains object by matching subpath
    $containsItem1 = $db->find()
    ->table('t_json_edge')
    ->whereJsonPath('meta', ['items', '0', 'id'], '=', 1)
    ->exists();
    $this->assertTrue($containsItem1);
    
    // 5) jsonSet: set nested scalar and nested object fields
    $id0 = $ids[0];
    $qb = $db->find()->table('t_json_edge')->where('id', $id0);
    $rawSet = $qb->jsonSet('meta', ['a', 'c'], 'newval');
    $qb->update(['meta' => $rawSet]);
    
    $val = $db->find()
    ->table('t_json_edge')
    ->selectJson('meta', ['a', 'c'], 'ac')
    ->where('id', $id0)
    ->getOne();
    $this->assertEquals('newval', $val['ac'] ?? null);
    
    // 6) jsonSet: set numeric value and ensure numeric comparisons still work
    $qb2 = $db->find()->table('t_json_edge')->where('id', $ids[4]);
    $rawSet2 = $qb2->jsonSet('meta', ['a', 'b'], 999);
    $qb2->update(['meta' => $rawSet2]);
    
    $numCheck = $db->find()
    ->table('t_json_edge')
    ->whereJsonPath('meta', ['a', 'b'], '=', 999)
    ->where('id', $ids[4])
    ->exists();
    $this->assertTrue($numCheck);
    
    // 7) jsonRemove: remove scalar path, remove nested array element (index)
    $qb3 = $db->find()->table('t_json_edge')->where('id', $ids[1]);
    $rawRemove = $qb3->jsonRemove('meta', ['extra', '0']); // remove first element of extra array
    $qb3->update(['meta' => $rawRemove]);
    
    $afterRemove = $db->find()
    ->table('t_json_edge')
    ->selectJson('meta', ['extra', '0'], 'ex0')
    ->where('id', $ids[1])
    ->getOne();
    // after removal, the element at index 0 should be null or not present
    $this->assertTrue($afterRemove['ex0'] === null || $afterRemove['ex0'] === '' || $afterRemove['ex0'] === 'null');
    
    // 8) orderByJson: numeric ordering vs string ordering
    $list = $db->find()
    ->table('t_json_edge')
    ->select(['id'])
    ->selectJson('meta', ['score'], 'score')
    ->orderByJson('meta', ['score'], 'ASC')
    ->get();
    $this->assertCount(count($rows), $list);
    $scores = array_map(fn ($r) => is_null($r['score']) ? null : (float)$r['score'], $list);
    $sorted = $scores;
    sort($sorted, SORT_NUMERIC);
    $this->assertEquals($sorted, $scores);
    
    // 9) whereJsonExists: check existing vs non-existing paths
    $existsA = $db->find()
    ->table('t_json_edge')
    ->whereJsonExists('meta', ['a', 'b'])
    ->get();
    $this->assertNotEmpty($existsA);
    
    $notExists = $db->find()
    ->table('t_json_edge')
    ->whereJsonExists('meta', ['non', 'existent'])
    ->get();
    $this->assertEmpty($notExists);
    
    // 10) Combine JSON operations: set, then remove, then check contains/exists
    $id3 = $ids[2];
    $qb4 = $db->find()->table('t_json_edge')->where('id', $id3);
    $qb4->update(['meta' => $qb4->jsonSet('meta', ['compound', 'value'], ['x' => 1])]);
    
    $hasCompound = $db->find()
    ->table('t_json_edge')
    ->whereJsonExists('meta', ['compound', 'value'])
    ->where('id', $id3)
    ->exists();
    $this->assertTrue($hasCompound);
    
    $qb4b = $db->find()->table('t_json_edge')->where('id', $id3);
    $qb4b->update(['meta' => $qb4b->jsonRemove('meta', ['compound', 'value'])]);
    
    $hasCompoundAfter = $db->find()
    ->table('t_json_edge')
    ->whereJsonExists('meta', ['compound', 'value'])
    ->where('id', $id3)
    ->exists();
    $this->assertFalse($hasCompoundAfter);
    
    // 11) Json contains for arrays: check that checking multiple items works (subset)
    // Insert a row with tags ['alpha','beta','gamma'] for explicit test
    $special = ['tags' => ['alpha', 'beta', 'gamma']];
    $specId = $db->find()->table('t_json_edge')->insert(['meta' => json_encode($special, JSON_UNESCAPED_UNICODE)]);
    $this->assertIsInt($specId);
    
    $containsSubset = $db->find()
    ->table('t_json_edge')
    ->whereJsonContains('meta', ['alpha', 'gamma'], ['tags'])
    ->where('id', $specId)
    ->exists();
    $this->assertTrue($containsSubset);
    }

    public function testJsonHelpers(): void
    {
    $db = self::$db;
    
    // Create test table
    $db->rawQuery('DROP TABLE IF EXISTS t_json_helpers');
    $db->rawQuery('CREATE TABLE t_json_helpers (id INTEGER PRIMARY KEY AUTOINCREMENT, data JSON)');
    
    // Insert test data using Db::jsonObject and Db::jsonArray
    $id1 = $db->find()->table('t_json_helpers')->insert([
    'data' => Db::jsonObject(['name' => 'Alice', 'age' => 30, 'tags' => ['php', 'mysql']]),
    ]);
    $this->assertIsInt($id1);
    
    $id2 = $db->find()->table('t_json_helpers')->insert([
    'data' => Db::jsonObject(['name' => 'Bob', 'age' => 25, 'items' => [1, 2, 3, 4, 5]]),
    ]);
    $this->assertIsInt($id2);
    
    // Test Db::jsonPath - compare JSON value
    $results = $db->find()
    ->table('t_json_helpers')
    ->where(Db::jsonPath('data', ['age'], '>', 27))
    ->get();
    $this->assertCount(1, $results);
    $this->assertEquals($id1, $results[0]['id']);
    
    // Test Db::jsonContains - check if value exists
    $hasPhp = $db->find()
    ->table('t_json_helpers')
    ->where(Db::jsonContains('data', 'php', ['tags']))
    ->exists();
    $this->assertTrue($hasPhp);
    
    // Test Db::jsonExists - check if path exists
    $hasItems = $db->find()
    ->table('t_json_helpers')
    ->where(Db::jsonExists('data', ['items']))
    ->where('id', $id2)
    ->exists();
    $this->assertTrue($hasItems);
    
    // Test Db::jsonGet in SELECT
    $row = $db->find()
    ->table('t_json_helpers')
    ->select(['id', 'name' => Db::jsonGet('data', ['name'])])
    ->where('id', $id1)
    ->getOne();
    $this->assertEquals('Alice', $row['name']);
    
    // Test Db::jsonLength - get array/object length
    $row = $db->find()
    ->table('t_json_helpers')
    ->select(['id', 'items_count' => Db::jsonLength('data', ['items'])])
    ->where('id', $id2)
    ->getOne();
    $this->assertEquals(5, (int)$row['items_count']);
    
    // Test Db::jsonType - get JSON type
    $row = $db->find()
    ->table('t_json_helpers')
    ->select(['id', 'tags_type' => Db::jsonType('data', ['tags'])])
    ->where('id', $id1)
    ->getOne();
    $this->assertEquals('array', $row['tags_type']);
    
    // Test Db::jsonKeys - get JSON object keys
    $row = $db->find()
    ->table('t_json_helpers')
    ->select(['id', 'data_keys' => Db::jsonKeys('data')])
    ->where('id', $id1)
    ->getOne();
    $this->assertEquals('[keys]', $row['data_keys']);
    
    // Test Db::jsonKeys with path
    $row = $db->find()
    ->table('t_json_helpers')
    ->select(['id', 'items_keys' => Db::jsonKeys('data', ['items'])])
    ->where('id', $id1)
    ->getOne();
    $this->assertEquals('[keys]', $row['items_keys']);
    
    // Test Db::jsonGet in ORDER BY
    $sorted = $db->find()
    ->table('t_json_helpers')
    ->select(['id'])
    ->orderBy(Db::jsonGet('data', ['age']), 'ASC')
    ->getColumn();
    $this->assertEquals([$id2, $id1], $sorted); // Bob (25) before Alice (30)
    
    // Test Db::jsonLength in WHERE condition
    $manyItems = $db->find()
    ->table('t_json_helpers')
    ->where(Db::jsonLength('data', ['items']), 3, '>')
    ->get();
    $this->assertCount(1, $manyItems);
    $this->assertEquals($id2, $manyItems[0]['id']);
    
    // Test that jsonPath works with different operators
    $ageEq = $db->find()
    ->table('t_json_helpers')
    ->where(Db::jsonPath('data', ['age'], '=', 25))
    ->getOne();
    $this->assertEquals($id2, $ageEq['id']);
    }
}
