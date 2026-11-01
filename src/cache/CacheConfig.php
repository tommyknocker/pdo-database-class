<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache;

/**
 * Cache configuration value object.
 */
class CacheConfig
{
    /**
     * Create a new cache configuration.
     *
     * @param string $prefix Cache key prefix for namespacing
     * @param int $defaultTtl Default time-to-live in seconds
     * @param bool $enabled Whether caching is enabled globally
     * @param bool $autoInvalidate Drop cached results that read a table after INSERT/UPDATE/DELETE/TRUNCATE/MERGE on it
     */
    public function __construct(
        protected string $prefix = 'pdodb_',
        protected int $defaultTtl = 3600,
        protected bool $enabled = true,
        protected bool $autoInvalidate = true
    ) {
    }

    /**
     * Whether writes through the query builder invalidate cached results of the written table.
     */
    public function isAutoInvalidate(): bool
    {
        return $this->autoInvalidate;
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get the default TTL.
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Check if caching is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Create from array configuration.
     *
     * @param array<string, mixed> $config
     *
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $prefix = $config['prefix'] ?? 'pdodb_';
        $defaultTtl = $config['default_ttl'] ?? 3600;
        $enabled = $config['enabled'] ?? true;
        $autoInvalidate = $config['auto_invalidate'] ?? true;

        return new self(
            prefix: is_string($prefix) ? $prefix : 'pdodb_',
            defaultTtl: is_int($defaultTtl) ? $defaultTtl : (is_numeric($defaultTtl) ? (int)$defaultTtl : 3600),
            enabled: is_bool($enabled) ? $enabled : (bool)$enabled,
            autoInvalidate: is_bool($autoInvalidate) ? $autoInvalidate : (bool)$autoInvalidate
        );
    }
}
