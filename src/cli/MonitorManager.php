<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Facade for database monitoring operations.
 */
class MonitorManager
{
    /**
     * Get active queries.
     *
     * @param PdoDb $db
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getActiveQueries(PdoDb $db): array
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->getActiveQueries($db);
    }

    /**
     * Get active connections.
     *
     * @param PdoDb $db
     *
     * @return array<string, mixed> Returns array with 'connections' and 'summary' keys
     */
    public static function getActiveConnections(PdoDb $db): array
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->getActiveConnections($db);
    }

    /**
     * Get slow queries.
     *
     * @param PdoDb $db
     * @param float $thresholdSeconds
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getSlowQueries(PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->getSlowQueries($db, $thresholdSeconds, $limit);
    }

    /**
     * Get query statistics from QueryProfiler.
     *
     * @param PdoDb $db
     *
     * @return array<string, mixed>|null
     */
    public static function getQueryStats(PdoDb $db): ?array
    {
        if (!$db->isProfilingEnabled()) {
            return null;
        }

        $profiler = $db->getProfiler();
        if ($profiler === null) {
            return null;
        }

        $stats = $profiler->getStats();
        $aggregated = $profiler->getAggregatedStats();

        return [
            'aggregated' => $aggregated,
            'by_query' => $stats,
        ];
    }
}
