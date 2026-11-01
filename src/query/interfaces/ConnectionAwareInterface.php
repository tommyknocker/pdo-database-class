<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

use tommyknocker\pdodb\connection\ConnectionInterface;

/**
 * A query builder component that follows the connection a query is routed to (read replica, shard).
 */
interface ConnectionAwareInterface
{
    /**
     * Point the component at another connection. Shared collaborators (execution engine, value resolver)
     * are rebound separately by the owner, so state kept on them is preserved.
     */
    public function rebindConnection(ConnectionInterface $connection): void;
}
