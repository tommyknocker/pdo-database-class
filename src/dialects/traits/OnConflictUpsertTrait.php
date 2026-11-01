<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

/**
 * INSERT ... ON CONFLICT (...) DO UPDATE / DO NOTHING, as written by PostgreSQL and SQLite.
 */
trait OnConflictUpsertTrait
{
    /**
     * {@inheritDoc}
     */
    public function buildIdempotentUpsertSql(
        string $table,
        array $row,
        array $conflictColumns,
        array $updateColumns
    ): array {
        [$columns, $params] = $this->prepareUpsert($row, $conflictColumns, $updateColumns);
        $quotedColumns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $placeholders = array_map(fn (string $column): string => $this->upsertValueSql($column, $row), $columns);
        $conflict = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $conflictColumns));
        $sql = 'INSERT INTO ' . $this->quoteTable($table)
            . ' (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ') '
            . 'ON CONFLICT (' . $conflict . ') ';
        if ($updateColumns === []) {
            return [$sql . 'DO NOTHING', $params];
        }

        $assignments = [];
        foreach ($updateColumns as $column) {
            $quoted = $this->quoteIdentifier($column);
            $assignments[] = $quoted . ' = EXCLUDED.' . $quoted;
        }

        return [$sql . 'DO UPDATE SET ' . implode(', ', $assignments), $params];
    }
}
