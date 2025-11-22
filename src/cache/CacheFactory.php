<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Factory for creating cache adapters from configuration.
 *
 * Supports optional dependencies: symfony/cache for various adapters.
 */
class CacheFactory
{
    /**
     * Create cache instance from configuration.
     *
     * @param array<string, mixed> $config Cache configuration
     *
     * @return CacheInterface|null Returns null if cache cannot be created
     */
    public static function create(array $config): ?CacheInterface
    {
        $type = $config['type'] ?? $config['adapter'] ?? 'filesystem';
        $type = is_string($type) ? mb_strtolower($type, 'UTF-8') : 'filesystem';

        return match ($type) {
            'filesystem', 'file' => static::createFilesystemCache($config),
            'apcu' => static::createApcuCache($config),
            'redis' => static::createRedisCache($config),
            'memcached' => static::createMemcachedCache($config),
            'array' => static::createArrayCache($config),
            default => null,
        };
    }

    /**
     * Create Symfony Filesystem cache adapter.
     *
     * @param array<string, mixed> $config Configuration
     *
     * @return CacheInterface|null Returns null if symfony/cache is not installed
     */
    public static function createFilesystemCache(array $config): ?CacheInterface
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class)) {
            return null;
        }

        $directory = $config['directory'] ?? $config['path'] ?? sys_get_temp_dir() . '/pdodb_cache';
        $directory = is_string($directory) ? $directory : sys_get_temp_dir() . '/pdodb_cache';

        $namespace = $config['namespace'] ?? '';
        $namespace = is_string($namespace) ? $namespace : '';

        $defaultLifetime = $config['default_lifetime'] ?? $config['ttl'] ?? 0;
        $defaultLifetime = is_int($defaultLifetime) ? $defaultLifetime : (is_numeric($defaultLifetime) ? (int)$defaultLifetime : 0);

        $adapter = new \Symfony\Component\Cache\Adapter\FilesystemAdapter($namespace, $defaultLifetime, $directory);

        return new \Symfony\Component\Cache\Psr16Cache($adapter);
    }

    /**
     * Create APCu cache adapter.
     *
     * @param array<string, mixed> $config Configuration
     *
     * @return CacheInterface|null Returns null if ext-apcu or symfony/cache is not installed
     */
    public static function createApcuCache(array $config): ?CacheInterface
    {
        if (!extension_loaded('apcu') || !class_exists(\Symfony\Component\Cache\Adapter\ApcuAdapter::class)) {
            return null;
        }

        $namespace = $config['namespace'] ?? '';
        $namespace = is_string($namespace) ? $namespace : '';

        $defaultLifetime = $config['default_lifetime'] ?? $config['ttl'] ?? 0;
        $defaultLifetime = is_int($defaultLifetime) ? $defaultLifetime : (is_numeric($defaultLifetime) ? (int)$defaultLifetime : 0);

        $version = $config['version'] ?? null;
        $version = is_string($version) ? $version : null;

        /** @var class-string<\Symfony\Component\Cache\Adapter\ApcuAdapter> $adapterClass */
        $adapterClass = \Symfony\Component\Cache\Adapter\ApcuAdapter::class;
        $adapter = new $adapterClass($namespace, $defaultLifetime, $version);

        /** @var class-string<\Symfony\Component\Cache\Psr16Cache> $cacheClass */
        $cacheClass = \Symfony\Component\Cache\Psr16Cache::class;
        return new $cacheClass($adapter);
    }

    /**
     * Create Redis cache adapter.
     *
     * @param array<string, mixed> $config Configuration
     *
     * @return CacheInterface|null Returns null if ext-redis or symfony/cache is not installed
     */
    public static function createRedisCache(array $config): ?CacheInterface
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\RedisAdapter::class)) {
            return null;
        }

        // Try to use provided Redis connection
        $redisConnection = $config['redis'] ?? null;
        if ($redisConnection !== null) {
            $isRedis = $redisConnection instanceof \Redis;
            $isPredis = class_exists(\Predis\Client::class) && $redisConnection instanceof \Predis\Client;
            if ($isRedis || $isPredis) {
                /** @var class-string<\Symfony\Component\Cache\Adapter\RedisAdapter> $adapterClass */
                $adapterClass = \Symfony\Component\Cache\Adapter\RedisAdapter::class;
                $adapter = new $adapterClass($redisConnection);

                /** @var class-string<\Symfony\Component\Cache\Psr16Cache> $cacheClass */
                $cacheClass = \Symfony\Component\Cache\Psr16Cache::class;
                // phpstan-ignore-next-line - adapter is CacheItemPoolInterface from RedisAdapter
                return new $cacheClass($adapter);
            }
        }

        // Create new Redis connection from config
        if (!extension_loaded('redis')) {
            return null;
        }

        $host = $config['host'] ?? $config['redis_host'] ?? '127.0.0.1';
        $host = is_string($host) ? $host : '127.0.0.1';

        $port = $config['port'] ?? $config['redis_port'] ?? 6379;
        $port = is_int($port) ? $port : (is_numeric($port) ? (int)$port : 6379);

        $password = $config['password'] ?? $config['redis_password'] ?? null;
        $password = is_string($password) ? $password : null;

        $database = $config['database'] ?? $config['redis_database'] ?? $config['db'] ?? 0;
        $database = is_int($database) ? $database : (is_numeric($database) ? (int)$database : 0);

        $timeout = $config['timeout'] ?? $config['redis_timeout'] ?? 0.0;
        $timeout = is_float($timeout) ? $timeout : (is_numeric($timeout) ? (float)$timeout : 0.0);

        try {
            $redis = new \Redis();
            $connected = $redis->connect($host, $port, $timeout);
            if (!$connected) {
                return null;
            }

            if ($password !== null && $password !== '') {
                $redis->auth($password);
            }

            if ($database > 0) {
                $redis->select($database);
            }
        } catch (\RedisException $e) {
            // Redis server not available or connection failed
            return null;
        }

        $namespace = $config['namespace'] ?? '';
        $namespace = is_string($namespace) ? $namespace : '';

        $defaultLifetime = $config['default_lifetime'] ?? $config['ttl'] ?? 0;
        $defaultLifetime = is_int($defaultLifetime) ? $defaultLifetime : (is_numeric($defaultLifetime) ? (int)$defaultLifetime : 0);

        /** @var class-string<\Symfony\Component\Cache\Adapter\RedisAdapter> $adapterClass */
        $adapterClass = \Symfony\Component\Cache\Adapter\RedisAdapter::class;
        $adapter = new $adapterClass($redis, $namespace, $defaultLifetime);

        /** @var class-string<\Symfony\Component\Cache\Psr16Cache> $cacheClass */
        $cacheClass = \Symfony\Component\Cache\Psr16Cache::class;
        // phpstan-ignore-next-line - adapter is CacheItemPoolInterface from RedisAdapter
        return new $cacheClass($adapter);
    }

    /**
     * Create Memcached cache adapter.
     *
     * @param array<string, mixed> $config Configuration
     *
     * @return CacheInterface|null Returns null if ext-memcached or symfony/cache is not installed
     */
    public static function createMemcachedCache(array $config): ?CacheInterface
    {
        if (!extension_loaded('memcached') || !class_exists(\Symfony\Component\Cache\Adapter\MemcachedAdapter::class)) {
            return null;
        }

        // Try to use provided Memcached connection
        $memcachedConnection = $config['memcached'] ?? null;
        if ($memcachedConnection !== null && $memcachedConnection instanceof \Memcached) {
            $namespace = $config['namespace'] ?? '';
            $namespace = is_string($namespace) ? $namespace : '';

            $defaultLifetime = $config['default_lifetime'] ?? $config['ttl'] ?? 0;
            $defaultLifetime = is_int($defaultLifetime) ? $defaultLifetime : (is_numeric($defaultLifetime) ? (int)$defaultLifetime : 0);

            /** @var class-string<\Symfony\Component\Cache\Adapter\MemcachedAdapter> $adapterClass */
            $adapterClass = \Symfony\Component\Cache\Adapter\MemcachedAdapter::class;
            $adapter = new $adapterClass($memcachedConnection, $namespace, $defaultLifetime);

            /** @var class-string<\Symfony\Component\Cache\Psr16Cache> $cacheClass */
            $cacheClass = \Symfony\Component\Cache\Psr16Cache::class;
            return new $cacheClass($adapter);
        }

        $servers = $config['servers'] ?? $config['memcached_servers'] ?? [['127.0.0.1', 11211]];
        if (!is_array($servers)) {
            $servers = [['127.0.0.1', 11211]];
        }

        // Build server list for createConnection (accepts array of [host, port] arrays)
        /** @var array<int, array<int, string|int>> $serverList */
        $serverList = [];
        foreach ($servers as $server) {
            if (is_array($server) && count($server) >= 2) {
                $host = is_string($server[0]) ? $server[0] : '127.0.0.1';
                $port = is_int($server[1]) ? $server[1] : (is_numeric($server[1]) ? (int)$server[1] : 11211);
                $serverList[] = [$host, $port];
            }
        }

        $namespace = $config['namespace'] ?? '';
        $namespace = is_string($namespace) ? $namespace : '';

        $defaultLifetime = $config['default_lifetime'] ?? $config['ttl'] ?? 0;
        $defaultLifetime = is_int($defaultLifetime) ? $defaultLifetime : (is_numeric($defaultLifetime) ? (int)$defaultLifetime : 0);

        /** @var class-string<\Symfony\Component\Cache\Adapter\MemcachedAdapter> $adapterClass */
        $adapterClass = \Symfony\Component\Cache\Adapter\MemcachedAdapter::class;
        // phpstan-ignore-next-line - createConnection accepts array<array|string>|string and returns CacheItemPoolInterface
        $adapter = $adapterClass::createConnection($serverList, [
            'namespace' => $namespace,
            'default_lifetime' => $defaultLifetime,
        ]);

        /** @var class-string<\Symfony\Component\Cache\Psr16Cache> $cacheClass */
        $cacheClass = \Symfony\Component\Cache\Psr16Cache::class;
        // @phpstan-ignore-next-line - adapter is CacheItemPoolInterface from createConnection
        return new $cacheClass($adapter);
    }

    /**
     * Create in-memory array cache (for testing).
     *
     * @param array<string, mixed> $config Configuration
     *
     * @return CacheInterface|null
     */
    public static function createArrayCache(array $config): ?CacheInterface
    {
        // Use test fixture if available
        if (class_exists(\tommyknocker\pdodb\tests\fixtures\ArrayCache::class)) {
            return new \tommyknocker\pdodb\tests\fixtures\ArrayCache();
        }

        // Fallback: try to create simple array-based cache
        // This is a basic implementation for testing only
        return new class () implements CacheInterface {
            /** @var array<string, mixed> */
            protected array $data = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                $this->data[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$key]);
                return true;
            }

            public function clear(): bool
            {
                $this->data = [];
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->get($key, $default);
                }
                return $result;
            }

            /**
             * @param iterable<string, mixed> $values
             */
            public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, $ttl);
                }
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->data[$key]);
            }
        };
    }
}
