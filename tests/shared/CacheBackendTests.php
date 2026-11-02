<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use tommyknocker\pdodb\cache\backend\CacheBackendDetector;
use tommyknocker\pdodb\cache\backend\RedisBackend;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

/**
 * Finding and using the server behind a PSR-16 cache.
 */
final class CacheBackendTests extends TestCase
{
    public function testACacheWithoutServerFeaturesHasNoBackend(): void
    {
        $this->assertNull(CacheBackendDetector::detect(new ArrayCache()));
        $this->assertNull(CacheBackendDetector::detect(new Psr16Cache(new ArrayAdapter())));
    }

    public function testSymfonyPoolsAreNamedByTheirKind(): void
    {
        $this->assertSame('Array', CacheBackendDetector::describe(new Psr16Cache(new ArrayAdapter())));
        $this->assertSame('Filesystem', CacheBackendDetector::describe(new Psr16Cache(new FilesystemAdapter('pdodb_test', 0, sys_get_temp_dir()))));
        $this->assertSame('Array', CacheBackendDetector::describe(new ArrayCache()));
    }

    public function testARedisClientNestedInAWrapperIsFound(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis is not loaded');
        }

        // two levels down, the way adapters keep their connection
        $cache = new class (new class (new \Redis()) {
            public function __construct(public object $connection)
            {
            }
        }) extends ArrayCache {
            public function __construct(private object $inner)
            {
            }
        };

        $backend = CacheBackendDetector::detect($cache);
        $this->assertInstanceOf(RedisBackend::class, $backend);
        $this->assertSame('Redis', $backend->type());
        $this->assertTrue($backend->supportsKeyPatterns());
        $this->assertSame('Redis', CacheBackendDetector::describe($cache));
    }

    public function testAClientBuriedTooDeepIsNotSearchedFor(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis is not loaded');
        }

        $deep = new \Redis();
        for ($i = 0; $i < 5; $i++) {
            $deep = new class ($deep) {
                public function __construct(public object $next)
                {
                }
            };
        }
        $cache = new class ($deep) extends ArrayCache {
            public function __construct(private object $inner)
            {
            }
        };

        $this->assertNull(CacheBackendDetector::detect($cache));
    }

    public function testACircularReferenceDoesNotLoopForever(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->other = $b;
        $b->other = $a;
        $cache = new class ($a) extends ArrayCache {
            public function __construct(private object $inner)
            {
            }
        };

        $this->assertNull(CacheBackendDetector::detect($cache));
    }

    public function testRedisCountersTreatAMissingKeyAsZeroWhicheverClientAnswers(): void
    {
        // ext-redis answers false for a missing key, Predis null
        foreach ([false, null] as $missing) {
            $client = new class ($missing) {
                /** @var array<string, int> */
                public array $values = [];

                /** @var list<string> */
                public array $log = [];

                public function __construct(private mixed $missing)
                {
                }

                public function incrby(string $key, int $by): int
                {
                    $this->log[] = "incrby {$key} {$by}";

                    return $this->values[$key] = ($this->values[$key] ?? 0) + $by;
                }

                public function expire(string $key, int $ttl): bool
                {
                    $this->log[] = "expire {$key} {$ttl}";

                    return true;
                }

                public function get(string $key): mixed
                {
                    return $this->values[$key] ?? $this->missing;
                }

                public function del(string $key): int
                {
                    unset($this->values[$key]);

                    return 1;
                }

                /** @return list<string> */
                public function keys(string $pattern): array
                {
                    return array_keys($this->values);
                }
            };
            $backend = new RedisBackend($client);

            $this->assertSame(0, $backend->counter('hits'));
            $backend->increment('hits', 1, 60);
            $backend->increment('hits', 2, 60);
            $this->assertSame(3, $backend->counter('hits'));
            $this->assertSame(['incrby hits 1', 'expire hits 60', 'incrby hits 2', 'expire hits 60'], $client->log);
            $this->assertSame(['hits'], $backend->keys('*'));
            $backend->delete('hits');
            $this->assertSame(0, $backend->counter('hits'));
        }
    }
}
