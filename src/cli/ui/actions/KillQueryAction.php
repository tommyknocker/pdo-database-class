<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui\actions;

use tommyknocker\pdodb\PdoDb;

/**
 * Action to kill a query.
 */
class KillQueryAction
{
    /**
     * Kill a query by process ID.
     *
     * @param PdoDb $db Database instance
     * @param int|string $processId Process/query ID to kill
     *
     * @return bool True if successful, false otherwise
     */
    public static function execute(PdoDb $db, int|string $processId): bool
    {
        $dialect = $db->schema()->getDialect();

        try {
            return $dialect->killQuery($db, $processId);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
