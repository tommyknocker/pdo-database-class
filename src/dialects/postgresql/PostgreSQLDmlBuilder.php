<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\postgresql;

use tommyknocker\pdodb\dialects\builders\DmlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * PostgreSQL DML builder implementation.
 */
class PostgreSQLDmlBuilder implements DmlBuilderInterface
{
    use UpsertBuilderTrait;

    protected DialectInterface $dialect;

    public function __construct(DialectInterface $dialect)
    {
        $this->dialect = $dialect;
    }

    /**
     * Quote identifier using dialect's method.
     */
    protected function quoteIdentifier(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }

    /**
     * Quote table using dialect's method.
     */
    protected function quoteTable(string $table): string
    {
        return $this->dialect->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildInsertSql(string $table, array $columns, array $placeholders, array $options = []): string
    {
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $vals = implode(', ', $placeholders);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $cols, $vals);

        $tail = [];
        $beforeValues = []; // e.g., OVERRIDING ...
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            if (str_starts_with($u, 'RETURNING')) {
                $tail[] = $opt;
            } elseif (str_starts_with($u, 'ONLY')) {
                $result = preg_replace(
                    '/^INSERT INTO\s+' . preg_quote($table, '/') . '/i',
                    'INSERT INTO ONLY ' . $table,
                    $sql,
                    1
                );
                $sql = $result ?? $sql;
            } elseif (str_starts_with($u, 'OVERRIDING')) {
                // insert before VALUES
                $beforeValues[] = $opt;
            } elseif (str_starts_with($u, 'ON CONFLICT')) {
                // ON CONFLICT goes after VALUES and before RETURNING
                $tail[] = $opt;
            } else {
                // move unknown option to tail by default
                $tail[] = $opt;
            }
        }

        if (!empty($beforeValues)) {
            $result = preg_replace('/\)\s+VALUES\s+/i', ') ' . implode(' ', $beforeValues) . ' VALUES ', $sql, 1);
            $sql = $result ?? $sql;
        }

        if (!empty($tail)) {
            $sql .= ' ' . implode(' ', $tail);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildInsertSelectSql(
        string $table,
        array $columns,
        string $selectSql,
        array $options = []
    ): string {
        $sql = 'INSERT INTO ' . $table;
        if (!empty($columns)) {
            $sql .= ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')';
        }
        $sql .= ' ' . $selectSql;

        $tail = [];
        $beforeSelect = []; // e.g., OVERRIDING ...
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            if (str_starts_with($u, 'RETURNING')) {
                $tail[] = $opt;
            } elseif (str_starts_with($u, 'ONLY')) {
                $result = preg_replace(
                    '/^INSERT INTO\s+/i',
                    'INSERT INTO ONLY ',
                    $sql,
                    1
                );
                $sql = $result ?? $sql;
            } elseif (str_starts_with($u, 'OVERRIDING')) {
                $beforeSelect[] = $opt;
            } elseif (str_starts_with($u, 'ON CONFLICT')) {
                $tail[] = $opt;
            } else {
                $tail[] = $opt;
            }
        }

        if (!empty($beforeSelect)) {
            $result = preg_replace('/\)\s+SELECT\s+/i', ') ' . implode(' ', $beforeSelect) . ' SELECT ', $sql, 1);
            if ($result === null) {
                // If no ) SELECT pattern, insert before SELECT
                $result = preg_replace('/\s+SELECT\s+/i', ' ' . implode(' ', $beforeSelect) . ' SELECT ', $sql, 1);
            }
            $sql = $result ?? $sql;
        }

        if (!empty($tail)) {
            $sql .= ' ' . implode(' ', $tail);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildUpdateWithJoinSql(
        string $table,
        string $setClause,
        array $joins,
        string $whereClause,
        ?int $limit = null,
        string $options = ''
    ): string {
        // PostgreSQL uses FROM clause instead of JOIN in UPDATE
        // Convert JOIN clauses to FROM clause
        $fromTables = [];
        $joinConditions = [];

        foreach ($joins as $join) {
            // Parse JOIN clause: "INNER JOIN table ON condition"
            if (preg_match('/\s+(?:INNER\s+)?JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                $fromTables[] = $matches[1];
                $joinConditions[] = $matches[2];
            } elseif (preg_match('/\s+LEFT\s+JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                // LEFT JOIN - add to FROM with condition in WHERE
                $fromTables[] = $matches[1];
                $joinConditions[] = $matches[2];
            } elseif (preg_match('/\s+RIGHT\s+JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                // RIGHT JOIN - PostgreSQL doesn't support RIGHT JOIN in UPDATE, convert to LEFT JOIN
                $fromTables[] = $matches[1];
                $joinConditions[] = $matches[2];
            }
        }

        $sql = "UPDATE {$options}{$table} SET {$setClause}";
        if (!empty($fromTables)) {
            $sql .= ' FROM ' . implode(', ', $fromTables);
        }

        // Combine JOIN conditions with WHERE clause
        $allConditions = array_merge($joinConditions, $whereClause ? [$whereClause] : []);
        if (!empty($allConditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $allConditions);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDeleteWithJoinSql(
        string $table,
        array $joins,
        string $whereClause,
        string $options = ''
    ): string {
        // PostgreSQL uses USING clause for DELETE with joins
        $usingTables = [];
        $joinConditions = [];

        foreach ($joins as $join) {
            if (preg_match('/\s+(?:INNER\s+)?JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                $usingTables[] = $matches[1];
                $joinConditions[] = $matches[2];
            } elseif (preg_match('/\s+LEFT\s+JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                $usingTables[] = $matches[1];
                $joinConditions[] = $matches[2];
            }
        }

        $sql = "DELETE {$options}FROM {$table}";
        if (!empty($usingTables)) {
            $sql .= ' USING ' . implode(', ', $usingTables);
        }

        // Combine JOIN conditions with WHERE clause
        $allConditions = array_merge($joinConditions, $whereClause ? [$whereClause] : []);
        if (!empty($allConditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $allConditions);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildReplaceSql(
        string $table,
        array $columns,
        array $placeholders,
        bool $isMultiple = false
    ): string {
        $tableSql = $this->quoteTable($table);
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));

        if ($isMultiple) {
            // $placeholders is expected to be an array of strings where each string
            // is a list of placeholders without outer parentheses or already wrapped.
            // Normalize to: VALUES (row1),(row2),...
            $rows = [];
            foreach ($placeholders as $ph) {
                // if the element already has outer parentheses — keep it, otherwise wrap it
                $phStr = is_array($ph) ? implode(',', $ph) : $ph;
                $phTrim = trim($phStr);
                if (str_starts_with($phTrim, '(') && str_ends_with($phTrim, ')')) {
                    $rows[] = $phTrim;
                } else {
                    $rows[] = '(' . $phTrim . ')';
                }
            }
            $valsSql = implode(',', $rows);
        } else {
            // single insert: ensure parentheses around the list
            $stringPlaceholders = array_map(static fn ($p) => is_array($p) ? implode(',', $p) : $p, $placeholders);
            $phList = implode(',', $stringPlaceholders);
            $valsSql = '(' . $phList . ')';
        }

        if (in_array('id', $columns, true)) {
            $updates = [];
            foreach ($columns as $col) {
                if ($col === 'id') {
                    continue;
                }
                $quoted = $this->quoteIdentifier($col);
                $updates[] = sprintf('%s = EXCLUDED.%s', $quoted, $quoted);
            }
            $updateSql = empty($updates) ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $updates);
            return sprintf(
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) %s',
                $tableSql,
                $colsSql,
                $valsSql,
                $this->quoteIdentifier('id'),
                $updateSql
            );
        }

        return sprintf('INSERT INTO %s (%s) VALUES %s ON CONFLICT DO NOTHING', $tableSql, $colsSql, $valsSql);
    }

    /**
     * {@inheritDoc}
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        if (!$updateColumns) {
            return '';
        }

        $isAssoc = $this->isAssociativeArray($updateColumns);

        if ($isAssoc) {
            $parts = $this->buildUpsertExpressions($updateColumns, $tableName);
        } else {
            $parts = [];
            foreach ($updateColumns as $c) {
                $parts[] = "{$this->quoteIdentifier($c)} = EXCLUDED.{$this->quoteIdentifier($c)}";
            }
        }

        return "ON CONFLICT ({$this->quoteIdentifier($defaultConflictTarget)}) DO UPDATE SET " . implode(', ', $parts);
    }

    /**
     * {@inheritDoc}
     */
    public function buildMergeSql(
        string $targetTable,
        string $sourceSql,
        string $onClause,
        array $whenClauses
    ): string {
        $target = $this->quoteTable($targetTable);

        // Ensure source has alias for PostgreSQL MERGE
        if (!preg_match('/\s+AS\s+source$/i', $sourceSql) && !preg_match('/\s+source$/i', $sourceSql)) {
            $sourceSql .= ' AS source';
        }

        $sql = "MERGE INTO {$target} AS target\n";
        $sql .= "USING {$sourceSql}\n";
        $sql .= "ON {$onClause}\n";

        // WHEN MATCHED
        if (!empty($whenClauses['whenMatched']) && is_string($whenClauses['whenMatched'])) {
            $condition = $whenClauses['whenMatched'];
            if (str_contains($condition, ' AND ')) {
                [$update, $whenCondition] = explode(' AND ', $condition, 2);
                $sql .= "WHEN MATCHED AND {$whenCondition} THEN\n";
                $sql .= "  UPDATE SET {$update}\n";
            } else {
                $sql .= "WHEN MATCHED THEN\n";
                $sql .= "  UPDATE SET {$condition}\n";
            }
        }

        // WHEN NOT MATCHED
        if (!empty($whenClauses['whenNotMatched']) && is_string($whenClauses['whenNotMatched'])) {
            $condition = $whenClauses['whenNotMatched'];
            // Replace MERGE_SOURCE_COLUMN_ markers with source.column for PostgreSQL
            $condition = preg_replace('/MERGE_SOURCE_COLUMN_(\w+)/', 'source.$1', $condition);
            if ($condition !== null && str_contains($condition, ' AND ')) {
                [$insert, $whenCondition] = explode(' AND ', $condition, 2);
                $sql .= "WHEN NOT MATCHED AND {$whenCondition} THEN\n";
                $sql .= "  INSERT {$insert}\n";
            } elseif ($condition !== null) {
                $sql .= "WHEN NOT MATCHED THEN\n";
                $sql .= "  INSERT {$condition}\n";
            }
        }

        // WHEN NOT MATCHED BY SOURCE (PostgreSQL 15+ syntax)
        if ($whenClauses['whenNotMatchedBySourceDelete'] ?? false) {
            $sql .= "WHEN NOT MATCHED BY SOURCE THEN\n";
            $sql .= "  DELETE\n";
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function needsColumnQualificationInUpdateSet(): bool
    {
        // PostgreSQL uses FROM clause in UPDATE, so column names don't need table prefix
        return false;
    }

    /**
     * Build increment/decrement expression.
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        if (!isset($expr['__op']) || !isset($expr['val'])) {
            return "{$colSql} = EXCLUDED.{$colSql}";
        }

        $op = $expr['__op'];
        // For inc/dec we reference the old table value
        $tableRef = $tableName ? $this->quoteTable($tableName) . '.' : '';
        return match ($op) {
            'inc' => "{$colSql} = {$tableRef}{$colSql} + " . (int)$expr['val'],
            'dec' => "{$colSql} = {$tableRef}{$colSql} - " . (int)$expr['val'],
            default => "{$colSql} = EXCLUDED.{$colSql}",
        };
    }

    /**
     * Build raw value expression.
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        // For RawValue with column references, replace with table.column
        $exprStr = $expr->getValue();
        if ($tableName) {
            $quotedCol = $this->quoteIdentifier($col);
            $tableRef = $this->quoteTable($tableName);
            $replacement = $tableRef . '.' . $quotedCol;

            $safeExpr = $this->replaceColumnReferences($exprStr, $col, $replacement);
            return "{$colSql} = {$safeExpr}";
        }
        return "{$colSql} = {$exprStr}";
    }

    /**
     * Build default expression.
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        $exprStr = trim((string)$expr);

        if (preg_match('/^(?:EXCLUDED\.)?[A-Za-z_][A-Za-z0-9_]*$/i', $exprStr)) {
            if (stripos($exprStr, 'EXCLUDED.') === 0) {
                return "{$colSql} = {$exprStr}";
            }
            return "{$colSql} = EXCLUDED.{$this->quoteIdentifier($exprStr)}";
        }

        $quotedCol = $this->quoteIdentifier($col);
        $replacement = 'excluded.' . $quotedCol;

        $safeExpr = $this->replaceColumnReferences($exprStr, $col, $replacement);
        return "{$colSql} = {$safeExpr}";
    }
}

