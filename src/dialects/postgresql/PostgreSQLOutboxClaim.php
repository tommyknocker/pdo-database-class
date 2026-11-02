<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\postgresql;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\outbox\claim\OutboxClaimRows;
use tommyknocker\pdodb\outbox\claim\OutboxClaimStrategy;
use tommyknocker\pdodb\PdoDb;

/** One UPDATE ... FROM (SELECT ... FOR UPDATE SKIP LOCKED) ... RETURNING for the whole batch. */
final class PostgreSQLOutboxClaim implements OutboxClaimStrategy
{
    public function __construct(private DialectInterface $dialect)
    {
    }

    public function claim(PdoDb $db, string $tableName, string $token, int $limit, int $lockSeconds): array
    {
        $table = $this->dialect->quoteTable($tableName);
        $rows = $db->rawQuery(
            "UPDATE {$table} AS t
                SET status = 'processing',
                    locked_by = :token,
                    locked_until = {$this->dialect->outboxNowPlusSecondsSql($lockSeconds)},
                    attempts = t.attempts + 1
                FROM (
                    SELECT id FROM {$table} cand
                    WHERE status = 'pending' AND available_at <= {$this->dialect->outboxNowSql()}
                        " . OutboxClaimRows::partitionGuard($table, 'cand') . "
                    ORDER BY available_at ASC, id ASC
                    LIMIT {$limit}
                    FOR UPDATE SKIP LOCKED
                ) AS picked
                WHERE t.id = picked.id
                RETURNING t.id, t.event_type, t.payload, t.status, t.attempts,
                    t.available_at, t.created_at, t.locked_by, t.locked_until, t.last_error, t.published_at, t.headers, t.partition_key",
            ['token' => $token]
        );

        return OutboxClaimRows::normalize($rows);
    }
}
