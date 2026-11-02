<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache\backend;

use Memcached;
use Predis\Client;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Finds the server connection behind a PSR-16 cache so counters and key lookup can use it.
 *
 * Any PSR-16 wrapper is searched (a few levels of object properties, which covers Symfony Cache and typical custom
 * adapters). That search is the only place that knows how caches are put together; an application that does not
 * want it passes a {@see CacheBackend} to the cache manager.
 */
final class CacheBackendDetector
{
    /** How many levels of nested objects are searched for a client */
    private const MAX_DEPTH = 3;

    public static function detect(CacheInterface $cache): ?CacheBackend
    {
        $client = self::findClient($cache, [self::isRedis(...)]);
        if ($client !== null) {
            return new RedisBackend($client);
        }

        $client = self::findClient($cache, [static fn (object $o): bool => $o instanceof Memcached]);
        if ($client instanceof Memcached) {
            return new MemcachedBackend($client);
        }

        if (self::isApcu($cache)) {
            return new ApcuBackend();
        }

        return null;
    }

    /**
     * Kind of cache for statistics, including those without a backend (array, filesystem).
     */
    public static function describe(CacheInterface $cache, ?CacheBackend $backend = null): string
    {
        $backend ??= self::detect($cache);
        if ($backend !== null) {
            return $backend->type();
        }

        $pool = self::symfonyPool($cache);
        $name = strtolower($pool !== null ? $pool::class : $cache::class);

        return match (true) {
            str_contains($name, 'redis') => 'Redis',
            str_contains($name, 'memcache') => 'Memcached',
            str_contains($name, 'apc') => 'APCu',
            str_contains($name, 'filesystem'), str_contains($name, 'file') => 'Filesystem',
            str_contains($name, 'array') => 'Array',
            default => 'Unknown',
        };
    }

    private static function isRedis(object $value): bool
    {
        return $value instanceof \Redis || (class_exists(Client::class) && $value instanceof Client);
    }

    /**
     * @param list<callable(object): bool> $matches
     */
    private static function findClient(object $root, array $matches): ?object
    {
        $seen = [];

        return self::search($root, $matches, 0, $seen);
    }

    /**
     * @param list<callable(object): bool> $matches
     * @param array<int, true> $seen
     */
    private static function search(object $object, array $matches, int $depth, array &$seen): ?object
    {
        foreach ($matches as $matcher) {
            if ($matcher($object)) {
                return $object;
            }
        }

        if ($depth >= self::MAX_DEPTH || isset($seen[spl_object_id($object)])) {
            return null;
        }
        $seen[spl_object_id($object)] = true;

        try {
            $properties = (new ReflectionClass($object))->getProperties();
        } catch (ReflectionException) {
            return null;
        }

        foreach ($properties as $property) {
            try {
                if (!$property->isInitialized($object)) {
                    continue;
                }
                $value = $property->getValue($object);
            } catch (ReflectionException | \Error) {
                continue;
            }

            if (is_object($value)) {
                $found = self::search($value, $matches, $depth + 1, $seen);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function isApcu(CacheInterface $cache): bool
    {
        if (!extension_loaded('apcu') || !function_exists('apcu_inc') || !class_exists(ApcuAdapter::class)) {
            return false;
        }

        return self::symfonyPool($cache) instanceof ApcuAdapter;
    }

    /**
     * The Symfony pool behind a Psr16Cache, if that is what the cache is.
     */
    private static function symfonyPool(CacheInterface $cache): ?object
    {
        if (!class_exists(Psr16Cache::class) || !$cache instanceof Psr16Cache) {
            return null;
        }

        try {
            $property = new \ReflectionProperty($cache, 'pool');

            return $property->isInitialized($cache) ? $property->getValue($cache) : null;
        } catch (ReflectionException) {
            return null;
        }
    }
}
