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
