<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared\sharding;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\connection\sharding\strategies\RangeShardStrategy;

/**
 * Tests for RangeShardStrategy.
 */
final class RangeShardStrategyTests extends TestCase
{
    protected RangeShardStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new RangeShardStrategy([
            'shard1' => [0, 1000],
            'shard2' => [1001, 2000],
            'shard3' => [2001, 3000],
        ]);
    }

    public function testResolveShardForValueInFirstRange(): void
    {
        $this->assertEquals('shard1', $this->strategy->resolveShard(500));
        $this->assertEquals('shard1', $this->strategy->resolveShard(0));
        $this->assertEquals('shard1', $this->strategy->resolveShard(1000));
    }

    public function testResolveShardForValueInSecondRange(): void
    {
        $this->assertEquals('shard2', $this->strategy->resolveShard(1500));
        $this->assertEquals('shard2', $this->strategy->resolveShard(1001));
        $this->assertEquals('shard2', $this->strategy->resolveShard(2000));
    }

    public function testResolveShardForValueInThirdRange(): void
    {
        $this->assertEquals('shard3', $this->strategy->resolveShard(2500));
        $this->assertEquals('shard3', $this->strategy->resolveShard(2001));
        $this->assertEquals('shard3', $this->strategy->resolveShard(3000));
    }

    public function testResolveShardThrowsExceptionForValueOutsideRanges(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No shard found for value');

        $this->strategy->resolveShard(3001);
    }

    public function testResolveShardThrowsExceptionForNonNumericValue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Range strategy requires numeric value');

        $this->strategy->resolveShard('not-a-number');
    }

    public function testResolveShardsForBetweenRange(): void
    {
        // Range spans multiple shards
        $shards = $this->strategy->resolveShards([500, 2500]);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
        $this->assertContains('shard3', $shards);
    }

    public function testResolveShardsForInArray(): void
    {
        $shards = $this->strategy->resolveShards([500, 1500, 2500]);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
        $this->assertContains('shard3', $shards);
    }

    public function testResolveShardsForSingleValue(): void
    {
        $shards = $this->strategy->resolveShards(1500);
        $this->assertCount(1, $shards);
        $this->assertContains('shard2', $shards);
    }

    public function testResolveShardsForInvalidRangeReturnsEmpty(): void
    {
        $shards = $this->strategy->resolveShards([5000, 6000]);
        $this->assertEmpty($shards);
    }
}
