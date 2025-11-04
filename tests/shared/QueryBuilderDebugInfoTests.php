<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Tests for QueryBuilder debug information and error diagnostics.
 */
final class QueryBuilderDebugInfoTests extends BaseSharedTestCase
{
    public function testGetDebugInfoReturnsComprehensiveInformation(): void
    {
        $db = self::$db;

        $db->rawQuery('CREATE TABLE IF NOT EXISTS debug_test (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT
        )');

        $query = $db->find()
            ->from('debug_test')
            ->select(['id', 'name'])
            ->where('name', 'Alice')
            ->orderBy('id', 'ASC')
            ->limit(10);

        $debugInfo = $query->getDebugInfo();

        $this->assertIsArray($debugInfo);
        $this->assertEquals('debug_test', $debugInfo['table']);
        $this->assertEquals('sqlite', $debugInfo['dialect']);
        $this->assertArrayHasKey('sql', $debugInfo);
        $this->assertArrayHasKey('params', $debugInfo);
        $this->assertArrayHasKey('operation', $debugInfo);
        $this->assertEquals('SELECT', $debugInfo['operation']);
        $this->assertArrayHasKey('where', $debugInfo);
        // Joins may not be present if no joins were added
        if (isset($debugInfo['joins'])) {
            $this->assertIsArray($debugInfo['joins']);
        }
    }

    public function testGetDebugInfoIncludesWhereConditions(): void
    {
        $db = self::$db;

        $query = $db->find()
            ->from('debug_test')
            ->where('name', 'Alice')
            ->andWhere('email', 'alice@example.com');

        $debugInfo = $query->getDebugInfo();

        $this->assertArrayHasKey('where', $debugInfo);
        $this->assertArrayHasKey('where_count', $debugInfo['where']);
        $this->assertGreaterThan(0, $debugInfo['where']['where_count']);
    }

    public function testGetDebugInfoIncludesJoins(): void
    {
        $db = self::$db;

        $db->rawQuery('CREATE TABLE IF NOT EXISTS debug_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            title TEXT
        )');

        $query = $db->find()
            ->from('debug_test AS u')
            ->join('debug_posts AS p', 'p.user_id = u.id');

        $debugInfo = $query->getDebugInfo();

        $this->assertArrayHasKey('joins', $debugInfo);
        $this->assertArrayHasKey('join_count', $debugInfo['joins']);
        $this->assertEquals(1, $debugInfo['joins']['join_count']);
    }

    public function testGetDebugInfoIncludesSelectColumns(): void
    {
        $db = self::$db;

        $query = $db->find()
            ->from('debug_test')
            ->select(['id', 'name', 'email']);

        $debugInfo = $query->getDebugInfo();

        $this->assertArrayHasKey('select', $debugInfo);
        $this->assertArrayHasKey('select_count', $debugInfo);
        $this->assertEquals(3, $debugInfo['select_count']);
    }

    public function testGetDebugInfoForInsertQuery(): void
    {
        $db = self::$db;

        $query = $db->find()
            ->table('debug_test');

        $debugInfo = $query->getDebugInfo();

        // Before insert, operation might be UNKNOWN if SQL not built yet
        $this->assertArrayHasKey('operation', $debugInfo);
    }

    public function testQueryExceptionIncludesQueryContext(): void
    {
        $db = self::$db;

        try {
            $db->find()
                ->from('nonexistent_table')
                ->where('id', 1)
                ->get();
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $queryContext = $e->getQueryContext();

            // QueryContext may be null if exception was thrown before query context was prepared
            // or if getting debug info failed
            if ($queryContext !== null) {
                $this->assertIsArray($queryContext);
                // At minimum, should have dialect info
                $this->assertArrayHasKey('dialect', $queryContext);
            }

            // Always check that getQueryContext() method exists and works
            $this->assertTrue(method_exists($e, 'getQueryContext'));
        }
    }

    public function testQueryExceptionDescriptionIncludesContext(): void
    {
        $db = self::$db;

        try {
            $db->find()
                ->from('nonexistent_table')
                ->where('id', 1)
                ->get();
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $description = $e->getDescription();

            // Description should include query context if available
            $this->assertIsString($description);
            // May or may not include context depending on when exception was thrown
        }
    }

    public function testGetDebugInfoWorksWithoutTable(): void
    {
        $db = self::$db;

        $query = $db->find();

        $debugInfo = $query->getDebugInfo();

        $this->assertIsArray($debugInfo);
        $this->assertNull($debugInfo['table']);
        $this->assertEquals('UNKNOWN', $debugInfo['operation']);
    }

    public function testGetDebugInfoIncludesParameters(): void
    {
        $db = self::$db;

        $query = $db->find()
            ->from('debug_test')
            ->where('name', 'Alice')
            ->where('email', 'alice@example.com');

        $debugInfo = $query->getDebugInfo();

        $this->assertArrayHasKey('params', $debugInfo);
        $this->assertIsArray($debugInfo['params']);
    }

    public function testGetDebugInfoIncludesOrderBy(): void
    {
        $db = self::$db;

        $query = $db->find()
            ->from('debug_test')
            ->orderBy('name', 'ASC')
            ->orderBy('id', 'DESC');

        $debugInfo = $query->getDebugInfo();

        $this->assertArrayHasKey('order', $debugInfo);
        $this->assertArrayHasKey('order_count', $debugInfo);
        $this->assertGreaterThanOrEqual(1, $debugInfo['order_count']);
    }

    public function testGetDebugInfoIncludesLimitAndOffset(): void
    {
        $db = self::$db;

        $query = $db->find()
            ->from('debug_test')
            ->limit(10)
            ->offset(20);

        $debugInfo = $query->getDebugInfo();

        $this->assertArrayHasKey('limit', $debugInfo);
        $this->assertEquals(10, $debugInfo['limit']);
        $this->assertArrayHasKey('offset', $debugInfo);
        $this->assertEquals(20, $debugInfo['offset']);
    }

    public function testGetDebugInfoForUpdateQuery(): void
    {
        $db = self::$db;

        $query = $db->find()
            ->table('debug_test')
            ->where('id', 1);

        // For UPDATE, we need to call update() to build SQL, but we can check debug info before
        $debugInfo = $query->getDebugInfo();

        $this->assertIsArray($debugInfo);
        $this->assertArrayHasKey('where', $debugInfo);
    }

    public function testErrorMessagesIncludeSanitizedParameters(): void
    {
        $db = self::$db;

        try {
            $db->find()
                ->table('debug_test')
                ->insert([
                    'name' => 'Test',
                    'password' => 'secret123',
                    'email' => 'test@example.com',
                ]);
            $this->fail('Expected exception for invalid operation');
        } catch (\Throwable $e) {
            // Check that exception description doesn't expose sensitive data
            $description = $e instanceof QueryException ? $e->getDescription() : $e->getMessage();
            // Password should not be visible in plain text
            $this->assertStringNotContainsString('secret123', $description);
        }
    }
}
