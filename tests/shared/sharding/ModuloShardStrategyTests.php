<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared\sharding;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\connection\sharding\strategies\ModuloShardStrategy;

/**
 * Tests for ModuloShardStrategy.
 */
final class ModuloShardStrategyTests extends TestCase
{
    public function testResolveShardForThreeShards(): void
    {
        $strategy = new ModuloShardStrategy(3);

        $this->assertEquals('shard1', $strategy->resolveShard(0));
        $this->assertEquals('shard2', $strategy->resolveShard(1));
        $this->assertEquals('shard3', $strategy->resolveShard(2));
        $this->assertEquals('shard1', $strategy->resolveShard(3));
        $this->assertEquals('shard2', $strategy->resolveShard(4));
        $this->assertEquals('shard3', $strategy->resolveShard(5));
    }

    public function testResolveShardForFiveShards(): void
    {
        $strategy = new ModuloShardStrategy(5);

        $this->assertEquals('shard1', $strategy->resolveShard(0));
        $this->assertEquals('shard2', $strategy->resolveShard(1));
        $this->assertEquals('shard3', $strategy->resolveShard(2));
        $this->assertEquals('shard4', $strategy->resolveShard(3));
        $this->assertEquals('shard5', $strategy->resolveShard(4));
        $this->assertEquals('shard1', $strategy->resolveShard(5));
    }

    public function testResolveShardThrowsExceptionForNonNumericValue(): void
    {
        $strategy = new ModuloShardStrategy(3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Modulo strategy requires numeric value');

        $strategy->resolveShard('not-a-number');
    }

    public function testResolveShardsForArray(): void
    {
        $strategy = new ModuloShardStrategy(3);

        $shards = $strategy->resolveShards([1, 2, 3, 4, 5]);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
        $this->assertContains('shard3', $shards);
    }

    public function testResolveShardsForSingleValue(): void
    {
        $strategy = new ModuloShardStrategy(3);

        $shards = $strategy->resolveShards(5);
        $this->assertCount(1, $shards);
        $this->assertEquals('shard3', $shards[0]);
    }

    public function testResolveShardsSkipsInvalidValues(): void
    {
        $strategy = new ModuloShardStrategy(3);

        $shards = $strategy->resolveShards([1, 'invalid', 3]);
        $this->assertContains('shard2', $shards);
        $this->assertContains('shard1', $shards);
    }

    public function testResolveShardThrowsExceptionForInvalidShardCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Shard count must be at least 1');

        new ModuloShardStrategy(0);
    }
}
