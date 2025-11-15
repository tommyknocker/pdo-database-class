<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\sqlite;

use tommyknocker\pdodb\dialects\builders\DmlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * SQLite DML builder implementation.
 */
class SQLiteDmlBuilder implements DmlBuilderInterface
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
        $cols = implode(',', array_map([$this, 'quoteIdentifier'], $columns));
        $phs = implode(',', $placeholders);
        return $this->insertKeywords($options) . "INTO {$table} ({$cols}) VALUES ({$phs})";
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
        $colsSql = '';
        if (!empty($columns)) {
            $colsSql = ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')';
        }
        return $this->insertKeywords($options) . "INTO {$table}{$colsSql} {$selectSql}";
    }

    /**
     * Build INSERT keywords for SQLite (INSERT OR IGNORE, etc.).
     *
     * @param array<int|string, mixed> $flags
     */
    public function insertKeywords(array $flags): string
    {
        // SQLite does not support IGNORE directly, but supports INSERT OR IGNORE
        if (in_array('IGNORE', $flags, true)) {
            return 'INSERT OR IGNORE ';
        }
        return 'INSERT ';
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
        // SQLite doesn't support JOIN in UPDATE, convert to subquery
        $sql = "UPDATE {$options}{$table} SET {$setClause}";
        if (!empty($joins)) {
            // SQLite doesn't support JOIN in UPDATE, so we need to use a different approach
            // For now, just add WHERE clause
        }
        $sql .= $whereClause;
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
        // SQLite doesn't support JOIN in DELETE, convert to subquery
        $sql = "DELETE {$options}FROM {$table}";
        $sql .= $whereClause;
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
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));

        if ($isMultiple) {
            $valsSql = implode(',', array_map(function ($p) {
                return is_array($p) ? '(' . implode(',', $p) . ')' : $p;
            }, $placeholders));
            return sprintf('REPLACE INTO %s (%s) VALUES %s', $table, $colsSql, $valsSql);
        }

        $stringPlaceholders = array_map(fn ($p) => is_array($p) ? implode(',', $p) : $p, $placeholders);
        $valsSql = implode(',', $stringPlaceholders);
        return sprintf('REPLACE INTO %s (%s) VALUES (%s)', $table, $colsSql, $valsSql);
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
                $parts[] = "{$this->quoteIdentifier($c)} = excluded.{$this->quoteIdentifier($c)}";
            }
        }

        $target = $this->quoteIdentifier($defaultConflictTarget);
        return "ON CONFLICT ({$target}) DO UPDATE SET " . implode(', ', $parts);
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
        // SQLite emulation similar to MySQL
        // Extract key from ON clause
        preg_match('/target\.(\w+)\s*=\s*source\.(\w+)/i', $onClause, $matches);
        $keyColumn = $matches[1] ?? 'id';

        $target = $this->quoteTable($targetTable);

        if (empty($whenClauses['whenNotMatched'])) {
            throw new QueryException('SQLite MERGE requires WHEN NOT MATCHED clause');
        }

        // Parse INSERT clause
        preg_match('/\(([^)]+)\)\s+VALUES\s+\(([^)]+)\)/', $whenClauses['whenNotMatched'], $insertMatches);
        $columns = $insertMatches[1] ?? '';
        $values = $insertMatches[2] ?? '';

        // Build SELECT columns from VALUES - replace MERGE_SOURCE_COLUMN_ markers with source columns
        $selectColumns = [];
        $valueColumns = explode(',', $values);
        $colNames = explode(',', $columns);
        foreach ($valueColumns as $idx => $val) {
            $val = trim($val);
            $colName = trim($colNames[$idx] ?? '');
            // Remove quotes from column name if present
            $colName = trim($colName, '`"');
            // If it's a MERGE_SOURCE_COLUMN_ marker, use source column; otherwise it's raw SQL
            if (preg_match('/^MERGE_SOURCE_COLUMN_(\w+)$/', $val, $paramMatch)) {
                // Use unquoted column name in source reference for SQLite
                $selectColumns[] = 'source.' . $this->quoteIdentifier($colName);
            } else {
                $selectColumns[] = $val;
            }
        }

        // SQLite doesn't support ON CONFLICT with INSERT ... SELECT
        // Use INSERT OR REPLACE which replaces based on PRIMARY KEY or UNIQUE constraints
        // Remove "AS source" if present (we add it ourselves)
        $sourceSql = preg_replace('/\s+AS\s+source$/i', '', $sourceSql);
        $sql = "INSERT OR REPLACE INTO {$target} ({$columns})\n";
        $sql .= 'SELECT ' . implode(', ', $selectColumns) . " FROM {$sourceSql} AS source";

        // Note: INSERT OR REPLACE will replace existing rows based on PRIMARY KEY
        // The whenMatched clause behavior is handled by OR REPLACE

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function needsColumnQualificationInUpdateSet(): bool
    {
        // SQLite doesn't support JOIN in UPDATE, so column names don't need table prefix
        return false;
    }

    /**
     * Build increment/decrement expression.
     *
     * @param array<string, mixed> $expr
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        if (!isset($expr['__op']) || !isset($expr['val'])) {
            return "{$colSql} = excluded.{$colSql}";
        }

        $op = $expr['__op'];
        // SQLite uses column (unqualified) for old values
        return match ($op) {
            'inc' => "{$colSql} = {$colSql} + " . (int)$expr['val'],
            'dec' => "{$colSql} = {$colSql} - " . (int)$expr['val'],
            default => "{$colSql} = excluded.{$colSql}",
        };
    }

    /**
     * Build raw value expression.
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        // SQLite doesn't support DEFAULT keyword in UPDATE statements
        // Replace DEFAULT with NULL (closest equivalent behavior)
        $value = $expr->getValue();
        if (trim($value) === 'DEFAULT') {
            return "{$colSql} = NULL";
        }
        return "{$colSql} = {$value}";
    }

    /**
     * Build default expression.
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        $exprStr = trim((string)$expr);

        // Simple name or EXCLUDED.name
        if (preg_match('/^(?:excluded\.)?[A-Za-z_][A-Za-z0-9_]*$/i', $exprStr)) {
            if (stripos($exprStr, 'excluded.') === 0) {
                return "{$colSql} = {$exprStr}";
            }
            return "{$colSql} = excluded.{$this->quoteIdentifier($exprStr)}";
        }

        // Auto-qualify for typical expressions: replace only "bare" occurrences of column name with excluded."col"
        $quotedCol = $this->quoteIdentifier($col);
        $replacement = 'excluded.' . $quotedCol;

        $safeExpr = $this->replaceColumnReferences($exprStr, $col, $replacement);
        return "{$colSql} = {$safeExpr}";
    }
}
