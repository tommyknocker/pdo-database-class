<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\inbox\Inbox;
use tommyknocker\pdodb\inbox\InboxStorage;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\outbox\OutboxStorage;
use tommyknocker\pdodb\PdoDb;

/**
 * Inbox retention scenario shared by every dialect's tests.
 */
final class RetentionSupport
{
    /**
     * @return array{dryRun: int, deleted: int, left: int}
     */
    public static function purgeInbox(PdoDb $db, string $table): array
    {
        $db->schema()->dropTableIfExists($table);
        $inbox = new Inbox($db, 'billing', $table);
        $inbox->ensureSchema();

        foreach (['old-1', 'old-2', 'old-3', 'old-4', 'old-5', 'new-1'] as $id) {
            $inbox->once($id, static function (): void {
            });
        }
        $dialect = $db->connection->getDialect();
        $db->find()->table($table)->where('message_id', 'old-%', 'LIKE')->update([
            'processed_at' => new RawValue($dialect->outboxNowPlusSecondsSql(-10 * 86400)),
        ]);

        $dryRun = $inbox->purge('3d', true);
        // A batch of 2 forces several rounds for the 5 old rows.
        $deleted = (new InboxStorage($db, $table))->deleteProcessedOlderThan(3 * 86400, 2);
        $left = (int) $db->find()->from($table)->select(['COUNT(*)'])->getValue();

        $db->schema()->dropTableIfExists($table);

        return ['dryRun' => $dryRun, 'deleted' => $deleted, 'left' => $left];
    }

    /**
     * @return array{deleted: int, left: int}
     */
    public static function purgeOutbox(PdoDb $db, string $table): array
    {
        $db->schema()->dropTableIfExists($table);
        $storage = new OutboxStorage($db, $table);
        $storage->ensureSchema();

        foreach (['a', 'b', 'c', 'd', 'e', 'keep'] as $id) {
            $storage->insertPending('purge-' . $id, 'demo', ['n' => $id]);
        }
        $dialect = $db->connection->getDialect();
        $db->find()->table($table)->where('id', 'purge-keep', '!=')->update([
            'status' => OutboxStatuses::FAILED,
            'created_at' => new RawValue($dialect->outboxNowPlusSecondsSql(-10 * 86400)),
        ]);

        // A batch of 2 forces several rounds for the 5 old rows.
        $deleted = $storage->deleteRowsOlderThan(OutboxStatuses::FAILED, 'created_at', 3 * 86400, 2);
        $left = (int) $db->find()->from($table)->select(['COUNT(*)'])->getValue();

        $db->schema()->dropTableIfExists($table);

        return ['deleted' => $deleted, 'left' => $left];
    }
}
