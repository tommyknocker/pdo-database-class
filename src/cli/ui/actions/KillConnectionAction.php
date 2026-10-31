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

        try {
            return $dialect->killQuery($db, $processId);
        } catch (\Throwable) {
            return false;
        }
    }
}
