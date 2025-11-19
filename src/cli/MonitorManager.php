<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\QueryProfiler;

/**
 * Facade for database monitoring operations.
 */
class MonitorManager
{
    /**
     * Safely convert value to string.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return '';
    }

    /**
     * Safely convert value to float.
     *
     * @param mixed $value
     *
     * @return float
     */
    protected static function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float)$value;
        }
        if (is_string($value)) {
            return (float)$value;
        }
        return 0.0;
    }
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
        $driver = $dialect->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => static::getActiveQueriesMySQL($db),
            'pgsql' => static::getActiveQueriesPostgreSQL($db),
            'sqlsrv' => static::getActiveQueriesMSSQL($db),
            'sqlite' => static::getActiveQueriesSQLite($db),
            default => [],
        };
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
        $driver = $dialect->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => static::getActiveConnectionsMySQL($db),
            'pgsql' => static::getActiveConnectionsPostgreSQL($db),
            'sqlsrv' => static::getActiveConnectionsMSSQL($db),
            'sqlite' => static::getActiveConnectionsSQLite($db),
            default => [],
        };
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
        $driver = $dialect->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => static::getSlowQueriesMySQL($db, $thresholdSeconds, $limit),
            'pgsql' => static::getSlowQueriesPostgreSQL($db, $thresholdSeconds, $limit),
            'sqlsrv' => static::getSlowQueriesMSSQL($db, $thresholdSeconds, $limit),
            'sqlite' => static::getSlowQueriesSQLite($db, $thresholdSeconds, $limit),
            default => [],
        };
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

    /**
     * Get active queries for MySQL/MariaDB.
     *
     * @param PdoDb $db
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getActiveQueriesMySQL(PdoDb $db): array
    {
        $rows = $db->rawQuery('SHOW FULL PROCESSLIST');
        $result = [];
        foreach ($rows as $row) {
            $command = $row['Command'] ?? '';
            $info = $row['Info'] ?? '';
            if (is_string($command) && $command !== 'Sleep' && is_string($info) && $info !== '') {
                $result[] = [
                    'id' => static::toString($row['Id'] ?? ''),
                    'user' => static::toString($row['User'] ?? ''),
                    'host' => static::toString($row['Host'] ?? ''),
                    'db' => static::toString($row['db'] ?? ''),
                    'command' => $command,
                    'time' => static::toString($row['Time'] ?? ''),
                    'state' => static::toString($row['State'] ?? ''),
                    'query' => $info,
                ];
            }
        }
        return $result;
    }

    /**
     * Get active connections for MySQL/MariaDB.
     *
     * @param PdoDb $db
     *
     * @return array<string, mixed> Returns array with 'connections' and 'summary' keys
     */
    protected static function getActiveConnectionsMySQL(PdoDb $db): array
    {
        $rows = $db->rawQuery('SHOW PROCESSLIST');
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => static::toString($row['Id'] ?? ''),
                'user' => static::toString($row['User'] ?? ''),
                'host' => static::toString($row['Host'] ?? ''),
                'db' => static::toString($row['db'] ?? ''),
                'command' => static::toString($row['Command'] ?? ''),
                'time' => static::toString($row['Time'] ?? ''),
                'state' => static::toString($row['State'] ?? ''),
            ];
        }

        // Get connection limits
        $status = $db->rawQuery("SHOW STATUS LIKE 'Threads_connected'");
        $maxConn = $db->rawQuery("SHOW VARIABLES LIKE 'max_connections'");
        $currentVal = $status[0]['Value'] ?? 0;
        $maxVal = $maxConn[0]['Value'] ?? 0;
        $current = is_int($currentVal) ? $currentVal : (is_string($currentVal) ? (int)$currentVal : 0);
        $max = is_int($maxVal) ? $maxVal : (is_string($maxVal) ? (int)$maxVal : 0);

        return [
            'connections' => $result,
            'summary' => [
                'current' => $current,
                'max' => $max,
                'usage_percent' => $max > 0 ? round(($current / $max) * 100, 2) : 0,
            ],
        ];
    }

    /**
     * Get slow queries for MySQL/MariaDB.
     *
     * @param PdoDb $db
     * @param float $thresholdSeconds
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getSlowQueriesMySQL(PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        $threshold = (int)($thresholdSeconds);
        $rows = $db->rawQuery('SHOW FULL PROCESSLIST');
        $result = [];
        foreach ($rows as $row) {
            $timeVal = $row['Time'] ?? 0;
            $time = is_int($timeVal) ? $timeVal : (is_string($timeVal) ? (int)$timeVal : 0);
            if ($time >= $threshold && ($row['Info'] ?? '') !== '') {
                $result[] = [
                    'id' => static::toString($row['Id'] ?? ''),
                    'user' => static::toString($row['User'] ?? ''),
                    'host' => static::toString($row['Host'] ?? ''),
                    'db' => static::toString($row['db'] ?? ''),
                    'time' => (string)$time,
                    'query' => static::toString($row['Info'] ?? ''),
                ];
            }
        }
        usort($result, static fn ($a, $b) => (int)$b['time'] <=> (int)$a['time']);
        return array_slice($result, 0, $limit);
    }

    /**
     * Get active queries for PostgreSQL.
     *
     * @param PdoDb $db
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getActiveQueriesPostgreSQL(PdoDb $db): array
    {
        $sql = "SELECT pid, usename, application_name, datname, state, query_start, state_change, wait_event_type, wait_event, query
                FROM pg_stat_activity
                WHERE state != 'idle' AND query IS NOT NULL AND query != ''";
        $rows = $db->rawQuery($sql);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'pid' => static::toString($row['pid'] ?? ''),
                'user' => static::toString($row['usename'] ?? ''),
                'application' => static::toString($row['application_name'] ?? ''),
                'database' => static::toString($row['datname'] ?? ''),
                'state' => static::toString($row['state'] ?? ''),
                'query_start' => static::toString($row['query_start'] ?? ''),
                'wait_event' => static::toString($row['wait_event'] ?? ''),
                'query' => static::toString($row['query'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Get active connections for PostgreSQL.
     *
     * @param PdoDb $db
     *
     * @return array<string, mixed> Returns array with 'connections' and 'summary' keys
     */
    protected static function getActiveConnectionsPostgreSQL(PdoDb $db): array
    {
        $rows = $db->rawQuery('SELECT pid, usename, application_name, datname, state, backend_start, state_change, wait_event_type, wait_event FROM pg_stat_activity');
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'pid' => static::toString($row['pid'] ?? ''),
                'user' => static::toString($row['usename'] ?? ''),
                'application' => static::toString($row['application_name'] ?? ''),
                'database' => static::toString($row['datname'] ?? ''),
                'state' => static::toString($row['state'] ?? ''),
                'backend_start' => static::toString($row['backend_start'] ?? ''),
                'wait_event' => static::toString($row['wait_event'] ?? ''),
            ];
        }

        // Get connection limits
        $maxConn = $db->rawQueryValue("SELECT setting FROM pg_settings WHERE name = 'max_connections'");
        $current = count($result);
        $maxVal = $maxConn ?? 0;
        $max = is_int($maxVal) ? $maxVal : (is_string($maxVal) ? (int)$maxVal : 0);

        return [
            'connections' => $result,
            'summary' => [
                'current' => $current,
                'max' => $max,
                'usage_percent' => $max > 0 ? round(($current / $max) * 100, 2) : 0,
            ],
        ];
    }

    /**
     * Get slow queries for PostgreSQL.
     *
     * @param PdoDb $db
     * @param float $thresholdSeconds
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getSlowQueriesPostgreSQL(PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        // Try pg_stat_statements first
        $hasExtension = false;

        try {
            $ext = $db->rawQueryValue("SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_stat_statements'");
            $extVal = $ext ?? 0;
            $extInt = is_int($extVal) ? $extVal : (is_string($extVal) ? (int)$extVal : 0);
            $hasExtension = $extInt > 0;
        } catch (\Exception $e) {
            // Extension not available
        }

        if ($hasExtension) {
            $sql = 'SELECT query, calls, total_exec_time, mean_exec_time, max_exec_time
                    FROM pg_stat_statements
                    WHERE mean_exec_time >= ?
                    ORDER BY mean_exec_time DESC
                    LIMIT ?';
            $rows = $db->rawQuery($sql, [$thresholdSeconds * 1000, $limit]); // PostgreSQL uses milliseconds
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'query' => static::toString($row['query'] ?? ''),
                    'calls' => static::toString($row['calls'] ?? ''),
                    'total_time' => static::toString($row['total_exec_time'] ?? '') . 'ms',
                    'avg_time' => static::toString($row['mean_exec_time'] ?? '') . 'ms',
                    'max_time' => static::toString($row['max_exec_time'] ?? '') . 'ms',
                ];
            }
            return $result;
        }

        // Fallback to pg_stat_activity (current queries only)
        $sql = "SELECT pid, usename, datname, state, query, NOW() - query_start as duration
                FROM pg_stat_activity
                WHERE state = 'active' AND query IS NOT NULL AND query != ''
                AND (NOW() - query_start) >= INTERVAL '{$thresholdSeconds} seconds'
                ORDER BY query_start
                LIMIT ?";
        $rows = $db->rawQuery($sql, [$limit]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'pid' => static::toString($row['pid'] ?? ''),
                'user' => static::toString($row['usename'] ?? ''),
                'database' => static::toString($row['datname'] ?? ''),
                'duration' => static::toString($row['duration'] ?? ''),
                'query' => static::toString($row['query'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Get active queries for MSSQL.
     *
     * @param PdoDb $db
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getActiveQueriesMSSQL(PdoDb $db): array
    {
        try {
            $sql = "SELECT r.session_id, r.request_id, r.start_time, r.status, r.command, r.database_id,
                           s.login_name, s.host_name, s.program_name, DB_NAME(r.database_id) as database_name,
                           SUBSTRING(t.text, (r.statement_start_offset/2)+1,
                           ((CASE r.statement_end_offset WHEN -1 THEN DATALENGTH(t.text)
                           ELSE r.statement_end_offset END - r.statement_start_offset)/2)+1) AS query_text
                    FROM sys.dm_exec_requests r
                    INNER JOIN sys.dm_exec_sessions s ON r.session_id = s.session_id
                    OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) t
                    WHERE r.session_id != @@SPID AND r.status != 'sleeping'";
            $rows = $db->rawQuery($sql);
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'session_id' => static::toString($row['session_id'] ?? ''),
                    'request_id' => static::toString($row['request_id'] ?? ''),
                    'login' => static::toString($row['login_name'] ?? ''),
                    'host' => static::toString($row['host_name'] ?? ''),
                    'database' => static::toString($row['database_name'] ?? ''),
                    'status' => static::toString($row['status'] ?? ''),
                    'command' => static::toString($row['command'] ?? ''),
                    'start_time' => static::toString($row['start_time'] ?? ''),
                    'query' => static::toString($row['query_text'] ?? ''),
                ];
            }
            return $result;
        } catch (\Exception $e) {
            // Return empty array if query fails (insufficient permissions, etc.)
            return [];
        }
    }

    /**
     * Get active connections for MSSQL.
     *
     * @param PdoDb $db
     *
     * @return array<string, mixed> Returns array with 'connections' and 'summary' keys
     */
    protected static function getActiveConnectionsMSSQL(PdoDb $db): array
    {
        try {
            $rows = $db->rawQuery('SELECT session_id, login_name, host_name, program_name, database_id, login_time, last_request_start_time, status FROM sys.dm_exec_sessions WHERE is_user_process = 1');
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'session_id' => static::toString($row['session_id'] ?? ''),
                    'login' => static::toString($row['login_name'] ?? ''),
                    'host' => static::toString($row['host_name'] ?? ''),
                    'program' => static::toString($row['program_name'] ?? ''),
                    'database_id' => static::toString($row['database_id'] ?? ''),
                    'login_time' => static::toString($row['login_time'] ?? ''),
                    'last_request' => static::toString($row['last_request_start_time'] ?? ''),
                    'status' => static::toString($row['status'] ?? ''),
                ];
            }

            // Get connection limits
            $maxConn = $db->rawQueryValue("SELECT value FROM sys.configurations WHERE name = 'user connections'");
            $current = count($result);
            $maxVal = $maxConn ?? 0;
            $max = is_int($maxVal) ? $maxVal : (is_string($maxVal) ? (int)$maxVal : 0);

            return [
                'connections' => $result,
                'summary' => [
                    'current' => $current,
                    'max' => $max,
                    'usage_percent' => $max > 0 ? round(($current / $max) * 100, 2) : 0,
                ],
            ];
        } catch (\Exception $e) {
            // Return empty structure if query fails (insufficient permissions, etc.)
            return [
                'connections' => [],
                'summary' => [
                    'current' => 0,
                    'max' => 0,
                    'usage_percent' => 0,
                ],
            ];
        }
    }

    /**
     * Get slow queries for MSSQL.
     *
     * @param PdoDb $db
     * @param float $thresholdSeconds
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getSlowQueriesMSSQL(PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        try {
            $thresholdMs = (int)($thresholdSeconds * 1000);
            $sql = 'SELECT TOP ? qs.execution_count, qs.total_elapsed_time / 1000.0 as total_time_sec,
                           qs.total_elapsed_time / qs.execution_count / 1000.0 as avg_time_sec,
                           qs.max_elapsed_time / 1000.0 as max_time_sec,
                           SUBSTRING(t.text, (qs.statement_start_offset/2)+1,
                           ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(t.text)
                           ELSE qs.statement_end_offset END - qs.statement_start_offset)/2)+1) AS query_text
                    FROM sys.dm_exec_query_stats qs
                    CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) t
                    WHERE qs.total_elapsed_time / qs.execution_count >= ?
                    ORDER BY qs.total_elapsed_time / qs.execution_count DESC';
            $rows = $db->rawQuery($sql, [$limit, $thresholdMs]);
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'executions' => static::toString($row['execution_count'] ?? ''),
                    'total_time' => number_format(static::toFloat($row['total_time_sec'] ?? 0), 2) . 's',
                    'avg_time' => number_format(static::toFloat($row['avg_time_sec'] ?? 0), 2) . 's',
                    'max_time' => number_format(static::toFloat($row['max_time_sec'] ?? 0), 2) . 's',
                    'query' => static::toString($row['query_text'] ?? ''),
                ];
            }
            return $result;
        } catch (\Exception $e) {
            // Return empty array if query fails (insufficient permissions, etc.)
            return [];
        }
    }

    /**
     * Get active queries for SQLite (limited - uses QueryProfiler if available).
     *
     * @param PdoDb $db
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getActiveQueriesSQLite(PdoDb $db): array
    {
        // SQLite doesn't have built-in query monitoring
        // Return empty array with note
        return [];
    }

    /**
     * Get active connections for SQLite (limited - shows PDOdb connection pool).
     *
     * @param PdoDb $db
     *
     * @return array<string, mixed> Returns array with 'connections' and 'summary' keys
     */
    protected static function getActiveConnectionsSQLite(PdoDb $db): array
    {
        // SQLite doesn't have multiple connections concept
        // Show PDOdb connection pool if available
        $connections = [];

        try {
            $reflection = new \ReflectionClass($db);
            $property = $reflection->getProperty('connections');
            $property->setAccessible(true);
            $pool = $property->getValue($db);
            if (is_array($pool)) {
                foreach ($pool as $name => $conn) {
                    if (is_object($conn) && method_exists($conn, 'getDriverName')) {
                        $connections[] = [
                            'name' => is_string($name) ? $name : '',
                            'driver' => $conn->getDriverName(),
                            'status' => 'active',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore reflection errors
        }

        return [
            'connections' => $connections,
            'summary' => [
                'current' => count($connections),
                'max' => 0,
                'usage_percent' => 0,
                'note' => 'SQLite does not support multiple connections. Showing PDOdb connection pool.',
            ],
        ];
    }

    /**
     * Get slow queries for SQLite (uses QueryProfiler).
     *
     * @param PdoDb $db
     * @param float $thresholdSeconds
     * @param int $limit
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getSlowQueriesSQLite(PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        if (!$db->isProfilingEnabled()) {
            return [];
        }

        $profiler = $db->getProfiler();
        if ($profiler === null) {
            return [];
        }

        $stats = $profiler->getStats();
        $result = [];
        foreach ($stats as $stat) {
            if ($stat['avg_time'] >= $thresholdSeconds) {
                $result[] = [
                    'query' => $stat['sql'],
                    'count' => (string)$stat['count'],
                    'avg_time' => number_format($stat['avg_time'], 3) . 's',
                    'max_time' => number_format($stat['max_time'], 3) . 's',
                    'slow_queries' => (string)$stat['slow_queries'],
                ];
            }
        }

        usort($result, static fn ($a, $b) => (float)str_replace('s', '', $b['avg_time']) <=> (float)str_replace('s', '', $a['avg_time']));
        return array_slice($result, 0, $limit);
    }
}
