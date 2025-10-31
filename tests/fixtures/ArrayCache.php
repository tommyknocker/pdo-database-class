<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\fixtures;

use DateInterval;
use DateTime;
use Psr\SimpleCache\CacheInterface;

/**
 * Simple in-memory cache implementation for testing.
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int|null}> */
    protected array $storage = [];

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->storage[$key]['value'];
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $expires = null;
        if ($ttl !== null) {
            if ($ttl instanceof DateInterval) {
                $expires = (new DateTime())->add($ttl)->getTimestamp();
            } else {
                $expires = time() + $ttl;
            }
        }

        $this->storage[$key] = [
            'value' => $value,
            'expires' => $expires,
        ];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        $this->storage = [];
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }

        $item = $this->storage[$key];
        if ($item['expires'] !== null && $item['expires'] < time()) {
            unset($this->storage[$key]);
            return false;
        }

        return true;
    }

    /**
     * Get all stored keys (for testing).
     *
     * @return array<string>
     */
    public function getKeys(): array
    {
        return array_keys($this->storage);
    }

    /**
     * Get count of stored items (for testing).
     */
    public function count(): int
    {
        return count($this->storage);
    }
}
