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
     * Seeds due rows with distinct ages plus one future row and claims them in limited batches.
     *
     * @return array{first: list<string>, second: list<string>, third: list<string>, locked: list<string>}
     */
    public static function exerciseBatchClaim(PdoDb $db, string $table): array
    {
        $outbox = new Outbox($db, $table);
        foreach ([5 => 'batch-e', 4 => 'batch-d', 3 => 'batch-c', 2 => 'batch-b', 1 => 'batch-a'] as $days => $id) {
            $at = gmdate('Y-m-d H:i:s', time() - $days * 86400);
            $db->find()->table($table)->insert([
                'id' => $id,
                'event_type' => 'batch',
                'payload' => '{}',
                'status' => OutboxStatuses::PENDING,
                'attempts' => 0,
                'available_at' => $at,
                'created_at' => $at,
            ]);
        }
        $future = gmdate('Y-m-d H:i:s', time() + 3 * 86400);
        $db->find()->table($table)->insert([
            'id' => 'batch-future',
            'event_type' => 'batch',
            'payload' => '{}',
            'status' => OutboxStatuses::PENDING,
            'attempts' => 0,
            'available_at' => $future,
            'created_at' => $future,
        ]);

        $storage = $outbox->storage();
        $ids = static fn (array $messages): array => array_map(static fn (OutboxMessage $m): string => $m->id, $messages);
        $first = $storage->claimBatch('batch-worker-1', 3, 60)->messages;
        $second = $storage->claimBatch('batch-worker-2', 3, 60)->messages;
        $third = $storage->claimBatch('batch-worker-3', 3, 60)->messages;

        return [
            'first' => $ids($first),
            'second' => $ids($second),
            'third' => $ids($third),
            'locked' => array_values(array_unique(array_map(static fn (OutboxMessage $m): string => $m->lockToken, $first))),
        ];
    }

    /**
     * A delayed message must stay unclaimed until its time, while a due one is published.
     *
     * @return array{seen: list<string>, pending: int}
     */
    public static function exerciseDelayedMessage(PdoDb $db, string $table): array
    {
        $outbox = new Outbox($db, $table);
        $db->transaction(static function () use ($outbox): void {
            $outbox->add('delay.later', ['n' => 1], delaySeconds: 3600);
            $outbox->add('delay.now', ['n' => 2]);
        });

        $seen = [];
        $outbox->worker('delay-worker', batchSize: 10)->runOnce(static function (OutboxMessage $message) use (&$seen): void {
            $seen[] = $message->type;
        });

        return ['seen' => $seen, 'pending' => $outbox->countByStatus()->pending];
    }

    /**
     * Seeds a row whose payload decodes to a scalar between two good rows and runs one worker batch.
     *
     * @return array{claimed: int, published: int, markedFailed: int, seen: list<string>, status: mixed, error: mixed}
     */
    public static function exercisePoisonPayload(PdoDb $db, string $table): array
    {
        $at = gmdate('Y-m-d H:i:s', time() - 86400);
        foreach (['poison-a' => '{}', 'poison-b' => '"scalar"', 'poison-c' => '{}'] as $id => $payload) {
            $db->find()->table($table)->insert([
                'id' => $id,
                'event_type' => 'poison',
                'payload' => $payload,
                'status' => OutboxStatuses::PENDING,
                'attempts' => 0,
                'available_at' => $at,
                'created_at' => $at,
            ]);
        }

        $seen = [];
        $result = (new Outbox($db, $table))->worker('poison-worker', batchSize: 10)->runOnce(
            static function (OutboxMessage $message) use (&$seen): void {
                $seen[] = $message->id;
            }
        );
        $row = $db->find()->table($table)->where('id', 'poison-b')->getOne();
        $row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];

        return [
            'claimed' => $result->claimed,
            'published' => $result->published,
            'markedFailed' => $result->markedFailed,
            'seen' => $seen,
            'status' => $row['status'] ?? null,
            'error' => $row['last_error'] ?? null,
        ];
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
