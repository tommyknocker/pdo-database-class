<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use tommyknocker\pdodb\cache\CacheFactory;

/**
 * Environment configuration loader for PdoDb.
 *
 * Loads database and cache configuration from .env file.
 * Separates configuration loading logic from PdoDb class.
 */
class EnvConfigLoader
{
    /**
     * Load database configuration from environment variables.
     *
     * @param string|null $envPath Path to .env file
     *
     * @return array{driver: string, config: array<string, mixed>, envVars: array<string, string>}
     * @throws InvalidArgumentException If driver is not set or required variables are missing
     */
    public static function loadDatabaseConfig(?string $envPath = null): array
    {
        // Load .env file
        EnvLoader::load($envPath);

        // Get driver from environment
        $driver = mb_strtolower(getenv('PDODB_DRIVER') ?: '', 'UTF-8');
        if ($driver === '') {
            throw new InvalidArgumentException('PDODB_DRIVER not set in .env file');
        }

        // Validate that required extension is available
        ExtensionChecker::validate($driver);

        // Collect only database-related PDODB_* environment variables
        // Always use getenv() to ensure we get variables set via putenv()
        // $_ENV may not be updated when putenv() is called
        $dbEnvVars = [
            'PDODB_DRIVER',
            'PDODB_HOST',
            'PDODB_PORT',
            'PDODB_DATABASE',
            'PDODB_DB', // Alternative name
            'PDODB_USERNAME',
            'PDODB_USER', // Alternative name
            'PDODB_PASSWORD',
            'PDODB_CHARSET',
            'PDODB_PREFIX',
            'PDODB_SERVICE_NAME',
            'PDODB_SID',
            'PDODB_PATH', // For SQLite
        ];

        $envVars = [];
        foreach ($dbEnvVars as $key) {
            $envValue = getenv($key);
            if ($envValue !== false) {
                $envVars[$key] = $envValue;
            }
        }

        // Check required variables (SQLite doesn't require username/database)
        if ($driver !== 'sqlite') {
            if (!isset($envVars['PDODB_DATABASE']) || !isset($envVars['PDODB_USERNAME'])) {
                throw new InvalidArgumentException(
                    'PDODB_DATABASE and PDODB_USERNAME must be set in .env file for driver: ' . $driver
                );
            }
        }

        // Build configuration from environment variables
        $dialect = DialectRegistry::resolve($driver);
        $config = $dialect->buildConfigFromEnv($envVars);

        return [
            'driver' => $driver,
            'config' => $config,
            'envVars' => $envVars,
        ];
    }

    /**
     * Load cache configuration from environment variables.
     *
     * @param array<string, string> $envVars Environment variables
     * @param array<string, mixed> $dbConfig Database configuration (may contain cache config)
     *
     * @return array<string, mixed> Cache configuration
     */
    public static function loadCacheConfig(array $envVars, array $dbConfig = []): array
    {
        $cacheConfig = [];

        // Get cache config from dbConfig (if exists)
        $cacheSection = isset($dbConfig['cache']) && is_array($dbConfig['cache']) ? $dbConfig['cache'] : [];

        // Check if cache is enabled
        $cacheEnabled = $envVars['PDODB_CACHE_ENABLED'] ?? null;
        if ($cacheEnabled !== null && mb_strtolower($cacheEnabled, 'UTF-8') === 'true') {
            $cacheConfig['enabled'] = true;
        } elseif (isset($cacheSection['enabled'])) {
            $cacheConfig['enabled'] = (bool)$cacheSection['enabled'];
        } else {
            $cacheConfig['enabled'] = false;
        }

        if (!$cacheConfig['enabled']) {
            return $cacheConfig;
        }

        // Get cache type
        $type = $envVars['PDODB_CACHE_TYPE'] ?? null;
        if ($type === null) {
            $type = $cacheSection['type'] ?? $cacheSection['adapter'] ?? 'filesystem';
        }
        $cacheConfig['type'] = is_string($type) ? $type : 'filesystem';

        // Load cache-specific configuration
        switch (mb_strtolower($cacheConfig['type'], 'UTF-8')) {
            case 'filesystem':
            case 'file':
                $cacheConfig['directory'] = $envVars['PDODB_CACHE_DIRECTORY'] ?? null;
                if ($cacheConfig['directory'] === null) {
                    $cacheConfig['directory'] = $cacheSection['directory'] ?? $cacheSection['path'] ?? sys_get_temp_dir() . '/pdodb_cache';
                }
                $cacheConfig['namespace'] = $envVars['PDODB_CACHE_NAMESPACE'] ?? $cacheSection['namespace'] ?? '';
                break;

            case 'redis':
                $cacheConfig['host'] = $envVars['PDODB_CACHE_REDIS_HOST'] ?? null;
                if ($cacheConfig['host'] === null) {
                    $cacheConfig['host'] = $cacheSection['host'] ?? $cacheSection['redis_host'] ?? '127.0.0.1';
                }
                $cacheConfig['port'] = isset($envVars['PDODB_CACHE_REDIS_PORT'])
                    ? (int)$envVars['PDODB_CACHE_REDIS_PORT']
                    : ($cacheSection['port'] ?? $cacheSection['redis_port'] ?? 6379);
                $cacheConfig['password'] = $envVars['PDODB_CACHE_REDIS_PASSWORD'] ?? null;
                if ($cacheConfig['password'] === null) {
                    $cacheConfig['password'] = $cacheSection['password'] ?? $cacheSection['redis_password'] ?? null;
                }
                $cacheConfig['database'] = isset($envVars['PDODB_CACHE_REDIS_DATABASE'])
                    ? (int)$envVars['PDODB_CACHE_REDIS_DATABASE']
                    : ($cacheSection['database'] ?? $cacheSection['redis_database'] ?? $cacheSection['db'] ?? 0);
                $cacheConfig['namespace'] = $envVars['PDODB_CACHE_NAMESPACE'] ?? $cacheSection['namespace'] ?? '';
                break;

            case 'apcu':
                $cacheConfig['namespace'] = $envVars['PDODB_CACHE_NAMESPACE'] ?? $cacheSection['namespace'] ?? '';
                break;

            case 'memcached':
                $servers = $envVars['PDODB_CACHE_MEMCACHED_SERVERS'] ?? null;
                if ($servers !== null && $servers !== '') {
                    $serversList = explode(',', $servers);
                    $cacheConfig['servers'] = [];
                    foreach ($serversList as $server) {
                        $parts = explode(':', trim($server));
                        $host = $parts[0] ?? '127.0.0.1';
                        $port = isset($parts[1]) ? (int)$parts[1] : 11211;
                        $cacheConfig['servers'][] = [$host, $port];
                    }
                } else {
                    $cacheConfig['servers'] = $cacheSection['servers'] ?? $cacheSection['memcached_servers'] ?? [['127.0.0.1', 11211]];
                }
                $cacheConfig['namespace'] = $envVars['PDODB_CACHE_NAMESPACE'] ?? $cacheSection['namespace'] ?? '';
                break;

            case 'array':
                // Array cache (for testing) - no additional configuration needed
                break;
        }

        // Common cache settings
        $cacheConfig['default_lifetime'] = isset($envVars['PDODB_CACHE_TTL'])
            ? (int)$envVars['PDODB_CACHE_TTL']
            : ($cacheSection['default_lifetime'] ?? $cacheSection['ttl'] ?? 3600);
        $cacheConfig['prefix'] = $envVars['PDODB_CACHE_PREFIX'] ?? $cacheSection['prefix'] ?? 'pdodb_';

        return $cacheConfig;
    }

    /**
     * Create cache instance from environment variables.
     *
     * @param array<string, string> $envVars Environment variables
     * @param array<string, mixed> $dbConfig Database configuration (may contain cache config)
     *
     * @return CacheInterface|null Cache instance or null if cache is disabled
     */
    public static function createCacheFromEnv(array $envVars, array $dbConfig = []): ?CacheInterface
    {
        $cacheConfig = static::loadCacheConfig($envVars, $dbConfig);
        if (!($cacheConfig['enabled'] ?? false)) {
            return null;
        }

        return CacheFactory::create($cacheConfig);
    }
}
