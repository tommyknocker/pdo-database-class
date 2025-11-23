<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\oracle;

use RuntimeException;
use tommyknocker\pdodb\dialects\builders\DmlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Oracle DML builder implementation.
 */
class OracleDmlBuilder implements DmlBuilderInterface
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

        // Handle RETURNING clause
        $tail = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            if (str_starts_with($u, 'RETURNING')) {
                $tail[] = $opt;
            }
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

        // Handle RETURNING clause
        $tail = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            if (str_starts_with($u, 'RETURNING')) {
                $tail[] = $opt;
            }
        }

        if (!empty($tail)) {
            $sql .= ' ' . implode(' ', $tail);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, string> $columns
     * @param array<int, string> $tuples
     * @param array<int|string, mixed> $options
     */
    public function buildInsertMultiSql(
        string $table,
        array $columns,
        array $tuples,
        array $options
    ): string {
        // Oracle doesn't support INSERT ... VALUES (...), (...)
        // Use INSERT ALL instead
        $colsQuoted = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $sql = 'INSERT ALL';

        foreach ($tuples as $tuple) {
            // Remove outer parentheses from tuple if present
            $tuple = trim($tuple, '()');
            $sql .= ' INTO ' . $table . ' (' . $colsQuoted . ') VALUES (' . $tuple . ')';
        }

        $sql .= ' SELECT 1 FROM DUAL';

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
        // Oracle supports UPDATE with JOINs using subqueries or MERGE
        // For simplicity, we'll use a subquery approach
        $sql = "UPDATE {$options}{$table} SET {$setClause}";

        // Add WHERE clause
        if (trim($whereClause) !== '' && trim($whereClause) !== 'WHERE') {
            $sql .= ' ' . $whereClause;
        }

        // Add JOIN conditions to WHERE if needed
        if (!empty($joins)) {
            $joinConditions = [];
            foreach ($joins as $join) {
                if (preg_match('/\s+ON\s+(.+)/i', $join, $matches)) {
                    $joinConditions[] = $matches[1];
                }
            }
            if (!empty($joinConditions)) {
                if (trim($whereClause) === '' || trim($whereClause) === 'WHERE') {
                    $sql .= ' WHERE ';
                } else {
                    $sql .= ' AND ';
                }
                $sql .= implode(' AND ', $joinConditions);
            }
        }

        // Oracle doesn't support LIMIT in UPDATE directly, use ROWNUM in subquery
        if ($limit !== null) {
            // Wrap in subquery with ROWNUM
            $sql = "UPDATE ({$sql} AND ROWNUM <= " . (int)$limit . ')';
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
        // Oracle supports DELETE with JOINs using subqueries
        $sql = "DELETE {$options}FROM {$table}";

        // Add WHERE clause
        if (trim($whereClause) !== '' && trim($whereClause) !== 'WHERE') {
            $sql .= ' ' . $whereClause;
        }

        // Add JOIN conditions to WHERE if needed
        if (!empty($joins)) {
            $joinConditions = [];
            foreach ($joins as $join) {
                if (preg_match('/\s+ON\s+(.+)/i', $join, $matches)) {
                    $joinConditions[] = $matches[1];
                }
            }
            if (!empty($joinConditions)) {
                if (trim($whereClause) === '' || trim($whereClause) === 'WHERE') {
                    $sql .= ' WHERE ';
                } else {
                    $sql .= ' AND ';
                }
                $sql .= implode(' AND ', $joinConditions);
            }
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
        // Oracle doesn't have REPLACE, use MERGE instead
        // This is a simplified implementation
        $tableSql = $this->quoteTable($table);
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));

        if ($isMultiple) {
            $rows = [];
            foreach ($placeholders as $ph) {
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
            $stringPlaceholders = array_map(static fn ($p) => is_array($p) ? implode(',', $p) : $p, $placeholders);
            $phList = implode(',', $stringPlaceholders);
            $valsSql = '(' . $phList . ')';
        }

        // Use MERGE for replace behavior
        $keyCol = $this->quoteIdentifier('id');
        return "MERGE INTO {$tableSql} t USING (SELECT * FROM (VALUES {$valsSql})) s ON (t.{$keyCol} = s.{$keyCol}) WHEN MATCHED THEN UPDATE SET " . implode(', ', array_map(fn ($c) => "t.{$this->quoteIdentifier($c)} = s.{$this->quoteIdentifier($c)}", $columns)) . " WHEN NOT MATCHED THEN INSERT ({$colsSql}) VALUES ({$valsSql})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        // Oracle does not support ON CONFLICT/ON DUPLICATE KEY syntax. Use buildMergeSql for UPSERT operations.
        throw new RuntimeException('Oracle does not support ON CONFLICT/ON DUPLICATE KEY syntax. Use buildMergeSql for UPSERT operations.');
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

        // buildMergeSourceSql already wraps in parentheses and adds AS source
        // So we use sourceSql as-is (it's already formatted as (SELECT ...) AS source)
        // But Oracle requires the alias to be "source" (lowercase)
        // Ensure sourceSql has proper alias
        if (!preg_match('/\s+AS\s+source$/i', $sourceSql)) {
            // If no alias, add it
            $sourceSql = '(' . trim($sourceSql, '()') . ') AS source';
        }
        
        // Ensure onClause uses proper table aliases (target and source)
        $onClause = preg_replace('/\btarget\./i', 'target.', $onClause);
        $onClause = preg_replace('/\bsource\./i', 'source.', $onClause);
        
        // Ensure sourceSql is properly formatted for Oracle
        // Oracle requires: USING (SELECT ...) source
        // But buildMergeSourceSql returns: (SELECT ...) AS source
        // Remove "AS" if present, Oracle doesn't require it in USING clause
        $sourceSql = preg_replace('/\s+AS\s+source$/i', ' source', $sourceSql);
        
        $sql = "MERGE INTO {$target} target\n";
        $sql .= "USING {$sourceSql}\n";
        $sql .= "ON ({$onClause})\n";

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
        if (!empty($whenClauses['whenNotMatched'])) {
            if (is_string($whenClauses['whenNotMatched'])) {
                // Format: (columns) VALUES (values)
                // Replace MERGE_SOURCE_COLUMN_ references with source.column
                $insertClause = preg_replace('/MERGE_SOURCE_COLUMN_(\w+)/', 'source.$1', $whenClauses['whenNotMatched']);
                $sql .= "WHEN NOT MATCHED THEN\n";
                $sql .= "  INSERT {$insertClause}\n";
            } elseif (is_array($whenClauses['whenNotMatched'])) {
                // Array format: ['column1' => 'value1', 'column2' => 'value2']
                $columns = array_keys($whenClauses['whenNotMatched']);
                $values = array_values($whenClauses['whenNotMatched']);
                $colsSql = '(' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')';
                $valsSql = '(' . implode(', ', $values) . ')';
                $sql .= "WHEN NOT MATCHED THEN\n";
                $sql .= "  INSERT {$colsSql} VALUES {$valsSql}\n";
            }
        }

        // WHEN NOT MATCHED BY SOURCE DELETE
        if (!empty($whenClauses['whenNotMatchedBySourceDelete'])) {
            $sql .= "WHEN NOT MATCHED BY SOURCE THEN\n";
            $sql .= "  DELETE\n";
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
            return "{$colSql} = excluded.{$colSql}";
        }

        $op = $expr['__op'];
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
        return "{$colSql} = {$expr->getValue()}";
    }

    /**
     * Build default expression.
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        if ($expr === true) {
            return "{$colSql} = excluded.{$colSql}";
        }
        if (is_string($expr)) {
            return "{$colSql} = {$expr}";
        }
        return "{$colSql} = excluded.{$colSql}";
    }
}
