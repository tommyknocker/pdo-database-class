<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\PdoDb;

/**
 * Server monitoring: active queries, connections, metrics.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectMonitoringInterface
{
    /**
     * Get active queries for this dialect.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, array<string, mixed>> Array of active queries with their details
     */
    public function getActiveQueries(PdoDb $db): array;

    /**
     * Get active connections for this dialect.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> Returns array with 'connections' and 'summary' keys
     */
    public function getActiveConnections(PdoDb $db): array;

    /**
     * Get slow queries for this dialect.
     *
     * @param PdoDb $db Database instance
     * @param float $thresholdSeconds Threshold in seconds for considering a query slow
     * @param int $limit Maximum number of queries to return
     *
     * @return array<int, array<string, mixed>> Array of slow queries sorted by execution time
     */
    public function getSlowQueries(PdoDb $db, float $thresholdSeconds, int $limit): array;

    /**
     * Get server metrics for this dialect.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> Server metrics (version, uptime, key performance indicators)
     */
    public function getServerMetrics(PdoDb $db): array;

    /**
     * Get server variables (configuration settings).
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, array<string, mixed>> Array of variable arrays with 'name' and 'value' keys
     */
    public function getServerVariables(PdoDb $db): array;

    /**
     * Kill a query/process for this dialect.
     *
     * @param PdoDb $db Database instance
     * @param int|string $processId Process/query ID to kill
     *
     * @return bool True if successful, false otherwise
     */
    public function killQuery(PdoDb $db, int|string $processId): bool;
}
