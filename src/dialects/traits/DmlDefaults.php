<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use PDOStatement;
use tommyknocker\pdodb\dialects\roles\DialectDmlInterface;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;

/**
 * Default implementation of the INSERT/UPDATE/DELETE building and SELECT clause formatting part of the dialect ({@see DialectDmlInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait DmlDefaults
{
    /**
     * Enhance INSERT options if needed (e.g., add RETURNING clause for Oracle).
     * Default implementation returns options unchanged.
     *
     * @param array<int|string, mixed> $options Original options
     * @param array<int, string> $columns Column names
     * @param string $table Table name
     *
     * @return array<int|string, mixed> Enhanced options
     */
    public function enhanceInsertOptions(array $options, array $columns, string $table): array
    {
        return $options;
    }

    /**
     * Extract inserted ID from statement result.
     * Default implementation returns null to use lastInsertId().
     *
     * @param PDOStatement $stmt The executed statement
     * @param string $sql The SQL query that was executed
     * @param array<string, string|int|float|bool|null> $params The parameters that were used
     *
     * @return int|null The inserted ID, or null to use lastInsertId()
     */
    public function extractInsertId(PDOStatement $stmt, string $sql, array $params): ?int
    {
        return null;
    }

    /**
     * Build multi-row insert sql.
     * Default implementation uses INSERT ... VALUES (...), (...).
     *
     * @param string $table
     * @param array<int, string> $columns
     * @param array<int, string> $tuples Array of tuple strings like "(ph1, ph2)" or "(raw, ph)"
     * @param array<int|string, mixed> $options
     *
     * @return string SQL for multi-row insert
     */
    public function buildInsertMultiSql(
        string $table,
        array $columns,
        array $tuples,
        array $options
    ): string {
        $colsQuoted = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $opt = !empty($options) ? ' ' . implode(',', $options) : '';
        return 'INSERT' . $opt . ' INTO ' . $table
            . ' (' . $colsQuoted . ') VALUES ' . implode(', ', $tuples);
    }

    /**
     * {@inheritDoc}
     */
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
        // Default implementation: append LIMIT and OFFSET
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        if ($offset !== null) {
            $sql .= ' OFFSET ' . (int)$offset;
        }
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function resolveInsertSelectSourceSql(
        string $sourceSql,
        array $columns,
        ExecutionEngineInterface $executionEngine,
    ): string {
        return $sourceSql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string
    {
        // Default implementation: return as-is
        return $selectSql;
    }

    /**
     * {@inheritDoc}
     */
    public function needsUnionParentheses(): bool
    {
        // Default implementation: no parentheses needed
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionOrderBy(array $orderBy, array $selectColumns): string
    {
        // Default implementation: return column names as-is
        return implode(', ', $orderBy);
    }

    /**
     * {@inheritDoc}
     */
    public function formatLateralJoin(string $tableSql, string $type, string $aliasQuoted, string|RawValue|null $condition = null): string
    {
        // Standard LATERAL JOIN syntax for PostgreSQL, MySQL, etc.
        $typeUpper = strtoupper(trim($type));
        $isCross = ($typeUpper === 'CROSS' || $typeUpper === 'CROSS JOIN');

        if ($condition !== null) {
            $onSql = $condition instanceof RawValue ? $condition->getValue() : (string)$condition;
            if ($isCross) {
                return "CROSS JOIN {$tableSql} ON {$onSql}";
            }
            return "{$typeUpper} JOIN {$tableSql} ON {$onSql}";
        }

        // For CROSS JOIN LATERAL, no ON clause needed
        if ($isCross) {
            return "CROSS JOIN {$tableSql}";
        }

        // For LEFT/INNER JOIN LATERAL, MySQL may require explicit ON condition
        // PostgreSQL supports LATERAL without ON, but for compatibility add ON true
        return "{$typeUpper} JOIN {$tableSql} ON true";
    }

    /**
     * {@inheritDoc}
     */
    public function needsColumnQualificationInUpdateSet(): bool
    {
        // Default: most dialects need column qualification when JOIN is used
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function buildIdempotentUpsertSql(
        string $table,
        array $row,
        array $conflictColumns,
        array $updateColumns
    ): array {
        throw new UnsupportedOperationException(
            static::class . ' does not implement buildIdempotentUpsertSql(); dialects with INSERT ... ON CONFLICT '
            . 'can use the OnConflictUpsertTrait.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function supportsUpsertClause(): bool
    {
        return true;
    }
}
