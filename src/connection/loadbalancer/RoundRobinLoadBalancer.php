<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\loadbalancer;

use tommyknocker\pdodb\connection\ConnectionInterface;

/**
 * Round-robin load balancing strategy.
 *
 * Distributes load evenly across all healthy connections in a circular manner.
 */
class RoundRobinLoadBalancer implements LoadBalancerInterface
{
    /** @var int Current index */
    protected int $currentIndex = 0;

    /** @var array<string, bool> Failed connections map */
    protected array $failedConnections = [];

    /**
     * {@inheritDoc}
     */
    public function select(array $connections): ?ConnectionInterface
    {
        if (empty($connections)) {
            return null;
        }

        $connectionNames = array_keys($connections);
        $healthyConnections = array_filter(
            $connectionNames,
            fn ($name) => !isset($this->failedConnections[$name]) || !$this->failedConnections[$name]
        );

        if (empty($healthyConnections)) {
            // All connections failed, try all again
            $this->reset();
            $healthyConnections = $connectionNames;
        }

        $healthyNames = array_values($healthyConnections);
        $this->currentIndex = $this->currentIndex % count($healthyNames);
        $selectedName = $healthyNames[$this->currentIndex];
        $this->currentIndex++;

        return $connections[$selectedName];
    }

    /**
     * {@inheritDoc}
     */
    public function markFailed(string $name): void
    {
        $this->failedConnections[$name] = true;
    }

    /**
     * {@inheritDoc}
     */
    public function markHealthy(string $name): void
    {
        unset($this->failedConnections[$name]);
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): void
    {
        $this->failedConnections = [];
        $this->currentIndex = 0;
    }
}
