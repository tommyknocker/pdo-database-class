<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox\claim;

/**
 * Shared helpers for claim strategies.
 */
final class OutboxClaimRows
{
    public const string COLUMNS = 'id, event_type, payload, status, attempts, available_at, created_at, locked_by, locked_until, last_error, published_at, headers, partition_key';

    /**
     * The condition that keeps a message back while an earlier message with the same partition key is still waiting or
     * being delivered, so messages of one key go out one at a time in the order they were added. Ids are time-ordered,
     * so "earlier" is `id <`. A message without a key is never held back; a failed message does not hold anything back.
     *
     * @param string $quotedTable The outbox table, quoted
     * @param string $alias Alias of the candidate row in the surrounding query
     */
    public static function partitionGuard(string $quotedTable, string $alias): string
    {
        return "AND NOT EXISTS (
                            SELECT 1 FROM {$quotedTable} blocker
                            WHERE blocker.partition_key = {$alias}.partition_key
                              AND blocker.status IN ('pending', 'processing')
                              AND blocker.id < {$alias}.id
                        )";
    }

    /**
     * Lowercase the column keys and order rows by `available_at`, then `id`.
     *
     * @param array<int, mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $rows, bool $sort = true): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = array_change_key_case($row, CASE_LOWER);
            }
        }
        if ($sort) {
            usort($normalized, static fn (array $a, array $b): int => [(string) ($a['available_at'] ?? ''), (string) ($a['id'] ?? '')]
                <=> [(string) ($b['available_at'] ?? ''), (string) ($b['id'] ?? '')]);
        }

        return $normalized;
    }
}
