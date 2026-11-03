<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared\sharding;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\connection\sharding\DecimalInteger;
use tommyknocker\pdodb\connection\sharding\strategies\ModuloShardStrategy;
use tommyknocker\pdodb\connection\sharding\strategies\RangeShardStrategy;

final class DecimalIntegerTests extends TestCase
{
    public function testNormalizeKeepsWholeNumbersExactly(): void
    {
        $this->assertSame('42', DecimalInteger::normalize(42));
        $this->assertSame('42', DecimalInteger::normalize('0042'));
        $this->assertSame('0', DecimalInteger::normalize('-000'));
        $this->assertSame('-7', DecimalInteger::normalize('-7'));
        $this->assertSame('9223372036854775808', DecimalInteger::normalize('9223372036854775808'));
        $this->assertSame('3', DecimalInteger::normalize('3.9'), 'a fraction keeps its whole part, as (int) did');
    }

    public function testNormalizeRejectsWhatItCannotRouteExactly(): void
    {
        $this->expectException(RuntimeException::class);
        DecimalInteger::normalize(1.0E19);
    }

    public function testModuloMatchesPhpForValuesThatFit(): void
    {
        foreach ([0, 1, 7, 100, PHP_INT_MAX, -9, -10] as $n) {
            foreach ([1, 2, 3, 8, 10] as $m) {
                $this->assertSame($n % $m, DecimalInteger::modulo((string)$n, $m), "{$n} % {$m}");
            }
        }
    }

    public function testModuloIsExactBeyondInt(): void
    {
        $this->assertSame(0, DecimalInteger::modulo('9223372036854775808', 2));
        $this->assertSame(0, DecimalInteger::modulo('18446744073709551614', 2));
        $this->assertSame(1, DecimalInteger::modulo('18446744073709551615', 2));
        $this->assertSame(5, DecimalInteger::modulo('18446744073709551615', 10));
    }

    public function testCompareOrdersBySignThenMagnitude(): void
    {
        $this->assertSame(0, DecimalInteger::compare('5', '5'));
        $this->assertSame(-1, DecimalInteger::compare('9', '10'));
        $this->assertSame(1, DecimalInteger::compare('9223372036854775808', '9223372036854775807'));
        $this->assertSame(-1, DecimalInteger::compare('-9223372036854775808', '-9223372036854775807'));
        $this->assertSame(-1, DecimalInteger::compare('-1', '0'));
        $this->assertSame(1, DecimalInteger::compare('1', '-100'));
    }

    public function testModuloStrategyRoutesAKeyBeyondIntOnItsExactValue(): void
    {
        $strategy = new ModuloShardStrategy(2);

        $this->assertSame('shard1', $strategy->resolveShard('9223372036854775808'), 'even: remainder 0');
        $this->assertSame('shard1', $strategy->resolveShard('18446744073709551614'));
        $this->assertSame('shard2', $strategy->resolveShard('18446744073709551615'), 'odd: remainder 1');
    }

    public function testRangeStrategyDoesNotPlaceAKeyBeyondIntInsideAnIntRange(): void
    {
        $strategy = new RangeShardStrategy(['shard1' => [0, PHP_INT_MAX]]);

        $this->assertSame('shard1', $strategy->resolveShard((string)PHP_INT_MAX));
        $this->expectException(RuntimeException::class);
        $strategy->resolveShard('9223372036854775808');
    }
}
