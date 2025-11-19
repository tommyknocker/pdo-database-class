# Optional Dependencies Examples

This directory contains examples demonstrating how to use optional dependencies with PDOdb.

## Available Examples

### 01-cache-factory.php
Demonstrates how to use `CacheFactory` to create cache adapters from optional dependencies:
- **Filesystem Cache**: Requires `symfony/cache`
- **APCu Cache**: Requires `ext-apcu` and `symfony/cache`
- **Redis Cache**: Requires `ext-redis` and `symfony/cache`
- **Using Cache with PdoDb**: Shows how to integrate cache with database connections

### 02-cache-configuration.php
Shows different ways to configure cache:
- Programmatic configuration via arrays
- Redis-specific configuration
- Configuration in PdoDb constructor
- Environment variables for cache configuration

## Installation

To use these examples, install the optional dependencies:

```bash
# For filesystem cache (recommended)
composer require symfony/cache

# For APCu cache
composer require symfony/cache
# Enable ext-apcu in php.ini

# For Redis cache
composer require symfony/cache
# Install ext-redis extension

# For Memcached cache
composer require symfony/cache
# Install ext-memcached extension
```

## Environment Variables

You can configure cache using environment variables:

```bash
# Enable cache
PDODB_CACHE_ENABLED=true

# Cache type
PDODB_CACHE_TYPE=filesystem  # or redis, apcu, memcached

# Filesystem cache
PDODB_CACHE_DIRECTORY=/path/to/cache
PDODB_CACHE_NAMESPACE=myapp

# Redis cache
PDODB_CACHE_REDIS_HOST=127.0.0.1
PDODB_CACHE_REDIS_PORT=6379
PDODB_CACHE_REDIS_DATABASE=0
PDODB_CACHE_REDIS_PASSWORD=secret

# Common settings
PDODB_CACHE_TTL=3600
PDODB_CACHE_PREFIX=app_
```

## Configuration File

You can also configure cache in `config/db.php`:

```php
<?php
return [
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
    'cache' => [
        'enabled' => true,
        'type' => 'filesystem',
        'directory' => '/var/cache/pdodb',
        'namespace' => 'app',
        'default_lifetime' => 3600,
        'prefix' => 'db_',
    ],
];
```

## Running Examples

```bash
# Run all examples
php examples/15-optional-dependencies/01-cache-factory.php
php examples/15-optional-dependencies/02-cache-configuration.php
```

## Notes

- Cache adapters are created only if the required dependencies are installed
- If a dependency is missing, `CacheFactory::create()` returns `null`
- Always check if cache is available before using it
- For production, use persistent cache adapters (Redis, Memcached) instead of filesystem

