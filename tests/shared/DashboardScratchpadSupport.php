<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\PdoDb;

/**
 * Runs statements through the dashboard's SQL scratchpad on any dialect.
 */
final class DashboardScratchpadSupport
{
    /**
     * @return array{rows: mixed, affectedAfterUpdate: mixed, affectedAfterSelect: mixed, error: mixed}
     */
    public static function run(PdoDb $db, string $createSql): array
    {
        self::drop($db);
        $db->rawQuery($createSql);

        try {
            $db->rawQuery("INSERT INTO dash_scratch (id, name) VALUES (1, 'a')");
            $db->rawQuery("INSERT INTO dash_scratch (id, name) VALUES (2, 'b')");
            $db->rawQuery("INSERT INTO dash_scratch (id, name) VALUES (3, 'c')");

            $dashboard = new DashboardHarness($db);
            $dashboard->press('8', 'enter');

            $dashboard->set('scratchpadQuery', "UPDATE dash_scratch SET name = 'x' WHERE id < 3");
            $dashboard->press('f5');
            $afterUpdate = $dashboard->prop('scratchpadAffectedRows');
            $error = $dashboard->prop('scratchpadLastError');

            $dashboard->press('tab');
            $dashboard->set('scratchpadQuery', 'SELECT id, name FROM dash_scratch ORDER BY id');
            $dashboard->press('f5');

            return [
                'rows' => array_map(static fn (array $row): array => array_change_key_case($row, CASE_LOWER), (array)$dashboard->prop('scratchpadLastResult')),
                'affectedAfterUpdate' => $afterUpdate,
                'affectedAfterSelect' => $dashboard->prop('scratchpadAffectedRows'),
                'error' => $error,
            ];
        } finally {
            self::drop($db);
        }
    }

    private static function drop(PdoDb $db): void
    {
        try {
            $db->schema()->dropTableIfExists('dash_scratch');
        } catch (\Throwable) {
            // nothing to drop
        }
    }
}
