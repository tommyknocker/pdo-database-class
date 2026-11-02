<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\cache\QueryCompilationCache;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

/**
 * Tests for QueryCompilationCache class.
 */
final class QueryCompilationCacheTests extends BaseSharedTestCase
{
    protected function createCache(): QueryCompilationCache
    {
        $arrayCache = new ArrayCache();
        return new QueryCompilationCache($arrayCache);
    }

    public function testSetEnabled(): void
    {
        $cache = $this->createCache();
        $this->assertTrue($cache->isEnabled());

        $cache->setEnabled(false);
        $this->assertFalse($cache->isEnabled());

        $cache->setEnabled(true);
        $this->assertTrue($cache->isEnabled());
    }

    public function testIsEnabledWithNullCache(): void
    {
        $cache = new QueryCompilationCache(null);
        $this->assertFalse($cache->isEnabled());
    }

    public function testSetDefaultTtl(): void
    {
        $cache = $this->createCache();
        $this->assertEquals(86400, $cache->getDefaultTtl());

        $cache->setDefaultTtl(3600);
        $this->assertEquals(3600, $cache->getDefaultTtl());
    }

    public function testSetPrefix(): void
    {
        $cache = $this->createCache();
        $this->assertEquals('pdodb_compiled_', $cache->getPrefix());

        $cache->setPrefix('custom_prefix_');
        $this->assertEquals('custom_prefix_', $cache->getPrefix());
    }

    public function testGetCache(): void
    {
        $arrayCache = new ArrayCache();
        $cache = new QueryCompilationCache($arrayCache);
        $this->assertSame($arrayCache, $cache->getCache());
    }

    public function testGetCacheNull(): void
    {
        $cache = new QueryCompilationCache(null);
        $this->assertNull($cache->getCache());
    }

    public function testGetOrCompileCacheHit(): void
    {
        $arrayCache = new ArrayCache();
        $cache = new QueryCompilationCache($arrayCache);

        // First compile to generate the hash
        $structure = ['table' => 'users', 'select' => [], 'where' => [], 'distinct' => false, 'joins' => [], 'group_by' => null, 'having' => [], 'order_by' => [], 'options' => [], 'unions' => [], 'cte' => []];
        $compiled = $cache->getOrCompile(fn () => 'SELECT * FROM users', $structure, 'mysql');

        // Second call should use cache
        $result = $cache->getOrCompile(
            fn () => 'SELECT * FROM users WHERE id = 1',
            $structure,
            'mysql'
        );

        $this->assertEquals($compiled, $result);
    }

    public function testGetOrCompileCacheMiss(): void
    {
        $arrayCache = new ArrayCache();
        $cache = new QueryCompilationCache($arrayCache);

        $compiled = 'SELECT * FROM users WHERE id = 1';
        $result = $cache->getOrCompile(
            fn () => $compiled,
            ['table' => 'users', 'where' => [['sql' => 'id = :id', 'cond' => 'AND']]],
            'mysql'
        );

        $this->assertEquals($compiled, $result);
    }

    public function testGetOrCompileDisabled(): void
    {
        $arrayCache = new ArrayCache();
        $cache = new QueryCompilationCache($arrayCache);
        $cache->setEnabled(false);

        $compiled = 'SELECT * FROM users';
        $result = $cache->getOrCompile(
            fn () => $compiled,
            ['table' => 'users'],
            'mysql'
        );

        $this->assertEquals($compiled, $result);
    }

    public function testClearForgetsTheCompiledQueriesAndOnlyThose(): void
    {
        $arrayCache = new ArrayCache();
        $arrayCache->set('unrelated', 'kept');
        $cache = new QueryCompilationCache($arrayCache);

        $compilations = 0;
        $compile = static function () use (&$compilations): string {
            $compilations++;

            return 'SELECT 1';
        };
        $structure = ['table' => 'users', 'select' => ['id']];

        $cache->getOrCompile($compile, $structure, 'sqlite');
        $cache->getOrCompile($compile, $structure, 'sqlite');
        $this->assertSame(1, $compilations, 'the second call is served from the cache');

        $cache->clear();

        $cache->getOrCompile($compile, $structure, 'sqlite');
        $this->assertSame(2, $compilations, 'after clear() the query is compiled again');
        $this->assertSame('kept', $arrayCache->get('unrelated'));
    }

    public function testClearWithoutACacheDoesNothing(): void
    {
        $cache = new QueryCompilationCache(null);
        $cache->clear();

        $this->assertFalse($cache->isEnabled());
    }

    public function testHashQueryStructure(): void
    {
        $arrayCache = new ArrayCache();
        $cache = $this->createCache();

        $structure1 = [
            'table' => 'users',
            'select' => ['id', 'name'],
            'where' => [['sql' => 'id = :id', 'cond' => 'AND']],
            'distinct' => false,
            'joins' => [],
            'group_by' => null,
            'having' => [],
            'order_by' => [],
            'limit' => 10,
            'offset' => 0,
            'options' => [],
            'unions' => [],
            'cte' => [],
        ];

        $structure2 = [
            'table' => 'users',
            'select' => ['id', 'name'],
            'where' => [['sql' => 'id = :id', 'cond' => 'AND']],
            'distinct' => false,
            'joins' => [],
            'group_by' => null,
            'having' => [],
            'order_by' => [],
            'limit' => 10,
            'offset' => 0,
            'options' => [],
            'unions' => [],
            'cte' => [],
        ];

        // Same structure should produce same hash
        $result1 = $cache->getOrCompile(fn () => 'SQL1', $structure1, 'mysql');
        $result2 = $cache->getOrCompile(fn () => 'SQL2', $structure2, 'mysql');

        // Second call should use cache
        $this->assertEquals('SQL1', $result2);
    }

    public function testHashQueryStructureDifferentParameters(): void
    {
        $cache = $this->createCache();

        $structure1 = [
            'table' => 'users',
            'select' => ['id', 'name'],
            'where' => [['sql' => 'id = :id', 'cond' => 'AND']],
            'distinct' => false,
            'joins' => [],
            'group_by' => null,
            'having' => [],
            'order_by' => [],
            'limit' => 10,
            'offset' => 0,
            'options' => [],
            'unions' => [],
            'cte' => [],
        ];

        $structure2 = [
            'table' => 'users',
            'select' => ['id', 'email'], // Different select
            'where' => [['sql' => 'id = :id', 'cond' => 'AND']],
            'distinct' => false,
            'joins' => [],
            'group_by' => null,
            'having' => [],
            'order_by' => [],
            'limit' => 10,
            'offset' => 0,
            'options' => [],
            'unions' => [],
            'cte' => [],
        ];

        // Different structure should produce different hash
        $result1 = $cache->getOrCompile(fn () => 'SQL1', $structure1, 'mysql');
        $result2 = $cache->getOrCompile(fn () => 'SQL2', $structure2, 'mysql');

        // Should compile separately
        $this->assertEquals('SQL1', $result1);
        $this->assertEquals('SQL2', $result2);
    }

    public function testGetOrCompileWithEmptyCompiled(): void
    {
        $arrayCache = new ArrayCache();
        $cache = new QueryCompilationCache($arrayCache);

        $structure = ['table' => 'users', 'select' => [], 'where' => [], 'distinct' => false, 'joins' => [], 'group_by' => null, 'having' => [], 'order_by' => [], 'options' => [], 'unions' => [], 'cte' => []];
        $result = $cache->getOrCompile(fn () => '', $structure, 'mysql');

        $this->assertEquals('', $result);
    }

    public function testGetOrCompileWithInvalidArgumentException(): void
    {
        $invalidCache = new class () implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new InvalidArgumentException('Invalid key');
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }
        };

        $cache = new QueryCompilationCache($invalidCache);
        $structure = ['table' => 'users', 'select' => [], 'where' => [], 'distinct' => false, 'joins' => [], 'group_by' => null, 'having' => [], 'order_by' => [], 'options' => [], 'unions' => [], 'cte' => []];
        $compiled = 'SELECT * FROM users';
        $result = $cache->getOrCompile(fn () => $compiled, $structure, 'mysql');

        // Should fallback to compilation on InvalidArgumentException
        $this->assertEquals($compiled, $result);
    }

    public function testGetOrCompileWithThrowable(): void
    {
        $throwingCache = new class () implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new \RuntimeException('Cache error');
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }
        };

        $cache = new QueryCompilationCache($throwingCache);
        $structure = ['table' => 'users', 'select' => [], 'where' => [], 'distinct' => false, 'joins' => [], 'group_by' => null, 'having' => [], 'order_by' => [], 'options' => [], 'unions' => [], 'cte' => []];
        $compiled = 'SELECT * FROM users';
        $result = $cache->getOrCompile(fn () => $compiled, $structure, 'mysql');

        // Should fallback to compilation on Throwable
        $this->assertEquals($compiled, $result);
    }

    public function testHashQueryStructureWithProtectedMethod(): void
    {
        $cache = $this->createCache();
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('hashQueryStructure');

        $structure = [
            'table' => 'users',
            'select' => ['id', 'name'],
            'where' => [['sql' => 'id = :id', 'cond' => 'AND']],
            'distinct' => false,
            'joins' => [],
            'group_by' => null,
            'having' => [],
            'order_by' => [],
            'options' => [],
            'unions' => [],
            'cte' => [],
        ];

        $hash1 = $method->invoke($cache, $structure, 'mysql');
        $hash2 = $method->invoke($cache, $structure, 'mysql');

        // Same structure should produce same hash
        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1)); // SHA-256 produces 64 char hex string
    }

    public function testHashQueryStructureWithDifferentDrivers(): void
    {
        $cache = $this->createCache();
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('hashQueryStructure');

        $structure = [
            'table' => 'users',
            'select' => ['id', 'name'],
            'where' => [],
            'distinct' => false,
            'joins' => [],
            'group_by' => null,
            'having' => [],
            'order_by' => [],
            'options' => [],
            'unions' => [],
            'cte' => [],
        ];

        $hash1 = $method->invoke($cache, $structure, 'mysql');
        $hash2 = $method->invoke($cache, $structure, 'pgsql');

        // Different drivers should produce different hashes
        $this->assertNotEquals($hash1, $hash2);
    }

    public function testNormalizeArray(): void
    {
        $cache = $this->createCache();
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('normalizeArray');

        $input = ['a', 'b', 'c'];
        $result = $method->invoke($cache, $input);

        $this->assertEquals($input, $result);
    }

    public function testNormalizeConditionsWithArrayCondition(): void
    {
        $cache = $this->createCache();
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('normalizeConditions');

        $conditions = [
            ['sql' => 'id = :id', 'cond' => 'AND'],
            ['sql' => 'name = :name', 'cond' => 'OR'],
        ];

        $result = $method->invoke($cache, $conditions);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('sql_structure', $result[0]);
        $this->assertStringContainsString(':placeholder', $result[0]['sql_structure']);
    }

    public function testNormalizeConditionsWithStringCondition(): void
    {
        $cache = $this->createCache();
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('normalizeConditions');

        $conditions = ['id = :id', 'name = :name'];

        $result = $method->invoke($cache, $conditions);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertStringContainsString(':placeholder', $result[0]);
    }

    public function testNormalizeConditionsWithNonStringNonArray(): void
    {
        $cache = $this->createCache();
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('normalizeConditions');

        $conditions = [123, null, true];

        $result = $method->invoke($cache, $conditions);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals(123, $result[0]);
        $this->assertNull($result[1]);
        $this->assertTrue($result[2]);
    }

    public function testGetPrefix(): void
    {
        $cache = $this->createCache();
        $this->assertEquals('pdodb_compiled_', $cache->getPrefix());
    }

    /**
     * @return PdoDb A cached connection with ten rows (ids 1..10)
     */
    private function cachedDbWithRows(): PdoDb
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:'], [], null, new ArrayCache());
        $db->rawQuery('CREATE TABLE paged (id INTEGER PRIMARY KEY, n INTEGER)');
        for ($i = 1; $i <= 10; $i++) {
            $db->find()->table('paged')->insert(['n' => $i]);
        }

        return $db;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int)$row['id'], array_values($rows));
    }

    public function testDifferentLimitsOfTheSameQueryAreNotServedFromOneCompiledSql(): void
    {
        $db = $this->cachedDbWithRows();

        $two = self::ids($db->find()->from('paged')->orderBy('id')->limit(2)->get());
        $five = self::ids($db->find()->from('paged')->orderBy('id')->limit(5)->get());

        $this->assertSame([1, 2], $two);
        $this->assertSame([1, 2, 3, 4, 5], $five);
    }

    public function testDifferentOffsetsOfTheSameQueryAreNotServedFromOneCompiledSql(): void
    {
        $db = $this->cachedDbWithRows();

        $first = self::ids($db->find()->from('paged')->orderBy('id')->limit(2)->offset(0)->get());
        $later = self::ids($db->find()->from('paged')->orderBy('id')->limit(2)->offset(6)->get());

        $this->assertSame([1, 2], $first);
        $this->assertSame([7, 8], $later);
    }

    public function testPaginationReturnsTheRequestedPageWithTheCompilationCacheOn(): void
    {
        $db = $this->cachedDbWithRows();

        $pageOne = $db->find()->from('paged')->orderBy('id')->paginate(3, 1);
        $pageThree = $db->find()->from('paged')->orderBy('id')->paginate(3, 3);

        $this->assertSame([1, 2, 3], self::ids($pageOne->items()));
        $this->assertSame([7, 8, 9], self::ids($pageThree->items()));
    }
}
