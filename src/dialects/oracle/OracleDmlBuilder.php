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
            // Only remove parentheses if tuple starts with '(' and ends with ')'
            // Don't use trim() as it removes ALL parentheses, including those in TO_DATE() etc.
            if (str_starts_with($tuple, '(') && str_ends_with($tuple, ')')) {
                $tuple = substr($tuple, 1, -1);
            }
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
        // Oracle doesn't support UPDATE with JOIN directly, use subquery
        // Extract join table and conditions
        $joinTable = null;
        $joinConditions = [];
        foreach ($joins as $join) {
            if (preg_match('/\s+(?:INNER\s+)?JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                $joinTable = trim($matches[1]);
                $joinConditions[] = $matches[2];
            } elseif (preg_match('/\s+LEFT\s+JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                $joinTable = trim($matches[1]);
                $joinConditions[] = $matches[2];
            }
        }

        // If SET clause references columns from JOIN table, replace them with correlated subqueries
        $setClauseProcessed = $setClause;
        if (!empty($joinTable) && !empty($joinConditions)) {
            // Extract table alias/name from join conditions (e.g., "UPDATE_DELETE_JOIN_ORDERS.USER_ID")
            // Replace references to join table columns with correlated subqueries
            // Check if joinTable already has quotes (from quoteTable in join builder)
            $joinTableQuoted = (str_starts_with($joinTable, '"') && str_ends_with($joinTable, '"'))
                ? $joinTable
                : $this->quoteTable($joinTable);
            $joinTableName = trim($joinTableQuoted, '"');
            $joinCondition = implode(' AND ', $joinConditions);
            $tableQuoted = $this->quoteTable($table);

            // Find references to join table columns in SET clause (pattern: table.column or "table"."column")
            // Replace with: (SELECT column FROM join_table WHERE join_condition AND correlation)
            // Extract correlation from join condition (e.g., "UPDATE_DELETE_JOIN_ORDERS.USER_ID = UPDATE_DELETE_JOIN_USERS.ID")
            $correlation = '';
            if (preg_match('/([^\s=]+)\s*=\s*([^\s=]+)/', $joinCondition, $corrMatches)) {
                // Use the join condition as correlation, but ensure it references the main table
                $correlation = $joinCondition;
            }

            // Match both quoted and unquoted table names, with or without quotes around column
            // Pattern: "table"."column" or table.column
            $pattern = '/(?:' . preg_quote($joinTableQuoted, '/') . '|' . preg_quote($joinTableName, '/') . ')\.(?:("?)([a-zA-Z_][a-zA-Z0-9_]*)\1)/i';
            $setClauseProcessed = preg_replace_callback(
                $pattern,
                function ($matches) use ($joinTableQuoted, $joinCondition, $tableQuoted, $table) {
                    if (empty($matches[2])) {
                        // Should not happen, but safety check
                        return $matches[0];
                    }
                    $column = $this->quoteIdentifier($matches[2]);
                    // Extract correlation from join condition
                    // Join condition format: "join_table"."column" = "main_table"."column"
                    // For correlated subquery, we need: "join_table"."column" = main_table."column"
                    // (main_table without quotes because it's the outer table alias)
                    $correlation = '';
                    if (preg_match('/"([^"]+)"\s*\.\s*"([^"]+)"\s*=\s*"([^"]+)"\s*\.\s*"([^"]+)"/', $joinCondition, $corrMatches)) {
                        // If join condition is "join_table"."col1" = "main_table"."col2"
                        // Correlation should be "join_table"."col1" = main_table."col2"
                        // (main_table is the outer table, so we reference it without quotes in subquery)
                        $tableName = trim($tableQuoted, '"');
                        if (empty($tableName)) {
                            // Fallback: use table name directly, removing quotes if present
                            $tableName = trim($table, '"');
                        }
                        if (empty($tableName)) {
                            // Last resort: use table as-is
                            $tableName = $table;
                        }
                        if ($corrMatches[1] === trim($joinTableQuoted, '"')) {
                            // join_table is first, main_table is second
                            // Use main table name from UPDATE statement for correlation (without quotes for outer table)
                            $correlation = '"' . $corrMatches[1] . '"."' . $corrMatches[2] . '" = ' . $tableName . '."' . $corrMatches[4] . '"';
                        } elseif ($corrMatches[3] === trim($joinTableQuoted, '"')) {
                            // main_table is first, join_table is second
                            // Use main table name from UPDATE statement for correlation (without quotes for outer table)
                            $correlation = '"' . $corrMatches[3] . '"."' . $corrMatches[4] . '" = ' . $tableName . '."' . $corrMatches[2] . '"';
                        } else {
                            // Fallback: use join condition as-is, but try to replace main table with unquoted name
                            $correlation = str_replace($tableQuoted, $tableName, $joinCondition);
                        }
                    } else {
                        $correlation = $joinCondition;
                    }
                    // Build correlated subquery
                    return "(SELECT {$column} FROM {$joinTableQuoted} WHERE {$correlation} AND ROWNUM = 1)";
                },
                $setClauseProcessed
            );
        }

        $sql = "UPDATE {$options}{$table} SET {$setClauseProcessed}";

        // Clean WHERE clause (remove WHERE keyword if present)
        $whereClauseClean = trim($whereClause);
        if (str_starts_with(strtoupper($whereClauseClean), 'WHERE ')) {
            $whereClauseClean = substr($whereClauseClean, 6);
        }

        // Build WHERE clause with subquery if JOIN is present
        $whereParts = [];
        if (!empty($joinTable) && !empty($joinConditions)) {
            // Combine JOIN conditions and WHERE clause in EXISTS subquery
            $subqueryConditions = array_merge($joinConditions, $whereClauseClean ? [$whereClauseClean] : []);
            $subqueryWhere = implode(' AND ', $subqueryConditions);
            $whereParts[] = "EXISTS (SELECT 1 FROM {$joinTable} WHERE {$subqueryWhere})";
        } elseif ($whereClauseClean !== '') {
            // No JOIN, use WHERE clause as is
            $whereParts[] = $whereClauseClean;
        }

        if (!empty($whereParts)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
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
        // Oracle doesn't support DELETE with JOIN directly, use subquery
        $sql = "DELETE {$options}FROM {$table}";

        // Extract join table and conditions
        $joinTable = null;
        $joinConditions = [];
        foreach ($joins as $join) {
            if (preg_match('/\s+(?:INNER\s+)?JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                $joinTable = trim($matches[1]);
                $joinConditions[] = $matches[2];
            } elseif (preg_match('/\s+LEFT\s+JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                $joinTable = trim($matches[1]);
                $joinConditions[] = $matches[2];
            }
        }

        // Clean WHERE clause (remove WHERE keyword if present)
        $whereClauseClean = trim($whereClause);
        if (str_starts_with(strtoupper($whereClauseClean), 'WHERE ')) {
            $whereClauseClean = substr($whereClauseClean, 6);
        }

        // Build WHERE clause with subquery if JOIN is present
        $whereParts = [];
        if (!empty($joinTable) && !empty($joinConditions)) {
            // Combine JOIN conditions and WHERE clause in EXISTS subquery
            $subqueryConditions = array_merge($joinConditions, $whereClauseClean ? [$whereClauseClean] : []);
            $subqueryWhere = implode(' AND ', $subqueryConditions);
            $whereParts[] = "EXISTS (SELECT 1 FROM {$joinTable} WHERE {$subqueryWhere})";
        } elseif ($whereClauseClean !== '') {
            // No JOIN, use WHERE clause as is
            $whereParts[] = $whereClauseClean;
        }

        if (!empty($whereParts)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
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

        // buildMergeSourceSql returns table name for string sources, but Oracle MERGE needs SELECT
        // Check if sourceSql is just a table name (not a SELECT query)
        if (!preg_match('/^\s*\(?\s*SELECT\s+/i', $sourceSql)) {
            // It's a table name, convert to SELECT * FROM table
            $sourceSql = 'SELECT * FROM ' . $sourceSql;
        }

        // buildMergeSourceSql already wraps in parentheses and adds AS source for subqueries
        // But for string table names, we need to wrap it ourselves
        if (!preg_match('/\s+AS\s+source$/i', $sourceSql)) {
            // If no alias, add it
            $sourceSql = '(' . trim($sourceSql, '()') . ') source';
        } else {
            // Remove "AS" if present, Oracle doesn't require it in USING clause
            $sourceSql = preg_replace('/\s+AS\s+source$/i', ' source', $sourceSql);
        }

        // Ensure onClause uses proper table aliases (target and source)
        $onClauseReplaced = preg_replace('/\btarget\./i', 'target.', $onClause);
        $onClause = is_string($onClauseReplaced) ? $onClauseReplaced : $onClause;
        $onClauseReplaced = preg_replace('/\bsource\./i', 'source.', $onClause);
        $onClause = is_string($onClauseReplaced) ? $onClauseReplaced : $onClause;

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
