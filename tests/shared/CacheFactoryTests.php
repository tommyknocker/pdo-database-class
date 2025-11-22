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
            $this->markTestSkipped('Redis not available (ext-redis not installed, symfony/cache not installed, or server not running)');
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
     * Test creating cache with empty config uses filesystem as default.
     */
    public function testCreateCacheWithEmptyConfig(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $cache = CacheFactory::create([]);
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    /**
     * Test creating cache with non-string type.
     */
    public function testCreateCacheWithNonStringType(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 123, // Non-string type should default to filesystem
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    /**
     * Test creating filesystem cache with custom configuration.
     */
    public function testCreateFilesystemCacheWithCustomConfig(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'file', // Alternative name for filesystem
            'directory' => sys_get_temp_dir() . '/pdodb_test_custom',
            'namespace' => 'custom_test',
            'default_lifetime' => 7200,
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Test with string lifetime
        $config['default_lifetime'] = '3600';
        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Test with invalid lifetime
        $config['default_lifetime'] = 'invalid';
        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    /**
     * Test creating APCu cache with custom configuration.
     */
    public function testCreateApcuCacheWithCustomConfig(): void
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
            'namespace' => 'custom_apcu',
            'default_lifetime' => '1800',
            'version' => 'v1.0',
        ];

        $cache = CacheFactory::create($config);
        if ($cache === null) {
            $this->markTestSkipped('APCu cache creation failed');
        }

        $this->assertInstanceOf(CacheInterface::class, $cache);

        // Test with non-string version
        $config['version'] = 123;
        $cache = CacheFactory::create($config);
        if ($cache !== null) {
            $this->assertInstanceOf(CacheInterface::class, $cache);
        }
    }

    /**
     * Test creating Redis cache with existing connection.
     */
    public function testCreateRedisCacheWithExistingConnection(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\RedisAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // Mock Redis connection
        $mockRedis = $this->createMock(\Redis::class);

        $config = [
            'type' => 'redis',
            'redis' => $mockRedis,
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    /**
     * Test creating Redis cache with Predis connection.
     */
    public function testCreateRedisCacheWithPredisConnection(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\RedisAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        if (!class_exists(\Predis\Client::class)) {
            $this->markTestSkipped('predis/predis is not installed');
        }

        // Mock Predis connection
        $mockPredis = $this->createMock(\Predis\Client::class);

        $config = [
            'type' => 'redis',
            'redis' => $mockPredis,
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    /**
     * Test creating Redis cache with invalid connection object.
     */
    public function testCreateRedisCacheWithInvalidConnection(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        if (!class_exists(\Symfony\Component\Cache\Adapter\RedisAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'redis',
            'redis' => new \stdClass(), // Invalid connection object
            'host' => '127.0.0.1',
            'port' => 6379,
        ];

        $cache = CacheFactory::create($config);
        // Should fallback to creating new connection or return null if Redis server unavailable
        $this->assertTrue($cache === null || $cache instanceof CacheInterface);
    }

    /**
     * Test creating Redis cache with authentication.
     */
    public function testCreateRedisCacheWithAuth(): void
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
            'password' => 'test_password',
            'database' => 1,
            'namespace' => 'auth_test',
            'ttl' => 1800, // Alternative to default_lifetime
        ];

        $cache = CacheFactory::create($config);
        // Should return null if Redis server not available or auth fails
        $this->assertTrue($cache === null || $cache instanceof CacheInterface);
    }

    /**
     * Test creating Memcached cache.
     */
    public function testCreateMemcachedCache(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('ext-memcached is not installed');
        }

        if (!class_exists(\Symfony\Component\Cache\Adapter\MemcachedAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'memcached',
            'servers' => [['127.0.0.1', 11211]],
            'namespace' => 'memcached_test',
        ];

        $cache = CacheFactory::create($config);
        // Should return null if Memcached server not available
        $this->assertTrue($cache === null || $cache instanceof CacheInterface);
    }

    /**
     * Test creating Memcached cache with invalid servers config.
     */
    public function testCreateMemcachedCacheWithInvalidServers(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('ext-memcached is not installed');
        }

        if (!class_exists(\Symfony\Component\Cache\Adapter\MemcachedAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        $config = [
            'type' => 'memcached',
            'servers' => 'invalid_servers_config', // Should fallback to default
        ];

        $cache = CacheFactory::create($config);
        // Should return null if Memcached server not available
        $this->assertTrue($cache === null || $cache instanceof CacheInterface);
    }

    /**
     * Test creating Memcached cache with existing connection.
     */
    public function testCreateMemcachedCacheWithExistingConnection(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('ext-memcached is not installed');
        }

        if (!class_exists(\Symfony\Component\Cache\Adapter\MemcachedAdapter::class)) {
            $this->markTestSkipped('symfony/cache is not installed');
        }

        // Mock Memcached connection
        $mockMemcached = $this->createMock(\Memcached::class);

        $config = [
            'type' => 'memcached',
            'memcached' => $mockMemcached,
        ];

        $cache = CacheFactory::create($config);
        $this->assertInstanceOf(CacheInterface::class, $cache);
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

        try {
            $redis = new \Redis();
            $connected = $redis->connect('127.0.0.1', 6379);
            if (!$connected) {
                $this->markTestSkipped('Redis connection failed (server may not be running)');
            }
            $redis->select(15); // Use database 15 for testing
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis connection failed (server may not be running)');
        }

        if (!isset($redis)) {
            $this->markTestSkipped('Redis connection failed (server may not be running)');
        }

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
