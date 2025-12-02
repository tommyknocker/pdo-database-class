<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\actions;

use tommyknocker\pdodb\PdoDb;

/**
 * Action to kill a connection.
 */
class KillConnectionAction
{
    /**
     * Kill a connection by process/session ID.
     *
     * @param PdoDb $db Database instance
     * @param int|string $processId Process/session ID to kill
     *
     * @return bool True if successful, false otherwise
     */
    public static function execute(PdoDb $db, int|string $processId): bool
    {
        $dialect = $db->schema()->getDialect();
        $driver = $dialect->getDriverName();

        try {
            // For most databases, killing a connection is the same as killing a query
            // PostgreSQL has separate pg_terminate_backend() which we can use
            if ($driver === 'pgsql') {
                $pid = is_int($processId) ? $processId : (int)$processId;
                $result = $db->rawQueryValue("SELECT pg_terminate_backend({$pid})");
                return (bool)$result;
            }

            // For MySQL/MariaDB, use KILL CONNECTION
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $processIdInt = is_int($processId) ? $processId : (int)$processId;
                $db->rawQuery("KILL CONNECTION {$processIdInt}");
                return true;
            }

            // For other databases, use killQuery
            return $dialect->killQuery($db, $processId);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
