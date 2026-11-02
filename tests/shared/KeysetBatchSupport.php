<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\PdoDb;

/**
 * Shared scenario for batch()/each() with a key: rows are deleted while they are iterated.
 */
final class KeysetBatchSupport
{
    /**
     * @return array{seen: list<int>, left: int, batches: list<int>}
     */
    public static function deleteWhileIterating(PdoDb $db, string $table): array
    {
        $db->schema()->dropTableIfExists($table);
        $db->schema()->createTable($table, ['id' => $db->schema()->integer()->notNull(), 'grp' => $db->schema()->integer()->notNull()]);
        for ($i = 1; $i <= 10; $i++) {
            $db->find()->table($table)->insert(['id' => $i, 'grp' => $i % 2]);
        }

        $seen = [];
        foreach ($db->find()->from($table)->where('grp', 1)->each(2, 'id') as $row) {
            $id = (int) array_change_key_case($row)['id'];
            $seen[] = $id;
            $db->find()->table($table)->where('id', $id)->delete();
        }

        $batches = [];
        foreach ($db->find()->from($table)->batch(3, 'id') as $batch) {
            $batches[] = count($batch);
        }

        $left = (int) $db->find()->from($table)->select(['COUNT(*) AS c'])->getValue();
        $db->schema()->dropTableIfExists($table);

        return ['seen' => $seen, 'left' => $left, 'batches' => $batches];
    }
}
