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
     */
    public function __construct(
        protected string $prefix = 'pdodb_',
        protected int $defaultTtl = 3600,
        protected bool $enabled = true
    ) {
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
     */
    public static function fromArray(array $config): self
    {
        $prefix = $config['prefix'] ?? 'pdodb_';
        $defaultTtl = $config['default_ttl'] ?? 3600;
        $enabled = $config['enabled'] ?? true;

        return new self(
            prefix: is_string($prefix) ? $prefix : 'pdodb_',
            defaultTtl: is_int($defaultTtl) ? $defaultTtl : (is_numeric($defaultTtl) ? (int)$defaultTtl : 3600),
            enabled: is_bool($enabled) ? $enabled : (bool)$enabled
        );
    }
}
