<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache\backend;

/**
 * APCu (shared memory of the PHP process group). It has atomic counters but cannot list keys by pattern here.
 */
final class ApcuBackend implements CacheBackend
{
    public function type(): string
    {
        return 'APCu';
    }

    public function increment(string $key, int $by, int $ttl): void
    {
        apcu_inc($key, $by, $success, $ttl);
        if (!$success) {
            apcu_store($key, $by, $ttl);
        }
    }

    public function counter(string $key): int
    {
        $value = apcu_fetch($key, $success);

        return $success ? (int)$value : 0;
    }

    public function delete(string $key): void
    {
        apcu_delete($key);
    }

    public function supportsKeyPatterns(): bool
    {
        return false;
    }

    public function keys(string $pattern): array
    {
        return [];
    }
}
