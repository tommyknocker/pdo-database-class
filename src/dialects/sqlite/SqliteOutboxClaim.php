<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\sqlite;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\outbox\claim\OutboxClaimRows;
use tommyknocker\pdodb\outbox\claim\OutboxClaimStrategy;
use tommyknocker\pdodb\PdoDb;

/** SQLite has a single writer, so one UPDATE ... RETURNING claims the batch. */
final class SqliteOutboxClaim implements OutboxClaimStrategy
{
    public function __construct(private DialectInterface $dialect)
    {
    }

    public function claim(PdoDb $db, string $tableName, string $token, int $limit, int $lockSeconds): array
    {
        $table = $this->dialect->quoteTable($tableName);
        $rows = $db->rawQuery(
            "UPDATE {$table}
                SET status = 'processing',
                    locked_by = :token,
                    locked_until = {$this->dialect->outboxNowPlusSecondsSql($lockSeconds)},
                    attempts = attempts + 1
                WHERE id IN (
                    SELECT id FROM {$table}
                    WHERE status = 'pending' AND available_at <= {$this->dialect->outboxNowSql()}
                    ORDER BY available_at ASC, id ASC
                    LIMIT {$limit}
                )
                RETURNING " . OutboxClaimRows::COLUMNS,
            ['token' => $token]
        );

        return OutboxClaimRows::normalize($rows);
    }
}
