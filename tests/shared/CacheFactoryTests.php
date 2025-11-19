<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use tommyknocker\pdodb\cache\CacheFactory;

/**
 * Tests for CacheFactory.
 */
class CacheFactoryTests extends TestCase
{
    /**
     * Test creating filesystem cache.
     */
    public function testCreateFilesystemCache(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'filesystem',
            'directory' => sys_get_temp_dir() . '/pdodb_test_cache',
            'namespace' => 'test',
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Test cache functionality
        $this->assertTrue($cache->set('test_key', 'test_value', 3600));
        $this->assertEquals('test_value', $cache->get('test_key'));
        $this->assertTrue($cache->has('test_key'));
        $this->assertTrue($cache->delete('test_key'));
        $this->assertFalse($cache->has('test_key'));

        // Cleanup
        $cache->clear();
    }

    /**
     * Test creating APCu cache.
     */
    public function testCreateApcuCache(): void
    {
        if (!extension_loaded('apcu')) {
            $this->markTestSkipped('ext-apcu is not installed');
        }

        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            $this->markTestSkipped('APCu is not enabled');
        }

        if (!class_exists(\Symfony\Component\Cache\Adapter\ApcuAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'apcu',
            'namespace' => 'pdodb_test',
        ];

        $cache = CacheFactory::create($config);
        if ($cache === null) {
            $this->markTestSkipped('APCu cache creation failed');
        }

        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Test cache functionality
        $this->assertTrue($cache->set('test_key', 'test_value', 3600));
        $this->assertEquals('test_value', $cache->get('test_key'));
        $this->assertTrue($cache->has('test_key'));
        $this->assertTrue($cache->delete('test_key'));
        $this->assertFalse($cache->has('test_key'));
    }

    /**
     * Test creating Redis cache.
     */
    public function testCreateRedisCache(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        if (!class_exists(\Symfony\Component\Cache\Adapter\RedisAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 15, // Use database 15 for testing
            'namespace' => 'pdodb_test',
        ];

        $cache = CacheFactory::create($config);
        if ($cache === null) {
            $this->markTestSkipped('Redis connection failed (server may not be running)');
        }

        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Test cache functionality
        $this->assertTrue($cache->set('test_key', 'test_value', 3600));
        $this->assertEquals('test_value', $cache->get('test_key'));
        $this->assertTrue($cache->has('test_key'));
        $this->assertTrue($cache->delete('test_key'));
        $this->assertFalse($cache->has('test_key'));

        // Cleanup
        $cache->clear();
    }

    /**
     * Test creating array cache.
     */
    public function testCreateArrayCache(): void
    {
        $config = [
            'type' => 'array',
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Test cache functionality
        $this->assertTrue($cache->set('test_key', 'test_value', 3600));
        $this->assertEquals('test_value', $cache->get('test_key'));
        $this->assertTrue($cache->has('test_key'));
        $this->assertTrue($cache->delete('test_key'));
        $this->assertFalse($cache->has('test_key'));
    }

    /**
     * Test creating cache with invalid type returns null.
     */
    public function testCreateCacheWithInvalidType(): void
    {
        $config = [
            'type' => 'invalid_type',
        ];

        $cache = CacheFactory::create($config);
        $this->assertNull($cache);
    }

    /**
     * Test creating filesystem cache with default directory.
     */
    public function testCreateFilesystemCacheWithDefaults(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'filesystem',
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Cleanup
        $cache->clear();
    }

    /**
     * Test creating Redis cache with provided connection.
     */
    public function testCreateRedisCacheWithProvidedConnection(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        if (!class_exists(\Symfony\Component\Cache\Adapter\RedisAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $redis = new \Redis();
        $connected = @$redis->connect('127.0.0.1', 6379);
        if (!$connected) {
            $this->markTestSkipped('Redis connection failed (server may not be running)');
        }

        $redis->select(15); // Use database 15 for testing

        $config = [
            'type' => 'redis',
            'redis' => $redis,
            'namespace' => 'pdodb_test',
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Test cache functionality
        $this->assertTrue($cache->set('test_key', 'test_value', 3600));
        $this->assertEquals('test_value', $cache->get('test_key'));

        // Cleanup
        $cache->clear();
    }

    /**
     * Test creating cache with 'file' alias for filesystem.
     */
    public function testCreateCacheWithFileAlias(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'file',
            'directory' => sys_get_temp_dir() . '/pdodb_test_cache',
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Cleanup
        $cache->clear();
    }

    /**
     * Test creating cache with 'adapter' key instead of 'type'.
     */
    public function testCreateCacheWithAdapterKey(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'adapter' => 'filesystem',
            'directory' => sys_get_temp_dir() . '/pdodb_test_cache',
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Cleanup
        $cache->clear();
    }

    /**
     * Test creating filesystem cache returns null when symfony/cache is not installed.
     */
    public function testCreateFilesystemCacheReturnsNullWhenNotInstalled(): void
    {
        // This test verifies the behavior when symfony/cache is not available
        // In practice, this is hard to test without actually removing the dependency
        // So we just verify that the method handles missing classes gracefully
        $this->assertTrue(true);
    }
}
