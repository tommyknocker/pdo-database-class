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

    public function testResolveShardsForBetweenRangeWithNonNumericValues(): void
    {
        // Non-numeric values in BETWEEN range should return empty
        $shards = $this->strategy->resolveShards(['not-a-number', 'also-not-a-number']);
        $this->assertEmpty($shards);
    }

    public function testResolveShardsForBetweenRangeWithPartialNonNumericValues(): void
    {
        // One non-numeric value in BETWEEN range should return empty
        $shards = $this->strategy->resolveShards([500, 'not-a-number']);
        $this->assertEmpty($shards);
    }

    public function testResolveShardsForArrayWithMoreThanTwoElements(): void
    {
        // Array with more than 2 elements should be treated as IN array
        $shards = $this->strategy->resolveShards([500, 1500, 2500, 3500]);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
        $this->assertContains('shard3', $shards);
    }

    public function testResolveShardsForInArrayWithInvalidValues(): void
    {
        // IN array with invalid values should skip them
        $shards = $this->strategy->resolveShards([500, 'invalid', 1500, 'also-invalid']);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
        $this->assertNotContains('shard3', $shards);
    }

    public function testResolveShardsForInArrayWithAllInvalidValues(): void
    {
        // IN array with all invalid values should return empty
        $shards = $this->strategy->resolveShards(['invalid1', 'invalid2', 'invalid3']);
        $this->assertEmpty($shards);
    }

    public function testResolveShardsForSingleInvalidValue(): void
    {
        // Single invalid value should return empty array
        $shards = $this->strategy->resolveShards('not-a-number');
        $this->assertEmpty($shards);
    }

    public function testResolveShardsForSingleValueOutsideRange(): void
    {
        // Single value outside range should return empty array
        $shards = $this->strategy->resolveShards(5000);
        $this->assertEmpty($shards);
    }

    public function testResolveShardWithInvalidRangeInConfig(): void
    {
        // Range with invalid count (not 2 elements) should be skipped
        $strategy = new RangeShardStrategy([
            'shard1' => [0, 1000],
            'shard2' => [1001], // Invalid: only 1 element
            'shard3' => [2001, 3000],
        ]);

        // Should still resolve correctly for valid ranges
        $this->assertEquals('shard1', $strategy->resolveShard(500));
        $this->assertEquals('shard3', $strategy->resolveShard(2500));
    }

    public function testResolveShardsWithInvalidRangeInConfig(): void
    {
        // Range with invalid count should be skipped in resolveShards
        $strategy = new RangeShardStrategy([
            'shard1' => [0, 1000],
            'shard2' => [1001], // Invalid: only 1 element
            'shard3' => [2001, 3000],
        ]);

        $shards = $strategy->resolveShards([500, 2500]);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard3', $shards);
        $this->assertNotContains('shard2', $shards);
    }

    public function testResolveShardsForBetweenRangeWithOverlappingRanges(): void
    {
        // Test BETWEEN range that overlaps with shard ranges
        $shards = $this->strategy->resolveShards([950, 1050]);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
    }

    public function testResolveShardsForBetweenRangeExactlyMatchingShardRange(): void
    {
        // Test BETWEEN range that exactly matches a shard range
        $shards = $this->strategy->resolveShards([1001, 2000]);
        $this->assertContains('shard2', $shards);
        $this->assertCount(1, $shards);
    }

    public function testResolveShardsForBetweenRangeBeforeFirstShard(): void
    {
        // Test BETWEEN range that is before first shard
        $shards = $this->strategy->resolveShards([-100, -50]);
        $this->assertEmpty($shards);
    }

    public function testResolveShardsForBetweenRangeAfterLastShard(): void
    {
        // Test BETWEEN range that is after last shard
        $shards = $this->strategy->resolveShards([3500, 4000]);
        $this->assertEmpty($shards);
    }

    public function testResolveShardsForBetweenRangeWithNullValues(): void
    {
        // Test BETWEEN range with null values (should be treated as non-numeric)
        $shards = $this->strategy->resolveShards([null, null]);
        $this->assertEmpty($shards);
    }

    public function testResolveShardsForInArrayWithDuplicateValues(): void
    {
        // IN array with duplicate values should return unique shards
        $shards = $this->strategy->resolveShards([500, 500, 1500, 1500]);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
        $this->assertCount(2, $shards);
    }

    public function testResolveShardsForEmptyArray(): void
    {
        // Empty array should return empty shards
        $shards = $this->strategy->resolveShards([]);
        $this->assertEmpty($shards);
    }

    public function testResolveShardsForArrayWithSingleElement(): void
    {
        // Array with single element should be treated as IN array
        $shards = $this->strategy->resolveShards([1500]);
        $this->assertContains('shard2', $shards);
        $this->assertCount(1, $shards);
    }

    public function testResolveShardWithFloatValue(): void
    {
        // Float values should be converted to int
        $this->assertEquals('shard1', $this->strategy->resolveShard(500.7));
        $this->assertEquals('shard2', $this->strategy->resolveShard(1500.9));
    }

    public function testResolveShardsForBetweenRangeWithFloatValues(): void
    {
        // Float values in BETWEEN range should be converted to int
        $shards = $this->strategy->resolveShards([500.5, 2500.7]);
        $this->assertContains('shard1', $shards);
        $this->assertContains('shard2', $shards);
        $this->assertContains('shard3', $shards);
    }
}
