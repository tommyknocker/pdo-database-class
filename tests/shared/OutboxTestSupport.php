<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\PdoDb;

/**
 * Helpers for outbox integration tests (multi-connection workers).
 */
final class OutboxTestSupport
{
    /**
     * @return list<string>
     */
    public static function enqueueMany(PdoDb $db, string $table, int $count, string $eventType = 'concurrent.test'): array
    {
        if ($count < 1) {
            return [];
        }

        $outbox = new Outbox($db, $table);
        $ids = [];
        $db->transaction(function () use ($outbox, $count, $eventType, &$ids): void {
            for ($i = 0; $i < $count; $i++) {
                $ids[] = $outbox->add($eventType, ['index' => $i]);
            }
        });

        return $ids;
    }

    /**
     * Seeds old and expired rows with native datetime values and checks purge, stale lease release and listing.
     *
     * @return array{purged: int, listed: int, published: int, requeued: bool}
     */
    public static function exerciseTimestampColumns(PdoDb $db, string $table): array
    {
        $outbox = new Outbox($db, $table);
        $old = gmdate('Y-m-d H:i:s', time() - 86400 * 10);
        $expired = gmdate('Y-m-d H:i:s', time() - 120);
        $oldId = 'probe-old';
        $staleId = 'probe-stale';
        $failedId = 'probe-failed';

        $db->find()->table($table)->insert([
            'id' => $oldId,
            'event_type' => 'old',
            'payload' => '{}',
            'status' => OutboxStatuses::PUBLISHED,
            'attempts' => 1,
            'available_at' => $old,
            'created_at' => $old,
            'published_at' => $old,
        ]);
        $db->find()->table($table)->insert([
            'id' => $failedId,
            'event_type' => 'failed',
            'payload' => '{}',
            'status' => OutboxStatuses::FAILED,
            'attempts' => 3,
            'available_at' => $old,
            'created_at' => $old,
        ]);
        $db->find()->table($table)->insert([
            'id' => $staleId,
            'event_type' => 'stale',
            'payload' => '{}',
            'status' => OutboxStatuses::PROCESSING,
            'attempts' => 1,
            'available_at' => $expired,
            'created_at' => $expired,
            'locked_by' => 'dead-worker',
            'locked_until' => $expired,
        ]);

        $purged = $outbox->purge('7d')->deleted;
        $listed = count($outbox->listMessages(OutboxStatuses::FAILED));
        $requeued = $outbox->requeueFailed($failedId);
        $published = $outbox->worker('probe', batchSize: 10)->runOnce(static function (): void {
        })->published;

        return ['purged' => $purged, 'listed' => $listed, 'published' => $published, 'requeued' => $requeued];
    }

    /**
     * @param callable(): PdoDb $connectionFactory
     *
     * @return list<string> Published message ids (throws on duplicate publish).
     */
    public static function publishWithIndependentWorkers(
        callable $connectionFactory,
        string $table,
        int $workerCount,
        int $batchSize,
        int $maxRounds = 80,
    ): array {
        if ($workerCount < 1) {
            throw new RuntimeException('workerCount must be at least 1.');
        }

        /** @var list<string> $published */
        $published = [];
        $publisher = static function (OutboxMessage $message) use (&$published): void {
            if (in_array($message->id, $published, true)) {
                throw new RuntimeException('Duplicate publish for id ' . $message->id);
            }
            $published[] = $message->id;
        };

        /** @var list<PdoDb> $connections */
        $connections = [];
        for ($w = 0; $w < $workerCount; $w++) {
            $connections[] = $connectionFactory();
        }

        $probe = $connections[0];
        for ($round = 0; $round < $maxRounds; $round++) {
            $anyClaimed = false;
            foreach ($connections as $index => $connection) {
                $result = (new Outbox($connection, $table))
                    ->worker('worker-' . $index, $batchSize)
                    ->runOnce($publisher);
                if ($result->claimed > 0) {
                    $anyClaimed = true;
                }
            }

            $pending = (int) $probe->rawQueryValue(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE status = ?',
                [OutboxStatuses::PENDING]
            );
            if ($pending === 0) {
                break;
            }

            if (!$anyClaimed) {
                (new Outbox($probe, $table))->storage()->releaseStaleClaims();
            }
        }

        return $published;
    }
}
