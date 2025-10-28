<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\loadbalancer;

use tommyknocker\pdodb\connection\ConnectionInterface;

/**
 * Weighted load balancing strategy.
 *
 * Distributes load based on connection weights.
 * Higher weight means more traffic.
 */
class WeightedLoadBalancer implements LoadBalancerInterface
{
    /** @var array<string, int> Connection weights */
    protected array $weights = [];

    /** @var array<string, bool> Failed connections map */
    protected array $failedConnections = [];

    /**
     * Set weights for connections.
     *
     * @param array<string, int> $weights Connection name => weight map
     */
    public function setWeights(array $weights): void
    {
        $this->weights = $weights;
    }

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

        // Build weighted pool
        $weightedPool = [];
        foreach ($healthyConnections as $name) {
            $weight = $this->weights[$name] ?? 1;
            for ($i = 0; $i < $weight; $i++) {
                $weightedPool[] = $name;
            }
        }

        $randomIndex = array_rand($weightedPool);
        $selectedName = $weightedPool[$randomIndex];

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
    }
}
