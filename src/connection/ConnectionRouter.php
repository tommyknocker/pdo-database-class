<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use RuntimeException;
use tommyknocker\pdodb\connection\loadbalancer\LoadBalancerInterface;
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;

/**
 * Connection Router.
 *
 * Routes queries to read or write connections based on operation type.
 * Manages sticky writes for read-after-write consistency.
 */
class ConnectionRouter
{
    /** @var array<string, ConnectionInterface> Write connections */
    protected array $writeConnections = [];

    /** @var array<string, ConnectionInterface> Read connections */
    protected array $readConnections = [];

    /** @var LoadBalancerInterface Load balancer for read connections */
    protected LoadBalancerInterface $loadBalancer;

    /** @var bool Whether sticky writes are enabled */
    protected bool $stickyWritesEnabled = false;

    /** @var int Sticky writes duration in seconds */
    protected int $stickyWritesDuration = 0;

    /** @var float|null Last write timestamp */
    protected ?float $lastWriteTime = null;

    /** @var bool Force all reads to use write connection */
    protected bool $forceWriteMode = false;

    /** @var bool Currently in transaction */
    protected bool $inTransaction = false;

    /**
     * ConnectionRouter constructor.
     *
     * @param LoadBalancerInterface|null $loadBalancer Load balancer strategy
     */
    public function __construct(?LoadBalancerInterface $loadBalancer = null)
    {
        $this->loadBalancer = $loadBalancer ?? new RoundRobinLoadBalancer();
    }

    /**
     * Add a write connection.
     *
     * @param string $name Connection name
     * @param ConnectionInterface $connection Connection instance
     */
    public function addWriteConnection(string $name, ConnectionInterface $connection): void
    {
        $this->writeConnections[$name] = $connection;
    }

    /**
     * Add a read connection.
     *
     * @param string $name Connection name
     * @param ConnectionInterface $connection Connection instance
     */
    public function addReadConnection(string $name, ConnectionInterface $connection): void
    {
        $this->readConnections[$name] = $connection;
    }

    /**
     * Get connection for read operations.
     *
     * @return ConnectionInterface
     * @throws RuntimeException If no suitable connection is available
     */
    public function getReadConnection(): ConnectionInterface
    {
        // If in transaction, always use write connection
        if ($this->inTransaction) {
            return $this->getWriteConnection();
        }

        // If force write mode is enabled, use write connection
        if ($this->forceWriteMode) {
            return $this->getWriteConnection();
        }

        // If sticky writes are enabled and recent write occurred, use write connection
        if ($this->shouldUseWriteForRead()) {
            return $this->getWriteConnection();
        }

        // Try to get a read replica
        if (!empty($this->readConnections)) {
            $connection = $this->loadBalancer->select($this->readConnections);
            if ($connection !== null) {
                return $connection;
            }
        }

        // Fallback to write connection if no read replicas available
        return $this->getWriteConnection();
    }

    /**
     * Get connection for write operations.
     *
     * @return ConnectionInterface
     * @throws RuntimeException If no write connection is available
     */
    public function getWriteConnection(): ConnectionInterface
    {
        if (empty($this->writeConnections)) {
            throw new RuntimeException('No write connection available');
        }

        // Mark write time for sticky writes
        $this->lastWriteTime = microtime(true);

        // Return first available write connection (typically only one master)
        return reset($this->writeConnections);
    }

    /**
     * Enable sticky writes.
     *
     * @param int $durationSeconds Duration in seconds to use write connection after a write
     */
    public function enableStickyWrites(int $durationSeconds): void
    {
        $this->stickyWritesEnabled = true;
        $this->stickyWritesDuration = $durationSeconds;
    }

    /**
     * Disable sticky writes.
     */
    public function disableStickyWrites(): void
    {
        $this->stickyWritesEnabled = false;
        $this->lastWriteTime = null;
    }

    /**
     * Enable force write mode (all reads go to master).
     */
    public function enableForceWrite(): void
    {
        $this->forceWriteMode = true;
    }

    /**
     * Disable force write mode.
     */
    public function disableForceWrite(): void
    {
        $this->forceWriteMode = false;
    }

    /**
     * Set transaction state.
     *
     * @param bool $inTransaction Whether currently in transaction
     */
    public function setTransactionState(bool $inTransaction): void
    {
        $this->inTransaction = $inTransaction;
    }

    /**
     * Check if write connection should be used for read.
     *
     * @return bool
     */
    protected function shouldUseWriteForRead(): bool
    {
        if (!$this->stickyWritesEnabled || $this->lastWriteTime === null) {
            return false;
        }

        $elapsed = microtime(true) - $this->lastWriteTime;
        return $elapsed < $this->stickyWritesDuration;
    }

    /**
     * Get load balancer instance.
     *
     * @return LoadBalancerInterface
     */
    public function getLoadBalancer(): LoadBalancerInterface
    {
        return $this->loadBalancer;
    }

    /**
     * Set load balancer.
     *
     * @param LoadBalancerInterface $loadBalancer
     */
    public function setLoadBalancer(LoadBalancerInterface $loadBalancer): void
    {
        $this->loadBalancer = $loadBalancer;
    }

    /**
     * Health check for a connection.
     *
     * @param ConnectionInterface $connection
     *
     * @return bool True if connection is healthy
     */
    public function healthCheck(ConnectionInterface $connection): bool
    {
        try {
            $stmt = $connection->query('SELECT 1');
            if ($stmt !== false) {
                $stmt->execute();
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get all write connections.
     *
     * @return array<string, ConnectionInterface>
     */
    public function getWriteConnections(): array
    {
        return $this->writeConnections;
    }

    /**
     * Get all read connections.
     *
     * @return array<string, ConnectionInterface>
     */
    public function getReadConnections(): array
    {
        return $this->readConnections;
    }

    /**
     * Check if sticky writes are enabled.
     *
     * @return bool
     */
    public function isStickyWritesEnabled(): bool
    {
        return $this->stickyWritesEnabled;
    }

    /**
     * Check if force write mode is enabled.
     *
     * @return bool
     */
    public function isForceWriteMode(): bool
    {
        return $this->forceWriteMode;
    }

    /**
     * Check if currently in transaction.
     *
     * @return bool
     */
    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }
}
