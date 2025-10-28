<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\loadbalancer;

use tommyknocker\pdodb\connection\ConnectionInterface;

/**
 * Interface for load balancing strategies.
 *
 * Load balancers distribute queries across multiple read replicas
 * and handle connection health tracking.
 */
interface LoadBalancerInterface
{
    /**
     * Select a connection from the available pool.
     *
     * @param array<string, ConnectionInterface> $connections Available connections
     *
     * @return ConnectionInterface|null Selected connection or null if none available
     */
    public function select(array $connections): ?ConnectionInterface;

    /**
     * Mark a connection as failed.
     *
     * @param string $name Connection name
     */
    public function markFailed(string $name): void;

    /**
     * Mark a connection as healthy.
     *
     * @param string $name Connection name
     */
    public function markHealthy(string $name): void;

    /**
     * Reset all connection states.
     */
    public function reset(): void;
}
