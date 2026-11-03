<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

/**
 * Remembers, for a short time, the single-column primary key of a table whose id the connection can report.
 *
 * A table whose key is auto-increment (or that has no single-column key) is answered from the connection for a plain
 * insert, so asking the catalog again on every insert would only add a round trip. The answer can go stale when the
 * table is altered, so it is kept for a limited time. A key the connection cannot report (UUID, text) is never cached:
 * its name is looked up each time, which is what keeps an altered table from breaking an insert.
 */
trait InsertKeyCacheTrait
{
    private const int INSERT_KEY_CACHE_SECONDS = 30;

    /**
     * @var array<string, array{until: float, key: ?string}> Table => its single-column key (null: none) and when to ask again
     */
    private array $connectionKeys = [];

    /**
     * @return array{key: ?string}|null The remembered key, or null when the table has to be looked up
     */
    private function recallConnectionKey(string $table): ?array
    {
        $known = $this->connectionKeys[$table] ?? null;

        return $known !== null && $known['until'] > microtime(true) ? ['key' => $known['key']] : null;
    }

    private function rememberConnectionKey(string $table, ?string $key): void
    {
        $this->connectionKeys[$table] = ['until' => microtime(true) + self::INSERT_KEY_CACHE_SECONDS, 'key' => $key];
    }
}
