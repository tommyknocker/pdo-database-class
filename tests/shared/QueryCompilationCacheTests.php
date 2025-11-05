<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

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

    public function testClear(): void
    {
        $cache = $this->createCache();
        // clear() is a placeholder method, so we just test it doesn't throw
        $cache->clear();
        $this->assertTrue(true);
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
        $invalidCache = new class () implements \Psr\SimpleCache\CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new \Psr\SimpleCache\InvalidArgumentException('Invalid key');
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
        $throwingCache = new class () implements \Psr\SimpleCache\CacheInterface {
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
}
