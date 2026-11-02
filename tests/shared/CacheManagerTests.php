<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cache\backend\CacheBackend;
use tommyknocker\pdodb\cache\CacheConfig;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

/**
 * What a cache manager does with the cache and the server features behind it.
 */
final class CacheManagerTests extends TestCase
{
    private function manager(?CacheBackend $backend = null, bool $enabled = true): CacheManager
    {
        return new CacheManager(new ArrayCache(), new CacheConfig(prefix: 'test_', enabled: $enabled), $backend);
    }

    public function testStatisticsCountEveryKindOfEvent(): void
    {
        $manager = $this->manager();
        $manager->set('a', 1);
        $manager->get('a');
        $manager->get('a');
        $manager->get('missing');
        $manager->delete('a');

        $stats = $manager->getStats();
        $this->assertSame(1, $stats['sets']);
        $this->assertSame(2, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(1, $stats['deletes']);
        $this->assertSame(3, $stats['total_requests']);
        $this->assertSame(66.67, $stats['hit_rate']);
        $this->assertSame('Array', $stats['type'], 'a cache without server features is named by its class');
    }

    public function testStatisticsSurviveTheManagerThatCountedThem(): void
    {
        $cache = new ArrayCache();
        $first = new CacheManager($cache, new CacheConfig(prefix: 'p_'));
        $first->set('k', 'v');
        $first->get('k');
        $first->flushStats();

        $second = new CacheManager($cache, new CacheConfig(prefix: 'p_'));
        $second->get('k');

        $this->assertSame(['hits' => 2, 'misses' => 0, 'sets' => 1], array_intersect_key($second->getStats(), ['hits' => 1, 'misses' => 1, 'sets' => 1]));
    }

    public function testCountsAreWrittenEveryFewOperationsWithoutAnExplicitFlush(): void
    {
        $cache = new ArrayCache();
        $manager = new CacheManager($cache, new CacheConfig(prefix: 'p_'));
        for ($i = 0; $i < 10; $i++) {
            $manager->get('missing');
        }

        $this->assertSame(10, (new CacheManager($cache, new CacheConfig(prefix: 'p_')))->getStats()['misses']);
    }

    public function testResetForgetsStoredAndPendingCounts(): void
    {
        $manager = $this->manager();
        $manager->set('a', 1);
        $manager->flushStats();
        $manager->get('a');
        $manager->resetStats();

        $this->assertSame(0, $manager->getStats()['hits']);
        $this->assertSame(0, $manager->getStats()['sets']);
    }

    public function testADisabledCacheCountsNothingAndStoresNothing(): void
    {
        $manager = $this->manager(enabled: false);

        $this->assertFalse($manager->set('a', 1));
        $this->assertNull($manager->get('a'));
        $this->assertSame(0, $manager->getStats()['total_requests']);
        $this->assertSame(0, $manager->invalidateByPattern('users'));
    }

    public function testABackendCountsOnTheServerInsteadOfInMemory(): void
    {
        $backend = new ArrayBackend();
        $manager = $this->manager($backend);
        $manager->set('a', 1);
        $manager->get('a');
        $manager->get('b');

        // visible to every process using the same server, without any flush
        $this->assertSame(1, $backend->counters['test___pdodb_stats__:sets']);
        $this->assertSame(1, $backend->counters['test___pdodb_stats__:hits']);
        $this->assertSame(1, $backend->counters['test___pdodb_stats__:misses']);
        $this->assertSame('Fake', $manager->getStats()['type']);

        $manager->resetStats();
        $this->assertSame([], $backend->counters);
    }

    public function testInvalidateTableDropsEntriesStoredForIt(): void
    {
        $manager = $this->manager();
        $manager->set('q1', 'one', null, ['users']);
        $manager->set('q2', 'two', null, ['users', 'orders']);
        $manager->set('q3', 'three', null, ['orders']);

        $this->assertSame(2, $manager->invalidateTable('users'));
        $this->assertNull($manager->get('q1'));
        $this->assertNull($manager->get('q2'));
        $this->assertSame('three', $manager->get('q3'));
        $this->assertSame(0, $manager->invalidateTable('users'), 'nothing left to drop');
    }

    public function testTableNamesAreNormalizedWhenStoredAndInvalidated(): void
    {
        $manager = $this->manager();
        $manager->set('q', 'x', null, ['`App`.`Users` AS u']);

        $this->assertSame(1, $manager->invalidateTable('"users"'));
    }

    public function testInvalidateByPatternSupportsTablesAndPrefixesWhenTheServerCanListKeys(): void
    {
        $backend = new ArrayBackend();
        $manager = $this->manager($backend);
        $manager->set('a', 1, null, ['users']);
        $manager->set('b', 2, null, ['users_archive']);
        $manager->set('c', 3, null, ['orders']);
        $backend->keyList = ['test___pdodb_meta:table:users', 'test___pdodb_meta:table:users_archive'];

        $this->assertSame(2, $manager->invalidateByPattern('table:users_*'));
        $this->assertSame(3, $manager->get('c'));
    }

    public function testKeyPatternsAreIgnoredWhenTheServerCannotListKeys(): void
    {
        $manager = $this->manager();
        $manager->set('test_table_users_1', 1);

        $this->assertSame(0, $manager->invalidateByPattern('test_table_users_*'));
        $this->assertSame(1, $manager->get('test_table_users_1'));
    }

    public function testKeyPatternsUseTheBackend(): void
    {
        $backend = new ArrayBackend();
        $backend->keyList = ['test_table_users_1', 'test_table_users_2'];
        $manager = $this->manager($backend);
        $manager->getCache()->set('test_table_users_1', 'x');
        $manager->getCache()->set('test_table_users_2', 'y');

        $this->assertSame(2, $manager->invalidateByPattern('test_table_users_*'));
        $this->assertSame(['test_table_users_*'], $backend->patterns);
    }
}

/**
 * A server stand-in that keeps counters in an array.
 */
final class ArrayBackend implements CacheBackend
{
    /** @var array<string, int> */
    public array $counters = [];

    /** @var list<string> */
    public array $keyList = [];

    /** @var list<string> */
    public array $patterns = [];

    public function type(): string
    {
        return 'Fake';
    }

    public function increment(string $key, int $by, int $ttl): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
    }

    public function counter(string $key): int
    {
        return $this->counters[$key] ?? 0;
    }

    public function delete(string $key): void
    {
        unset($this->counters[$key]);
    }

    public function supportsKeyPatterns(): bool
    {
        return true;
    }

    public function keys(string $pattern): array
    {
        $this->patterns[] = $pattern;

        return $this->keyList;
    }
}
