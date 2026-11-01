<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox\claim;

use tommyknocker\pdodb\PdoDb;

/**
 * Dialect-specific way to move due pending outbox rows to `processing` for one worker.
 */
interface OutboxClaimStrategy
{
    /**
     * Claim up to `$limit` due pending rows for `$token` and return them, oldest first.
     *
     * Runs inside the caller's transaction. Rows locked by other workers are skipped where the
     * database supports it, so concurrent workers never receive the same row.
     *
     * @return list<array<string, mixed>> Claimed rows with lowercase column keys.
     */
    public function claim(PdoDb $db, string $tableName, string $token, int $limit, int $lockSeconds): array;
}
