<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\connection\loadbalancer\RandomLoadBalancer;

final class RandomLoadBalancerTests extends TestCase
{
    public function testSelectReturnsNullWhenNoConnections(): void
    {
        $lb = new RandomLoadBalancer();
        $this->assertNull($lb->select([]));
    }

    public function testSelectSkipsFailedAndResetsWhenAllFailed(): void
    {
        $lb = new RandomLoadBalancer();

        $a = $this->createMock(ConnectionInterface::class);
        $b = $this->createMock(ConnectionInterface::class);
        $c = $this->createMock(ConnectionInterface::class);

        $conns = [
            'a' => $a,
            'b' => $b,
            'c' => $c,
        ];

        // Initially should return one of the three
        $sel1 = $lb->select($conns);
        $this->assertTrue(in_array($sel1, [$a, $b, $c], true));

        // Mark 'a' and 'b' as failed; selection should return 'c'
        $lb->markFailed('a');
        $lb->markFailed('b');
        $sel2 = $lb->select($conns);
        $this->assertSame($c, $sel2);

        // Mark 'c' as failed too -> all failed; next select should reset and return any of them
        $lb->markFailed('c');
        $sel3 = $lb->select($conns);
        $this->assertTrue(in_array($sel3, [$a, $b, $c], true));

        // MarkHealthy should allow selection of previously failed node
        $lb->markHealthy('a');
        $picked = $lb->select($conns);
        $this->assertTrue(in_array($picked, [$a, $b, $c], true));

        // Reset clears failures; any node can be picked
        $lb->reset();
        $picked2 = $lb->select($conns);
        $this->assertTrue(in_array($picked2, [$a, $b, $c], true));
    }
}


