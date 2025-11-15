<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use RuntimeException;
use tommyknocker\pdodb\dialects\builders\DmlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * MSSQL DML builder implementation.
 */
class MSSQLDmlBuilder implements DmlBuilderInterface
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

        // MSSQL requires CTE to be before INSERT statement
        // If selectSql starts with WITH, we need to split CTE and SELECT parts
        // Pattern: WITH ... SELECT ... -> WITH ... INSERT INTO ... SELECT ...
        if (preg_match('/^(\s*WITH\s+.*?)(\s+SELECT\s+.*)$/is', $selectSql, $matches)) {
            $ctePart = $matches[1];
            $selectPart = $matches[2];
            return sprintf('%s %s%s%s', $ctePart, $prefix, $table, $colsSql) . $selectPart;
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
        // MSSQL UPDATE with JOIN syntax: UPDATE t SET ... FROM t JOIN ...
        // MSSQL doesn't support LIMIT in UPDATE, use TOP instead
        $topClause = $limit !== null ? "TOP ({$limit}) " : '';
        $sql = "UPDATE {$topClause}{$options}{$table} SET {$setClause}";
        if (!empty($joins)) {
            $sql .= ' FROM ' . $table . ' ' . implode(' ', $joins);
        }
        $sql .= $whereClause;
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
        // MSSQL DELETE with JOIN syntax: DELETE t FROM t JOIN ...
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
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        if (!$updateColumns) {
            return '';
        }

        // MSSQL doesn't support ON CONFLICT or ON DUPLICATE KEY UPDATE
        // Use MERGE statement instead, but for compatibility with INSERT ... ON DUPLICATE pattern,
        // we'll throw an exception suggesting to use MERGE directly
        // Alternatively, we could emulate using a subquery, but that's complex
        throw new RuntimeException(
            'MSSQL does not support UPSERT via INSERT ... ON DUPLICATE KEY UPDATE. ' .
            'Please use QueryBuilder::merge() method instead for UPSERT operations.'
        );
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
        $sql = "MERGE {$target} AS target\n";
        // For string source (table name), wrap in SELECT * FROM for MSSQL
        if (!str_starts_with(trim($sourceSql), '(') && !str_starts_with(trim($sourceSql), 'SELECT')) {
            $sourceSql = "SELECT * FROM {$sourceSql}";
        }
        $sql .= "USING ({$sourceSql}) AS source\n";
        $sql .= "ON {$onClause}\n";

        if (!empty($whenClauses['whenMatched'])) {
            $sql .= "WHEN MATCHED THEN\n";
            $sql .= "  UPDATE SET {$whenClauses['whenMatched']}\n";
        }

        if (!empty($whenClauses['whenNotMatched'])) {
            $insertClause = $whenClauses['whenNotMatched'];
            // MSSQL MERGE INSERT requires: INSERT (columns) VALUES (source.columns)
            // Replace MERGE_SOURCE_COLUMN_ markers with source.column for MSSQL
            $insertClause = preg_replace('/MERGE_SOURCE_COLUMN_(\w+)/', 'source.$1', $insertClause);

            // MSSQL MERGE INSERT format: (columns) VALUES (values)
            // Extract columns and values from the clause
            if ($insertClause !== null && preg_match('/^\(([^)]+)\)\s+VALUES\s+\(([^)]+)\)/', $insertClause, $matches)) {
                $columns = $matches[1];
                $values = $matches[2];
                $sql .= "WHEN NOT MATCHED THEN\n";
                $sql .= "  INSERT ({$columns}) VALUES ({$values})\n";
            } else {
                // Fallback: use as-is
                $sql .= "WHEN NOT MATCHED THEN\n";
                $sql .= "  INSERT {$insertClause}\n";
            }
        }

        if (!empty($whenClauses['whenNotMatchedBySourceDelete'])) {
            $sql .= "WHEN NOT MATCHED BY SOURCE THEN DELETE\n";
        }

        // MSSQL requires semicolon after MERGE statement
        $sql .= ';';

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildReplaceSql(string $table, array $columns, array $placeholders, bool $isMultiple = false): string
    {
        // MSSQL doesn't have REPLACE, use MERGE or DELETE + INSERT
        // For simplicity, use DELETE + INSERT pattern
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        /** @var array<string> $placeholders */
        $vals = implode(', ', $placeholders);
        return "DELETE FROM {$table}; INSERT INTO {$table} ({$cols}) VALUES ({$vals})";
    }

    /**
     * Build increment expression for MSSQL OUTPUT clause.
     *
     * @param string $colSql Column SQL
     * @param array<string, mixed> $expr Expression array
     * @param string $tableName Table name
     *
     * @return string SQL expression
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        if (!isset($expr['__op']) || !isset($expr['val'])) {
            return "{$colSql} = INSERTED.{$colSql}";
        }
        $op = $expr['__op'];
        $val = $expr['val'];
        if ($op === 'inc') {
            return "{$colSql} = INSERTED.{$colSql} + {$val}";
        }
        if ($op === 'dec') {
            return "{$colSql} = INSERTED.{$colSql} - {$val}";
        }
        return "{$colSql} = INSERTED.{$colSql}";
    }

    /**
     * Build raw value expression for MSSQL OUTPUT clause.
     *
     * @param string $colSql Column SQL
     * @param RawValue $expr Raw value expression
     * @param string $tableName Table name
     * @param string $col Column name
     *
     * @return string SQL expression
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        $value = $expr->getValue();
        // Replace INSERTED.column references if needed
        $value = str_replace("VALUES({$col})", "INSERTED.{$col}", $value);
        return "{$colSql} = {$value}";
    }

    /**
     * Build default expression for MSSQL OUTPUT clause.
     *
     * @param string $colSql Column SQL
     * @param mixed $expr Expression
     * @param string $col Column name
     *
     * @return string SQL expression
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        if ($expr instanceof RawValue) {
            return $this->buildRawValueExpression($colSql, $expr, '', $col);
        }
        if (is_string($expr)) {
            return "{$colSql} = '{$expr}'";
        }
        return "{$colSql} = {$expr}";
    }
}
