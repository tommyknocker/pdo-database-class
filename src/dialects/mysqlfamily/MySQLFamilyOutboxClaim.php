<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysqlfamily;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\outbox\claim\OutboxClaimRows;
use tommyknocker\pdodb\outbox\claim\OutboxClaimStrategy;
use tommyknocker\pdodb\PdoDb;

/** UPDATE ... JOIN (SELECT ... FOR UPDATE SKIP LOCKED), then read the batch back by its token. */
final class MySQLFamilyOutboxClaim implements OutboxClaimStrategy
{
    public function __construct(private DialectInterface $dialect)
    {
    }

    public function claim(PdoDb $db, string $tableName, string $token, int $limit, int $lockSeconds): array
    {
        $table = $this->dialect->quoteTable($tableName);
        $db->rawQuery(
            "UPDATE {$table} AS t
                INNER JOIN (
                    SELECT id FROM (
                        SELECT id FROM {$table}
                        WHERE status = 'pending' AND available_at <= {$this->dialect->outboxNowSql()}
                        ORDER BY available_at ASC, id ASC
                        LIMIT {$limit}
                        FOR UPDATE SKIP LOCKED
                    ) AS candidate
                ) AS picked ON t.id = picked.id
                SET t.status = 'processing',
                    t.locked_by = :token,
                    t.locked_until = {$this->dialect->outboxNowPlusSecondsSql($lockSeconds)},
                    t.attempts = t.attempts + 1",
            ['token' => $token]
        );

        $rows = $db->rawQuery(
            'SELECT ' . OutboxClaimRows::COLUMNS . " FROM {$table}
                WHERE locked_by = :token AND status = 'processing'
                ORDER BY available_at ASC, id ASC",
            ['token' => $token]
        );

        return OutboxClaimRows::normalize($rows, false);
    }
}
