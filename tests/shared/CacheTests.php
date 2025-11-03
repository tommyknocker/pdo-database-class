<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cache\CacheConfig;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\cache\QueryCacheKey;
use tommyknocker\pdodb\connection\DialectRegistry;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\cache\QueryCompilationCache;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

/**
 * CacheTests tests for shared.
 */
final class CacheTests extends BaseSharedTestCase
{
    public function testDialectRegistryAndCacheManagerCoverage(): void
    {
        // DialectRegistry
        $drivers = DialectRegistry::getSupportedDrivers();
        $this->assertNotEmpty($drivers);
        $this->assertTrue(DialectRegistry::isSupported('sqlite'));
        $dialect = DialectRegistry::resolve('sqlite');
        $this->assertEquals('sqlite', $dialect->getDriverName());

        // CacheManager basic ops
        $cache = new ArrayCache();
        $cm = new CacheManager($cache, ['enabled' => true, 'default_ttl' => 60, 'prefix' => 'p']);
        $key = $cm->generateKey('SELECT 1', ['a' => 1], 'sqlite');
        $this->assertIsString($key);
        $this->assertFalse($cm->has($key));
        $this->assertTrue($cm->set($key, 'val'));
        $this->assertTrue($cm->has($key));
        $this->assertEquals('val', $cm->get($key));
        $this->assertTrue($cm->delete($key));
        $this->assertTrue($cm->clear());

        // Test getConfig and getCache
        $config = $cm->getConfig();
        $this->assertInstanceOf(CacheConfig::class, $config);
        $this->assertEquals('p', $config->getPrefix());
        $this->assertEquals(60, $config->getDefaultTtl());
        $this->assertTrue($config->isEnabled());

        $retrievedCache = $cm->getCache();
        $this->assertSame($cache, $retrievedCache);
    }

    public function testCacheManagerWithDisabledCache(): void
    {
        $cache = new ArrayCache();
        $cm = new CacheManager($cache, ['enabled' => false]);

        $key = 'test_key';
        $value = 'test_value';

        // When cache is disabled, set should return false
        $this->assertFalse($cm->set($key, $value));

        // When cache is disabled, get should return null
        $this->assertNull($cm->get($key));

        // When cache is disabled, has should return false
        $this->assertFalse($cm->has($key));

        // delete and clear should still work (they operate on cache directly)
        $cache->set($key, $value); // Set directly to cache
        $this->assertTrue($cm->delete($key)); // Should work
        $cache->set($key, $value);
        $this->assertTrue($cm->clear()); // Should work
    }

    public function testCacheManagerWithCacheConfigInstance(): void
    {
        $cache = new ArrayCache();
        $config = new CacheConfig('test_', 120, true);
        $cm = new CacheManager($cache, $config);

        $retrievedConfig = $cm->getConfig();
        $this->assertSame($config, $retrievedConfig);
        $this->assertEquals('test_', $retrievedConfig->getPrefix());
        $this->assertEquals(120, $retrievedConfig->getDefaultTtl());
    }

    public function testNoCacheManager(): void
    {
        // Use the existing database without cache
        $result = self::$db->find()
        ->table('test_coverage')
        ->insert(['name' => 'Test', 'value' => 100]);

        $this->assertGreaterThan(0, $result);

        // Try to use cache methods (should be no-op)
        $data = self::$db->find()
        ->from('test_coverage')
        ->where('value', 100)
        ->cache(3600) // This should be ignored since no cache manager
        ->get();

        $this->assertCount(1, $data);
        $this->assertEquals('Test', $data[0]['name']);
    }

    public function testQueryCompilationCache(): void
    {
        $cache = new ArrayCache();
        $compilationCache = new QueryCompilationCache($cache);

        // Test enabled/disabled
        $this->assertTrue($compilationCache->isEnabled());
        $compilationCache->setEnabled(false);
        $this->assertFalse($compilationCache->isEnabled());
        $compilationCache->setEnabled(true);
        $this->assertTrue($compilationCache->isEnabled());

        // Test TTL
        $this->assertEquals(86400, $compilationCache->getDefaultTtl());
        $compilationCache->setDefaultTtl(3600);
        $this->assertEquals(3600, $compilationCache->getDefaultTtl());

        // Test prefix
        $compilationCache->setPrefix('test_');
        $this->assertNotNull($compilationCache->getCache());
    }

    public function testQueryCompilationCacheHashing(): void
    {
        $cache = new ArrayCache();
        $compilationCache = new QueryCompilationCache($cache);

        $structure1 = [
            'table' => 'users',
            'select' => ['id', 'name'],
            'distinct' => false,
            'distinct_on' => [],
            'joins' => [],
            'where' => [['type' => 'where', 'column' => 'active', 'operator' => '=', 'has_value' => true]],
            'group_by' => null,
            'having' => [],
            'order_by' => ['id'],
            'limit' => 10,
            'offset' => 0,
            'options' => [],
            'unions' => [],
            'cte' => null,
        ];

        $structure2 = [
            'table' => 'users',
            'select' => ['id', 'name'],
            'distinct' => false,
            'distinct_on' => [],
            'joins' => [],
            'where' => [['type' => 'where', 'column' => 'active', 'operator' => '=', 'has_value' => true]],
            'group_by' => null,
            'having' => [],
            'order_by' => ['id'],
            'limit' => 10,
            'offset' => 0,
            'options' => [],
            'unions' => [],
            'cte' => null,
        ];

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($compilationCache);
        $method = $reflection->getMethod('hashQueryStructure');
        $method->setAccessible(true);

        $hash1 = $method->invoke($compilationCache, $structure1, 'sqlite');
        $hash2 = $method->invoke($compilationCache, $structure2, 'sqlite');

        // Same structure should produce same hash
        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1)); // SHA-256 produces 64 char hex string

        // Different driver should produce different hash
        $hash3 = $method->invoke($compilationCache, $structure1, 'mysql');
        $this->assertNotEquals($hash1, $hash3);

        // Different structure should produce different hash
        $structure3 = $structure1;
        $structure3['select'] = ['id', 'email']; // Different select
        $hash4 = $method->invoke($compilationCache, $structure3, 'sqlite');
        $this->assertNotEquals($hash1, $hash4);
    }

    public function testQueryCompilationCacheGetOrCompile(): void
    {
        $cache = new ArrayCache();
        $compilationCache = new QueryCompilationCache($cache);
        $compilationCache->setPrefix('compiled_');

        $structure = [
            'table' => 'users',
            'select' => ['id', 'name'],
            'distinct' => false,
            'distinct_on' => [],
            'joins' => [],
            'where' => [],
            'group_by' => null,
            'having' => [],
            'order_by' => [],
            'limit' => null,
            'offset' => null,
            'options' => [],
            'unions' => [],
            'cte' => null,
        ];

        $callCount = 0;
        $compiler = function () use (&$callCount): string {
            $callCount++;
            return 'SELECT id, name FROM users';
        };

        // First call - should compile
        $result1 = $compilationCache->getOrCompile($compiler, $structure, 'sqlite');
        $this->assertEquals('SELECT id, name FROM users', $result1);
        $this->assertEquals(1, $callCount);

        // Second call - should use cache
        $result2 = $compilationCache->getOrCompile($compiler, $structure, 'sqlite');
        $this->assertEquals('SELECT id, name FROM users', $result2);
        $this->assertEquals(1, $callCount); // Compiler should not be called again

        // Different structure - should compile again
        $structure2 = $structure;
        $structure2['select'] = ['id'];
        $result3 = $compilationCache->getOrCompile($compiler, $structure2, 'sqlite');
        $this->assertEquals('SELECT id, name FROM users', $result3);
        $this->assertEquals(2, $callCount); // Compiler called again for different structure
    }

    public function testQueryCompilationCacheDisabled(): void
    {
        $cache = new ArrayCache();
        $compilationCache = new QueryCompilationCache($cache);
        $compilationCache->setEnabled(false);

        $structure = [
            'table' => 'users',
            'select' => ['id'],
            'distinct' => false,
            'distinct_on' => [],
            'joins' => [],
            'where' => [],
            'group_by' => null,
            'having' => [],
            'order_by' => [],
            'limit' => null,
            'offset' => null,
            'options' => [],
            'unions' => [],
            'cte' => null,
        ];

        $callCount = 0;
        $compiler = function () use (&$callCount): string {
            $callCount++;
            return 'SELECT id FROM users';
        };

        // Both calls should compile (cache disabled)
        $result1 = $compilationCache->getOrCompile($compiler, $structure, 'sqlite');
        $result2 = $compilationCache->getOrCompile($compiler, $structure, 'sqlite');

        $this->assertEquals('SELECT id FROM users', $result1);
        $this->assertEquals('SELECT id FROM users', $result2);
        $this->assertEquals(2, $callCount); // Compiler called twice
    }

    public function testQueryCompilationCacheWithActualQueries(): void
    {
        $cache = new ArrayCache();
        $compilationCache = new QueryCompilationCache($cache);
        $compilationCache->setEnabled(true);

        $db = self::$db;

        // Clear test data
        $db->find()->table('test_coverage')->delete();

        // Insert test data
        $db->find()->table('test_coverage')->insert([
            'name' => 'Test 1',
            'value' => 10,
        ]);
        $db->find()->table('test_coverage')->insert([
            'name' => 'Test 2',
            'value' => 20,
        ]);

        // Create a new PdoDb instance with compilation cache
        // Use sqlite with same configuration
        $newDb = new PdoDb('sqlite', ['path' => ':memory:'], [], null, $cache);

        // Create the same test table
        $newDb->rawQuery('
        CREATE TABLE test_coverage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            value INTEGER,
            meta TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

        // Insert same test data
        $newDb->find()->table('test_coverage')->insert([
            'name' => 'Test 1',
            'value' => 10,
        ]);
        $newDb->find()->table('test_coverage')->insert([
            'name' => 'Test 2',
            'value' => 20,
        ]);

        // Enable compilation cache
        $newDb->setCompilationCache($compilationCache);

        // Execute same query structure multiple times
        $query1 = $newDb->find()
            ->from('test_coverage')
            ->where('value', 10)
            ->orderBy('id')
            ->toSQL();

        $query2 = $newDb->find()
            ->from('test_coverage')
            ->where('value', 20) // Different parameter, same structure
            ->orderBy('id')
            ->toSQL();

        // Both should have same SQL structure (parameters are separate)
        $this->assertStringContainsString('SELECT', $query1['sql']);
        $this->assertStringContainsString('FROM', $query1['sql']);
        $this->assertStringContainsString('WHERE', $query1['sql']);

        // Verify queries work
        $result1 = $newDb->find()
            ->from('test_coverage')
            ->where('value', 10)
            ->orderBy('id')
            ->get();

        $result2 = $newDb->find()
            ->from('test_coverage')
            ->where('value', 20)
            ->orderBy('id')
            ->get();

        $this->assertCount(1, $result1);
        $this->assertCount(1, $result2);
        $this->assertEquals('Test 1', $result1[0]['name']);
        $this->assertEquals('Test 2', $result2[0]['name']);
    }

    public function testQueryCompilationCacheDifferentColumns(): void
    {
        // Test that compilation cache correctly distinguishes queries with different columns
        // even if they have the same structure (one WHERE condition with same operator)
        // This is a regression test for the bug where compilation cache was returning
        // cached SQL from a previous query with different column names

        $cache = new ArrayCache();
        $compilationCache = new QueryCompilationCache($cache);
        $compilationCache->setEnabled(true);

        $db = new PdoDb('sqlite', ['path' => ':memory:'], [], null, $cache);
        $db->setCompilationCache($compilationCache);

        // Create test table
        $db->rawQuery('
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                category TEXT,
                price REAL,
                stock INTEGER
            )
        ');

        // Insert test data
        $db->find()->table('products')->insertMulti([
            ['name' => 'Laptop', 'category' => 'Electronics', 'price' => 1299.99, 'stock' => 15],
            ['name' => 'Mouse', 'category' => 'Electronics', 'price' => 29.99, 'stock' => 50],
            ['name' => 'Desk', 'category' => 'Furniture', 'price' => 499.99, 'stock' => 5],
        ]);

        // Query 1: WHERE category = 'Electronics'
        $query1 = $db->find()
            ->from('products')
            ->where('category', 'Electronics');
        $sql1 = $query1->toSQL();
        $result1 = $query1->get();

        // Query 2: WHERE price > 100 (different column, same structure: one WHERE condition)
        // This should NOT use the cached SQL from query1, because the column is different
        $query2 = $db->find()
            ->from('products')
            ->where('price', 100, '>');
        $sql2 = $query2->toSQL();

        // Verify that SQL contains the correct column name
        $this->assertStringContainsString('category', $sql1['sql']);
        $this->assertStringNotContainsString('price', $sql1['sql']);
        $this->assertStringContainsString('price', $sql2['sql']);
        $this->assertStringNotContainsString('category', $sql2['sql']);

        // Verify parameters match SQL placeholders
        $this->assertArrayHasKey(':category_1', $sql1['params']);
        $this->assertEquals('Electronics', $sql1['params'][':category_1']);
        $this->assertArrayHasKey(':price_1', $sql2['params']);
        $this->assertEquals(100, $sql2['params'][':price_1']);

        // Verify queries execute correctly (no "column index out of range" error)
        $result2 = $query2->get();
        $this->assertCount(2, $result1); // Laptop and Mouse
        $this->assertCount(2, $result2); // Laptop and Desk (price > 100)

        // Verify results are correct
        $this->assertEquals('Laptop', $result1[0]['name']);
        $this->assertEquals('Mouse', $result1[1]['name']);
        $this->assertEquals('Laptop', $result2[0]['name']);
        $this->assertEquals('Desk', $result2[1]['name']);
    }

    public function testCacheConfigConstructorDefaults(): void
    {
        $config = new CacheConfig();
        $this->assertEquals('pdodb_', $config->getPrefix());
        $this->assertEquals(3600, $config->getDefaultTtl());
        $this->assertTrue($config->isEnabled());
    }

    public function testCacheConfigConstructorCustomValues(): void
    {
        $config = new CacheConfig('custom_', 7200, false);
        $this->assertEquals('custom_', $config->getPrefix());
        $this->assertEquals(7200, $config->getDefaultTtl());
        $this->assertFalse($config->isEnabled());
    }

    public function testCacheConfigFromArrayWithDefaults(): void
    {
        $config = CacheConfig::fromArray([]);
        $this->assertEquals('pdodb_', $config->getPrefix());
        $this->assertEquals(3600, $config->getDefaultTtl());
        $this->assertTrue($config->isEnabled());
    }

    public function testCacheConfigFromArrayWithCustomValues(): void
    {
        $config = CacheConfig::fromArray([
            'prefix' => 'myprefix_',
            'default_ttl' => 1800,
            'enabled' => false,
        ]);
        $this->assertEquals('myprefix_', $config->getPrefix());
        $this->assertEquals(1800, $config->getDefaultTtl());
        $this->assertFalse($config->isEnabled());
    }

    public function testCacheConfigFromArrayWithInvalidPrefixType(): void
    {
        $config = CacheConfig::fromArray([
            'prefix' => 12345,
        ]);
        $this->assertEquals('pdodb_', $config->getPrefix());
    }

    public function testCacheConfigFromArrayWithInvalidTtlType(): void
    {
        $config = CacheConfig::fromArray([
            'default_ttl' => 'invalid',
        ]);
        $this->assertEquals(3600, $config->getDefaultTtl());
    }

    public function testCacheConfigFromArrayWithNumericStringTtl(): void
    {
        $config = CacheConfig::fromArray([
            'default_ttl' => '7200',
        ]);
        $this->assertEquals(7200, $config->getDefaultTtl());
    }

    public function testCacheConfigFromArrayWithInvalidEnabledType(): void
    {
        $config = CacheConfig::fromArray([
            'enabled' => 'yes',
        ]);
        $this->assertTrue($config->isEnabled());
    }

    public function testCacheConfigFromArrayWithFalseEnabledString(): void
    {
        $config = CacheConfig::fromArray([
            'enabled' => '0',
        ]);
        $this->assertFalse($config->isEnabled());
    }

    public function testCacheManagerSetWithNullTtlUsesDefault(): void
    {
        $cache = new ArrayCache();
        $cm = new CacheManager($cache, ['default_ttl' => 120]);
        $key = 'test_key';
        $cm->set($key, 'value', null);
        $this->assertEquals('value', $cm->get($key));
    }

    public function testCacheManagerSetWithExplicitTtl(): void
    {
        $cache = new ArrayCache();
        $cm = new CacheManager($cache, ['default_ttl' => 120]);
        $key = 'test_key';
        $cm->set($key, 'value', 60);
        $this->assertEquals('value', $cm->get($key));
    }

    public function testQueryCacheKeyGenerateWithDifferentDrivers(): void
    {
        $key1 = QueryCacheKey::generate('SELECT 1', [], 'mysql');
        $key2 = QueryCacheKey::generate('SELECT 1', [], 'sqlite');
        $this->assertNotEquals($key1, $key2);
    }

    public function testQueryCacheKeyGenerateWithDifferentSql(): void
    {
        $key1 = QueryCacheKey::generate('SELECT 1', [], 'mysql');
        $key2 = QueryCacheKey::generate('SELECT 2', [], 'mysql');
        $this->assertNotEquals($key1, $key2);
    }

    public function testQueryCacheKeyGenerateWithDifferentParams(): void
    {
        $key1 = QueryCacheKey::generate('SELECT ?', ['a' => 1], 'mysql');
        $key2 = QueryCacheKey::generate('SELECT ?', ['a' => 2], 'mysql');
        $this->assertNotEquals($key1, $key2);
    }

    public function testQueryCacheKeyGenerateWithCustomPrefix(): void
    {
        $key = QueryCacheKey::generate('SELECT 1', [], 'mysql', 'custom_');
        $this->assertStringStartsWith('custom_', $key);
    }

    public function testQueryCacheKeyGenerateIdempotency(): void
    {
        // Same inputs should produce same key (cache consistency)
        $key1 = QueryCacheKey::generate('SELECT 1', ['id' => 1], 'mysql');
        $key2 = QueryCacheKey::generate('SELECT 1', ['id' => 1], 'mysql');
        $this->assertEquals($key1, $key2);

        // Verify hash part is consistent (should be 64 hex chars for SHA-256)
        $this->assertEquals(64, strlen(substr($key1, strlen('pdodb_'))));
    }

    public function testQueryCacheKeyTablePattern(): void
    {
        $pattern = QueryCacheKey::tablePattern('users');
        $this->assertEquals('pdodb_table_users_*', $pattern);
    }

    public function testQueryCacheKeyTablePatternWithCustomPrefix(): void
    {
        $pattern = QueryCacheKey::tablePattern('users', 'custom_');
        $this->assertEquals('custom_table_users_*', $pattern);
    }

    public function testPdoDbCacheConfigWithInvalidStringCacheThrows(): void
    {
        $cache = new ArrayCache();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQLite cache parameter');
        new PdoDb('sqlite', ['path' => ':memory:', 'cache' => 'not_an_array'], [], null, $cache);
    }

    public function testPdoDbCacheConfigWithArrayConfig(): void
    {
        $cache = new ArrayCache();
        // Array cache config is for query result caching, not SQLite DSN cache parameter
        $db = new PdoDb('sqlite', ['path' => ':memory:', 'cache' => ['prefix' => 'test_']], [], null, $cache);
        $this->assertNotNull($db);
    }
}
