<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox\claim;

/**
 * Shared helpers for claim strategies.
 */
final class OutboxClaimRows
{
    public const string COLUMNS = 'id, event_type, payload, status, attempts, available_at, created_at, locked_by, locked_until, last_error, published_at';

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
