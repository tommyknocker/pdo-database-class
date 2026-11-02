<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysqlfamily;

use RuntimeException;
use tommyknocker\pdodb\dialects\builders\DmlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * DML generation shared by MySQL and MariaDB.
 */
abstract class MySQLFamilyDmlBuilder implements DmlBuilderInterface
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
        $prefix = 'INSERT' . ($options ? ' ' . implode(' ', $options) : '') . ' INTO';
        return sprintf('%s %s (%s) VALUES (%s)', $prefix, $table, $cols, $vals);
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
        $prefix = 'INSERT' . ($options ? ' ' . implode(' ', $options) : '') . ' INTO';
        $colsSql = '';
        if (!empty($columns)) {
            $colsSql = ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')';
        }
        return sprintf('%s %s%s %s', $prefix, $table, $colsSql, $selectSql);
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
        $sql = "UPDATE {$options}{$table}";
        if (!empty($joins)) {
            $sql .= ' ' . implode(' ', $joins);
        }
        $sql .= " SET {$setClause}";
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
        // MySQL/MariaDB syntax: DELETE table FROM table JOIN ...
        $sql = "DELETE {$options}{$table} FROM {$table}";
        if (!empty($joins)) {
            $sql .= ' ' . implode(' ', $joins);
        }
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
        $tableSql = $this->quoteTable($table);
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));

        if ($isMultiple) {
            // placeholders already contain grouped row expressions like "(...),(...)" or ["(...)", "(...)"]
            $valsSql = implode(',', array_map(function ($p) {
                return is_array($p) ? '(' . implode(',', $p) . ')' : $p;
            }, $placeholders));
            return sprintf('REPLACE INTO %s (%s) VALUES %s', $tableSql, $colsSql, $valsSql);
        }

        // Single row: placeholders are scalar fragments matching columns
        $stringPlaceholders = array_map(fn ($p) => is_array($p) ? implode(',', $p) : $p, $placeholders);
        $valsSql = implode(',', $stringPlaceholders);
        return sprintf('REPLACE INTO %s (%s) VALUES (%s)', $tableSql, $colsSql, $valsSql);
    }

    /**
     * {@inheritDoc}
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        return $this->upsertClause($updateColumns, $tableName, null);
    }

    /**
     * The clause for `INSERT ... VALUES`: on a server with row aliases the new row is read through one, otherwise
     * (and for `INSERT ... SELECT`, which has no alias) through `VALUES(column)`.
     *
     * @param array<int|string, mixed> $updateColumns
     * @param list<string> $insertColumns
     */
    public function buildUpsertClauseForValues(array $updateColumns, array $insertColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        $alias = $this->usesRowAlias()
            ? new MySQLRowAlias(array_map([$this, 'quoteIdentifier'], $insertColumns))
            : null;

        return $this->upsertClause($updateColumns, $tableName, $alias);
    }

    /**
     * Whether the connected server takes the new row as an alias instead of the deprecated `VALUES(c)`: MySQL
     * 8.0.19 added the alias, MariaDB has none.
     */
    protected function usesRowAlias(): bool
    {
        return false;
    }

    private ?MySQLRowAlias $rowAlias = null;

    /**
     * The new row's value of a column in an ON DUPLICATE KEY UPDATE expression.
     */
    protected function newRowValue(string $colSql): string
    {
        return $this->rowAlias !== null ? $this->rowAlias->value($colSql) : "VALUES({$colSql})";
    }

    /**
     * @param array<int|string, mixed> $updateColumns
     */
    private function upsertClause(array $updateColumns, string $tableName, ?MySQLRowAlias $alias): string
    {
        if (!$updateColumns) {
            return '';
        }

        $this->rowAlias = $alias;

        try {
            return ($alias !== null ? $alias->declaration() . ' ' : '') . $this->onDuplicateKeyUpdate($updateColumns, $tableName);
        } finally {
            $this->rowAlias = null;
        }
    }

    /**
     * @param array<int|string, mixed> $updateColumns
     */
    private function onDuplicateKeyUpdate(array $updateColumns, string $tableName): string
    {
        $isAssoc = $this->isAssociativeArray($updateColumns);

        if ($isAssoc) {
            $updates = $this->buildUpsertExpressions($updateColumns, $tableName);
        } else {
            $updates = [];
            foreach ($updateColumns as $col) {
                $qid = $this->quoteIdentifier($col);
                $updates[] = "{$qid} = " . $this->newRowValue($qid);
            }
        }

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
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
        // MySQL/MariaDB emulation using INSERT ... SELECT ... ON DUPLICATE KEY UPDATE
        // Extract key from ON clause for conflict target
        preg_match('/target\.(\w+)\s*=\s*source\.(\w+)/i', $onClause, $matches);
        $keyColumn = $matches[1] ?? 'id';

        $target = $this->quoteTable($targetTable);

        if (empty($whenClauses['whenNotMatched'])) {
            throw new RuntimeException($this->engineLabel() . ' MERGE requires WHEN NOT MATCHED clause');
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
                $selectColumns[] = 'source.' . $this->quoteIdentifier($colName);
            } else {
                $selectColumns[] = $val;
            }
        }

        $sql = "INSERT INTO {$target} ({$columns})\n";
        $sql .= 'SELECT ' . implode(', ', $selectColumns) . " FROM {$sourceSql} AS source\n";

        // Add ON DUPLICATE KEY UPDATE if whenMatched exists
        if (!empty($whenClauses['whenMatched'])) {
            // Replace source.column references with VALUES(column) for MySQL/MariaDB
            $updateExpr = preg_replace('/source\.([a-zA-Z_][a-zA-Z0-9_]*)/', 'VALUES($1)', $whenClauses['whenMatched']);
            $sql .= "ON DUPLICATE KEY UPDATE {$updateExpr}\n";
        }

        return $sql;
    }

    /**
     * Build increment/decrement expression.
     *
     * @param array<string, mixed> $expr
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        if (!isset($expr['__op']) || !isset($expr['val'])) {
            return "{$colSql} = " . $this->newRowValue($colSql);
        }

        $op = $expr['__op'];
        return match ($op) {
            'inc' => "{$colSql} = {$colSql} + " . (int)$expr['val'],
            'dec' => "{$colSql} = {$colSql} - " . (int)$expr['val'],
            default => "{$colSql} = " . $this->newRowValue($colSql),
        };
    }

    /**
     * Build raw value expression.
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        return "{$colSql} = {$expr->getValue()}";
    }

    /**
     * Build default expression.
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        if ($expr === true) {
            return "{$colSql} = " . $this->newRowValue($colSql);
        }
        if (is_string($expr)) {
            return "{$colSql} = {$expr}";
        }
        return "{$colSql} = " . $this->newRowValue($colSql);
    }

    /**
     * Engine name used in error messages.
     */
    abstract protected function engineLabel(): string;
}
