<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared\sharding;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\connection\sharding\strategies\HashShardStrategy;

/**
 * Tests for HashShardStrategy.
 */
final class HashShardStrategyTests extends TestCase
{
    public function testResolveShardReturnsValidShardName(): void
    {
        $strategy = new HashShardStrategy(3);

        $shard = $strategy->resolveShard('test-value');
        $this->assertStringStartsWith('shard', $shard);
        $this->assertMatchesRegularExpression('/^shard[1-3]$/', $shard);
    }

    public function testResolveShardIsConsistent(): void
    {
        $strategy = new HashShardStrategy(3);

        $shard1 = $strategy->resolveShard('test-value');
        $shard2 = $strategy->resolveShard('test-value');

        $this->assertEquals($shard1, $shard2);
    }

    public function testResolveShardDistributesValues(): void
    {
        $strategy = new HashShardStrategy(3);

        $shards = [];
        for ($i = 1; $i <= 100; $i++) {
            $shard = $strategy->resolveShard("value-{$i}");
            $shards[$shard] = ($shards[$shard] ?? 0) + 1;
        }

        // Should distribute across all shards
        $this->assertGreaterThan(1, count($shards));
    }

    public function testResolveShardsForArray(): void
    {
        $strategy = new HashShardStrategy(3);

        $shards = $strategy->resolveShards(['value1', 'value2', 'value3']);
        $this->assertIsArray($shards);
        $this->assertNotEmpty($shards);
    }

    public function testResolveShardsForSingleValue(): void
    {
        $strategy = new HashShardStrategy(3);

        $shards = $strategy->resolveShards('test-value');
        $this->assertCount(1, $shards);
        $this->assertStringStartsWith('shard', $shards[0]);
    }

    public function testResolveShardWithDifferentShardCounts(): void
    {
        $strategy2 = new HashShardStrategy(2);
        $strategy5 = new HashShardStrategy(5);

        $shard2 = $strategy2->resolveShard('test');
        $shard5 = $strategy5->resolveShard('test');

        $this->assertMatchesRegularExpression('/^shard[1-2]$/', $shard2);
        $this->assertMatchesRegularExpression('/^shard[1-5]$/', $shard5);
    }

    public function testResolveShardThrowsExceptionForInvalidShardCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Shard count must be at least 1');

        new HashShardStrategy(0);
    }
}
