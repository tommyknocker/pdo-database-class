<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\outbox\claim\OutboxClaimRows;
use tommyknocker\pdodb\outbox\claim\OutboxClaimStrategy;
use tommyknocker\pdodb\PdoDb;

/** Queue pattern: UPDATE through a TOP (n) ... READPAST CTE with OUTPUT. */
final class MSSQLOutboxClaim implements OutboxClaimStrategy
{
    public function __construct(private DialectInterface $dialect)
    {
    }

    public function claim(PdoDb $db, string $tableName, string $token, int $limit, int $lockSeconds): array
    {
        $table = $this->dialect->quoteTable($tableName);
        $rows = $db->rawQuery(
            "WITH picked AS (
                SELECT TOP ({$limit}) * FROM {$table} cand WITH (READPAST, UPDLOCK, ROWLOCK)
                WHERE status = 'pending' AND available_at <= {$this->dialect->outboxNowSql()}
                    " . OutboxClaimRows::partitionGuard($table, 'cand') . "
                ORDER BY available_at ASC, id ASC
            )
            UPDATE picked
                SET status = 'processing',
                    locked_by = :token,
                    locked_until = {$this->dialect->outboxNowPlusSecondsSql($lockSeconds)},
                    attempts = attempts + 1
                OUTPUT INSERTED.id, INSERTED.event_type, INSERTED.payload, INSERTED.status, INSERTED.attempts,
                    INSERTED.available_at, INSERTED.created_at, INSERTED.locked_by, INSERTED.locked_until,
                    INSERTED.last_error, INSERTED.published_at, INSERTED.headers, INSERTED.partition_key",
            ['token' => $token]
        );

        return OutboxClaimRows::normalize($rows);
    }
}
