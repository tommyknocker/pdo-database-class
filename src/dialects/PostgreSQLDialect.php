<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\traits\JsonPathBuilderTrait;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class PostgreSQLDialect extends DialectAbstract
{
    use JsonPathBuilderTrait;
    use UpsertBuilderTrait;
    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'pgsql';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsLateralJoin(): bool
    {
        // PostgreSQL has native LATERAL JOIN support since version 9.3
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsJoinInUpdateDelete(): bool
    {
        return true;
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
        $allConditions = [];
        if (!empty($joinConditions)) {
            $allConditions[] = implode(' AND ', $joinConditions);
        }
        if (trim($whereClause) !== '' && trim($whereClause) !== 'WHERE') {
            // Remove WHERE keyword and add condition
            $whereCondition = preg_replace('/^\s*WHERE\s+/i', '', $whereClause);
            if ($whereCondition !== null && $whereCondition !== '') {
                $allConditions[] = $whereCondition;
            }
        }

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
        // PostgreSQL uses USING clause in DELETE
        // Convert JOIN clauses to USING clause
        $usingTables = [];
        $joinConditions = [];

        foreach ($joins as $join) {
            // Parse JOIN clause: "INNER JOIN table ON condition"
            if (preg_match('/\s+(?:INNER\s+)?JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                // Extract table name (may have alias)
                $tablePart = trim($matches[1]);
                // Remove alias if present
                $tableParts = preg_split('/\s+/', $tablePart);
                $tableName = $tableParts !== false && count($tableParts) > 0 ? $tableParts[0] : $tablePart;
                $usingTables[] = $tableName;
                $joinConditions[] = $matches[2];
            } elseif (preg_match('/\s+LEFT\s+JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                $tablePart = trim($matches[1]);
                $tableParts = preg_split('/\s+/', $tablePart);
                $tableName = $tableParts !== false && count($tableParts) > 0 ? $tableParts[0] : $tablePart;
                $usingTables[] = $tableName;
                $joinConditions[] = $matches[2];
            } elseif (preg_match('/\s+RIGHT\s+JOIN\s+([^\s]+(?:\s+[^\s]+)?)\s+ON\s+(.+)/i', $join, $matches)) {
                // RIGHT JOIN - PostgreSQL doesn't support RIGHT JOIN in DELETE, convert to LEFT JOIN
                $tablePart = trim($matches[1]);
                $tableParts = preg_split('/\s+/', $tablePart);
                $tableName = $tableParts !== false && count($tableParts) > 0 ? $tableParts[0] : $tablePart;
                $usingTables[] = $tableName;
                $joinConditions[] = $matches[2];
            }
        }

        $sql = "DELETE {$options}FROM {$table}";
        if (!empty($usingTables)) {
            $sql .= ' USING ' . implode(', ', $usingTables);
        }

        // Combine JOIN conditions with WHERE clause
        $allConditions = [];
        if (!empty($joinConditions)) {
            $allConditions[] = implode(' AND ', $joinConditions);
        }
        if (trim($whereClause) !== '' && trim($whereClause) !== 'WHERE') {
            // Remove WHERE keyword and add condition
            $whereCondition = preg_replace('/^\s*WHERE\s+/i', '', $whereClause);
            if ($whereCondition !== null && $whereCondition !== '') {
                $allConditions[] = $whereCondition;
            }
        }

        if (!empty($allConditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $allConditions);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDsn(array $params): string
    {
        foreach (['host', 'dbname', 'username', 'password'] as $requiredParam) {
            if (empty($params[$requiredParam])) {
                throw new InvalidArgumentException("Missing '$requiredParam' parameter");
            }
        }
        return "pgsql:host={$params['host']};dbname={$params['dbname']}"
            . (!empty($params['hostaddr']) ? ";hostaddr={$params['hostaddr']}" : '')
            . (!empty($params['service']) ? ";service={$params['service']}" : '')
            . (!empty($params['port']) ? ";port={$params['port']}" : '')
            . (!empty($params['options']) ? ";options={$params['options']}" : '')
            . (!empty($params['sslmode']) ? ";sslmode={$params['sslmode']}" : '')
            . (!empty($params['sslkey']) ? ";sslkey={$params['sslkey']}" : '')
            . (!empty($params['sslcert']) ? ";sslcert={$params['sslcert']}" : '')
            . (!empty($params['sslrootcert']) ? ";sslrootcert={$params['sslrootcert']}" : '')
            . (!empty($params['application_name']) ? ";application_name={$params['application_name']}" : '')
            . (!empty($params['connect_timeout']) ? ";connect_timeout={$params['connect_timeout']}" : '')
            . (!empty($params['target_session_attrs']) ? ";target_session_attrs={$params['target_session_attrs']}" : '');
    }

    /**
     * {@inheritDoc}
     */
    public function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(string $name): string
    {
        return "\"{$name}\"";
    }

    /**
     * {@inheritDoc}
     */
    public function quoteTable(mixed $table): string
    {
        return $this->quoteTableWithAlias($table);
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
     *
     * @param array<string, mixed> $options
     */
    public function formatSelectOptions(string $sql, array $options): string
    {
        $tail = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            // PG supports FOR UPDATE / FOR SHARE as tail options
            if (in_array($u, ['FOR UPDATE', 'FOR SHARE', 'FOR NO KEY UPDATE', 'FOR KEY SHARE'])) {
                $tail[] = $opt;
            }
        }
        if ($tail) {
            $sql .= ' ' . implode(' ', $tail);
        }
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
    public function supportsMerge(): bool
    {
        // PostgreSQL 15+ supports MERGE
        // Check version if needed, or assume 15+ for now
        return true;
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
        if ($whenClauses['whenNotMatchedBySourceDelete']) {
            $sql .= "WHEN NOT MATCHED BY SOURCE THEN\n";
            $sql .= "  DELETE\n";
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $expr
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
     * {@inheritDoc}
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
     * {@inheritDoc}
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

    /**
     * {@inheritDoc}
     *
     * @param array<int, string> $columns
     * @param array<int, string|array<int, string>> $placeholders
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
    public function now(?string $diff = '', bool $asTimestamp = false): string
    {
        if (!$diff) {
            return $asTimestamp ? 'EXTRACT(EPOCH FROM NOW())' : 'NOW()';
        }

        $trimmedDif = trim($diff);
        if (preg_match('/^([+-]?)(\d+)\s+([A-Za-z]+)$/', $trimmedDif, $matches)) {
            $sign = $matches[1] === '-' ? '-' : '+';
            $value = $matches[2];
            $unit = strtolower($matches[3]);

            if ($asTimestamp) {
                return "EXTRACT(EPOCH FROM (NOW() {$sign} INTERVAL '{$value} {$unit}'))";
            }

            return "NOW() {$sign} INTERVAL '{$value} {$unit}'";
        }

        if ($asTimestamp) {
            return "EXTRACT(EPOCH FROM (NOW() + INTERVAL '{$trimmedDif}'))";
        }

        return "NOW() + INTERVAL '{$trimmedDif}'";
    }

    /**
     * {@inheritDoc}
     */
    public function ilike(string $column, string $pattern): RawValue
    {
        return new RawValue("$column ILIKE :pattern", ['pattern' => $pattern]);
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainSql(string $query): string
    {
        return 'EXPLAIN ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainAnalyzeSql(string $query): string
    {
        return 'EXPLAIN ANALYZE ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildTableExistsSql(string $table): string
    {
        return "SELECT to_regclass('{$table}') IS NOT NULL AS exists";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return "SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_name = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        $list = implode(', ', array_map(fn ($t) => $this->quoteIdentifier($prefix . $t), $tables));
        return "LOCK TABLE {$list} IN ACCESS EXCLUSIVE MODE";
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        // PostgreSQL does not need unlock
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function buildTruncateSql(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadCsvSql(string $table, string $filePath, array $options = []): string
    {
        if ($this->pdo === null) {
            throw new RuntimeException('PDO instance not set. Call setPdo() first.');
        }
        $pdo = $this->pdo;

        $tableSql = $this->quoteIdentifier($table);
        $quotedPath = $pdo->quote($filePath);

        $delimiter = $options['fieldChar'] ?? ',';
        $quoteChar = $options['fieldEnclosure'] ?? '"';
        $header = isset($options['header']) && (bool)$options['header'];

        $columnsSql = '';
        if (!empty($options['fields']) && is_array($options['fields'])) {
            $columnsSql = ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $options['fields'])) . ')';
        }

        $del = $this->pdo->quote($delimiter);
        $quo = $this->pdo->quote($quoteChar);

        return sprintf(
            'COPY %s%s FROM %s WITH (FORMAT csv, HEADER %s, DELIMITER %s, QUOTE %s)',
            $tableSql,
            $columnsSql,
            $quotedPath,
            $header ? 'true' : 'false',
            $del,
            $quo
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        // PostgreSQL uses native COPY which loads entire file at once
        yield $this->buildLoadCsvSql($table, $filePath, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string
    {
        $parts = $this->normalizeJsonPath($path);
        if (empty($parts)) {
            return $asText ? $this->quoteIdentifier($col) . '::text' : $this->quoteIdentifier($col);
        }
        $arr = $this->buildPostgreSqlJsonPath($parts);
        // build #> or #>> with array literal
        if ($asText) {
            return $this->quoteIdentifier($col) . ' #>> ' . $arr;
        }
        return $this->quoteIdentifier($col) . ' #> ' . $arr;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \JsonException
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string
    {
        $colQuoted = $this->quoteIdentifier($col);
        $parts = $this->normalizeJsonPath($path ?? []);
        $paramJson = $this->generateParameterName('jsonc', $col);
        $json = $this->encodeToJson($value);

        if (empty($parts)) {
            $sql = "{$colQuoted}::jsonb @> {$paramJson}::jsonb";
            return [$sql, [$paramJson => $json]];
        }

        $arr = $this->buildPostgreSqlJsonPath($parts);
        $extracted = "{$colQuoted}::jsonb #> {$arr}";

        if (is_string($value)) {
            // Avoid using the '?' operator because PDO treats '?' as positional placeholder.
            // Use EXISTS with jsonb_array_elements_text to check presence of string element.
            $tokenParam = $this->generateParameterName('jsonc_token', $col);
            $sql = "EXISTS (SELECT 1 FROM jsonb_array_elements_text({$extracted}) AS _e(val) WHERE _e.val = {$tokenParam})";
            return [$sql, [$tokenParam => (string)$value]];
        }

        // non-string scalar or array/object: use jsonb containment against extracted node
        $sql = "{$extracted} @> {$paramJson}::jsonb";
        return [$sql, [$paramJson => $json]];
    }

    /**
     * {@inheritDoc}
     *
     * @throws \JsonException
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);

        // build pg path for full path and for parent path
        $pgPath = $this->buildPostgreSqlJsonbPath($parts);
        if (count($parts) > 1) {
            $parentParts = $this->getParentPathParts($parts);
            $pgParentPath = $this->buildPostgreSqlJsonbPath($parentParts);
            $lastKey = $this->getLastSegment($parts);
        } else {
            $pgParentPath = null;
            $lastKey = $parts[0];
        }

        $param = $this->generateParameterName('jsonset', $col . '|' . $pgPath);

        // produce json text to bind exactly once (use as-is if it already looks like JSON)
        if (is_string($value)) {
            $jsonText = $this->looksLikeJson($value) ? $value : $this->encodeToJson($value);
        } else {
            $jsonText = $this->encodeToJson($value);
        }

        // If there is a parent path, first ensure parent exists as object: set(parentPath, COALESCE(col->parent, {}))
        // Then set the final child inside that parent with jsonb_set(..., fullPath, to_jsonb(CAST(:param AS json)), true)
        if ($pgParentPath !== null) {
            $ensureParent = "jsonb_set({$colQuoted}::jsonb, '{$pgParentPath}', COALESCE({$colQuoted}::jsonb #> '{$pgParentPath}', '{}'::jsonb), true)";
            $expr = "jsonb_set({$ensureParent}, '{$pgPath}', to_jsonb(CAST({$param} AS json)), true)::json";
        } else {
            // single segment: just set directly
            $expr = "jsonb_set({$colQuoted}::jsonb, '{$pgPath}', to_jsonb(CAST({$param} AS json)), true)::json";
        }

        return [$expr, [$param => $jsonText]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);

        // Build pg path array literal for functions that need it
        $pgPathArr = $this->buildPostgreSqlJsonbPath($parts);

        // If last segment is numeric -> treat as array index: set that element to JSON null (preserve indices)
        $last = $this->getLastSegment($parts);
        if ($this->isNumericIndex($last)) {
            // Use jsonb_set to assign JSON null at exact path; create parents if needed
            return "jsonb_set({$colQuoted}::jsonb, '{$pgPathArr}', 'null'::jsonb, true)::jsonb";
        }

        // Otherwise treat as object key removal: use #- operator which removes key at path (works for nested paths)
        // Operator expects text[] on right-hand side; use the same pgPathArr
        // Return expression only (no "col ="), QueryBuilder will use: SET "meta" = <expr>
        return "({$colQuoted}::jsonb #- '{$pgPathArr}')::jsonb";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonReplace(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);
        $pgPath = $this->buildPostgreSqlJsonbPath($parts);

        $param = $this->generateParameterName('jsonreplace', $col . '|' . $pgPath);

        if (is_string($value)) {
            $jsonText = $this->looksLikeJson($value) ? $value : $this->encodeToJson($value);
        } else {
            $jsonText = $this->encodeToJson($value);
        }

        // jsonb_set with create_missing=false only replaces if path exists
        $expr = "jsonb_set({$colQuoted}::jsonb, '{$pgPath}', to_jsonb(CAST({$param} AS json)), false)::json";

        return [$expr, [$param => $jsonText]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonExists(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);

        // Build jsonpath for jsonb_path_exists
        $jsonPath = $this->buildJsonPathString($parts);

        $checks = ["jsonb_path_exists({$colQuoted}::jsonb, '{$jsonPath}')"];

        // Build prefix existence checks without using the ? operator to avoid PDO positional placeholder issues
        $prefixExpr = $colQuoted . '::jsonb';
        $accum = [];
        foreach ($parts as $i => $p) {
            $isIndex = $this->isNumericIndex($p);
            if ($isIndex) {
                $idx = (int)$p;
                $lenCheck = "(jsonb_typeof({$prefixExpr}) = 'array' AND jsonb_array_length({$prefixExpr}) > {$idx})";
                $accum[] = $lenCheck;
                $prefixExpr = "{$prefixExpr} -> {$idx}";
            } else {
                // use -> to get child and IS NOT NULL to check existence (avoids ? operator)
                $keyLiteral = "'" . str_replace("'", "''", (string)$p) . "'";
                $accum[] = "({$prefixExpr} -> {$keyLiteral}) IS NOT NULL";
                $prefixExpr = "{$prefixExpr} -> {$keyLiteral}";
            }
        }

        if (!empty($accum)) {
            $prefixCheck = '(' . implode(' AND ', $accum) . ')';
            $checks[] = $prefixCheck;
        }

        // Final fallback: #> path not null. Build pg path array literal safely.
        $pgPathArr = $this->buildPostgreSqlJsonbPath($parts);
        $checks[] = "({$colQuoted}::jsonb #> '{$pgPathArr}') IS NOT NULL";

        return '(' . implode(' OR ', $checks) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        if (empty($parts)) {
            // whole column: cast text to numeric when possible
            $expr = $this->quoteIdentifier($col) . '::text';
        } else {
            $arr = $this->buildPostgreSqlJsonPath($parts);
            $expr = $this->quoteIdentifier($col) . ' #>> ' . $arr;
        }

        // CASE expression: if text looks like a number, cast to numeric for ordering, otherwise NULL
        // This yields numeric values for numeric entries and NULL for non-numeric ones.
        return "CASE WHEN ({$expr}) ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN ({$expr})::numeric ELSE NULL END";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // For whole column, use jsonb_array_length for arrays, return 0 for others
            return "CASE WHEN jsonb_typeof({$colQuoted}::jsonb) = 'array' THEN jsonb_array_length({$colQuoted}::jsonb) ELSE 0 END";
        }

        $parts = $this->normalizeJsonPath($path);
        $arr = $this->buildPostgreSqlJsonPath($parts);
        $extracted = "{$colQuoted}::jsonb #> {$arr}";

        return "CASE WHEN jsonb_typeof({$extracted}) = 'array' THEN jsonb_array_length({$extracted}) ELSE 0 END";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // PostgreSQL doesn't have a simple JSON_KEYS function, return a placeholder
            return "'[keys]'";
        }

        $parts = $this->normalizeJsonPath($path);
        $arr = $this->buildPostgreSqlJsonPath($parts);

        // PostgreSQL doesn't have a simple JSON_KEYS function, return a placeholder
        return "'[keys]'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonType(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            return "jsonb_typeof({$colQuoted}::jsonb)";
        }

        $parts = $this->normalizeJsonPath($path);
        $arr = $this->buildPostgreSqlJsonPath($parts);
        $extracted = "{$colQuoted}::jsonb #> {$arr}";

        return "jsonb_typeof({$extracted})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatIfNull(string $expr, mixed $default): string
    {
        // PostgreSQL doesn't have IFNULL, use COALESCE
        return "COALESCE($expr, {$this->formatDefaultValue($default)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string
    {
        $src = $this->resolveValue($source);
        if ($length === null) {
            return "SUBSTRING($src FROM $start)";
        }
        return "SUBSTRING($src FROM $start FOR $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurDate(): string
    {
        return 'CURRENT_DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurTime(): string
    {
        return 'CURRENT_TIME';
    }

    /**
     * {@inheritDoc}
     */
    public function formatYear(string|RawValue $value): string
    {
        return "EXTRACT(YEAR FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMonth(string|RawValue $value): string
    {
        return "EXTRACT(MONTH FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDay(string|RawValue $value): string
    {
        return "EXTRACT(DAY FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatHour(string|RawValue $value): string
    {
        return "EXTRACT(HOUR FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMinute(string|RawValue $value): string
    {
        return "EXTRACT(MINUTE FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSecond(string|RawValue $value): string
    {
        return "EXTRACT(SECOND FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        $e = $this->resolveValue($expr);
        $sign = $isAdd ? '+' : '-';
        // PostgreSQL uses INTERVAL 'value unit' syntax
        return "{$e} {$sign} INTERVAL '{$value} {$unit}'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = addslashes($separator);
        $dist = $distinct ? 'DISTINCT ' : '';
        return "STRING_AGG($dist$col, '$sep')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        $val = $this->resolveValue($value);
        return "TRUNC($val, $precision)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : "'" . addslashes((string)$substring) . "'";
        $val = $this->resolveValue($value);
        return "POSITION($sub IN $val)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRepeat(string|RawValue $value, int $count): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : "'" . addslashes((string)$value) . "'";
        return "REPEAT($val, $count)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatReverse(string|RawValue $value): string
    {
        $val = $this->resolveValue($value);
        return "REVERSE($val)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string
    {
        $val = $this->resolveValue($value);
        $pad = addslashes($padString);
        $func = $isLeft ? 'LPAD' : 'RPAD';
        return "{$func}($val, $length, '$pad')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpMatch(string|RawValue $value, string $pattern): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        // PostgreSQL uses ~ operator for case-sensitive match
        return "($val ~ '$pat')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        $rep = str_replace("'", "''", $replacement);
        return "regexp_replace($val, '$pat', '$rep', 'g')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        $val = $this->resolveValue($value);
        // Escape single quotes for PostgreSQL string literal (backslashes are handled by PostgreSQL regex)
        $pat = str_replace("'", "''", $pattern);
        // PostgreSQL regexp_match returns array, use (regexp_match(...))[1] for first group
        // For full match (groupIndex = 0 or null), use [1] (full match)
        // For specific group, use [groupIndex + 1] since PostgreSQL arrays are 1-indexed
        // [1] = full match, [2] = first capture group, [3] = second capture group, etc.
        // Note: regexp_match returns NULL if no match, so array indexing will also return NULL
        if ($groupIndex !== null && $groupIndex > 0) {
            $arrayIndex = $groupIndex + 1;
            return "(regexp_match($val, '$pat'))[$arrayIndex]";
        }
        // For full match, use [1] which is the full match
        return "(regexp_match($val, '$pat'))[1]";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDateOnly(string|RawValue $value): string
    {
        return $this->resolveValue($value) . '::DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function formatTimeOnly(string|RawValue $value): string
    {
        return $this->resolveValue($value) . '::TIME';
    }

    /**
     * {@inheritDoc}
     */
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $quotedCols = array_map([$this, 'quoteIdentifier'], $cols);
        $colList = implode(' || ', $quotedCols);

        $ph = ':fulltext_search_term';
        $sql = "$colList @@ to_tsquery('english', $ph)";

        return [$sql, [$ph => $searchTerm]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatWindowFunction(
        string $function,
        array $args,
        array $partitionBy,
        array $orderBy,
        ?string $frameClause
    ): string {
        $sql = strtoupper($function) . '(';

        // Add function arguments
        if (!empty($args)) {
            $formattedArgs = array_map(function ($arg) {
                if (is_string($arg) && !is_numeric($arg)) {
                    return $this->quoteIdentifier($arg);
                }
                if (is_null($arg)) {
                    return 'NULL';
                }
                return (string)$arg;
            }, $args);
            $sql .= implode(', ', $formattedArgs);
        }

        $sql .= ') OVER (';

        // Add PARTITION BY
        if (!empty($partitionBy)) {
            $quotedPartitions = array_map(
                fn ($col) => $this->quoteIdentifier($col),
                $partitionBy
            );
            $sql .= 'PARTITION BY ' . implode(', ', $quotedPartitions);
        }

        // Add ORDER BY
        if (!empty($orderBy)) {
            if (!empty($partitionBy)) {
                $sql .= ' ';
            }
            $orderClauses = [];
            foreach ($orderBy as $order) {
                foreach ($order as $col => $dir) {
                    $orderClauses[] = $this->quoteIdentifier($col) . ' ' . strtoupper($dir);
                }
            }
            $sql .= 'ORDER BY ' . implode(', ', $orderClauses);
        }

        // Add frame clause
        if ($frameClause !== null) {
            $sql .= ' ' . $frameClause;
        }

        $sql .= ')';

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFilterClause(): bool
    {
        return true; // PostgreSQL supports FILTER clause
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        return true; // PostgreSQL supports DISTINCT ON
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        return true; // PostgreSQL supports MATERIALIZED CTE (12+)
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowIndexesSql(string $table): string
    {
        return "SELECT
            indexname as index_name,
            tablename as table_name,
            indexdef as definition
            FROM pg_indexes
            WHERE tablename = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        return "SELECT
            tc.constraint_name,
            kcu.column_name,
            ccu.table_name AS referenced_table_name,
            ccu.column_name AS referenced_column_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY'
            AND tc.table_name = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        return "SELECT
            tc.constraint_name,
            tc.constraint_type,
            tc.table_name,
            kcu.column_name
            FROM information_schema.table_constraints tc
            LEFT JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
            WHERE tc.table_name = '{$table}'";
    }

    /* ---------------- DDL Operations ---------------- */

    /**
     * {@inheritDoc}
     */
    public function buildCreateTableSql(
        string $table,
        array $columns,
        array $options = []
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnDefs = [];

        foreach ($columns as $name => $def) {
            if ($def instanceof ColumnSchema) {
                $columnDefs[] = $this->formatColumnDefinition($name, $def);
            } elseif (is_array($def)) {
                // Short syntax: ['type' => 'VARCHAR(255)', 'null' => false]
                $schema = $this->parseColumnDefinition($def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
            } else {
                // String type: 'VARCHAR(255)'
                $schema = new ColumnSchema((string)$def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
            }
        }

        // Add PRIMARY KEY constraint from options if specified
        if (!empty($options['primaryKey'])) {
            $pkColumns = is_array($options['primaryKey']) ? $options['primaryKey'] : [$options['primaryKey']];
            $pkQuoted = array_map([$this, 'quoteIdentifier'], $pkColumns);
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
        }

        $sql = "CREATE TABLE {$tableQuoted} (\n    " . implode(",\n    ", $columnDefs) . "\n)";

        // PostgreSQL table options (TABLESPACE, WITH, etc.)
        if (!empty($options['tablespace'])) {
            $sql .= ' TABLESPACE ' . $this->quoteIdentifier($options['tablespace']);
        }
        if (isset($options['with']) && is_array($options['with'])) {
            $withOptions = [];
            foreach ($options['with'] as $key => $value) {
                $withOptions[] = $this->quoteIdentifier($key) . ' = ' . (is_numeric($value) ? $value : "'" . addslashes((string)$value) . "'");
            }
            if (!empty($withOptions)) {
                $sql .= ' WITH (' . implode(', ', $withOptions) . ')';
            }
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableSql(string $table): string
    {
        return 'DROP TABLE ' . $this->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableIfExistsSql(string $table): string
    {
        // Use CASCADE to drop dependent objects (foreign keys, constraints, etc.)
        // This is necessary when tables have dependencies from previous test runs
        return 'DROP TABLE IF EXISTS ' . $this->quoteTable($table) . ' CASCADE';
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnDef = $this->formatColumnDefinition($column, $schema);
        // PostgreSQL doesn't support FIRST/AFTER in ALTER TABLE ADD COLUMN
        return "ALTER TABLE {$tableQuoted} ADD COLUMN {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropColumnSql(string $table, string $column): string
    {
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);
        return "ALTER TABLE {$tableQuoted} DROP COLUMN {$columnQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAlterColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);

        $parts = [];
        $type = $schema->getType();
        if ($schema->getLength() !== null) {
            if ($schema->getScale() !== null) {
                $type .= '(' . $schema->getLength() . ',' . $schema->getScale() . ')';
            } else {
                $type .= '(' . $schema->getLength() . ')';
            }
        }
        if ($type !== '') {
            $parts[] = "ALTER COLUMN {$columnQuoted} TYPE {$type}";
        }
        if ($schema->isNotNull()) {
            $parts[] = "ALTER COLUMN {$columnQuoted} SET NOT NULL";
        }
        if ($schema->getDefaultValue() !== null) {
            if ($schema->isDefaultExpression()) {
                $parts[] = "ALTER COLUMN {$columnQuoted} SET DEFAULT " . $schema->getDefaultValue();
            } else {
                $default = $this->formatDefaultValue($schema->getDefaultValue());
                $parts[] = "ALTER COLUMN {$columnQuoted} SET DEFAULT {$default}";
            }
        }

        if (empty($parts)) {
            return "ALTER TABLE {$tableQuoted} ALTER COLUMN {$columnQuoted} TYPE " . ($schema->getType() ?: 'text');
        }

        return "ALTER TABLE {$tableQuoted} " . implode(', ', $parts);
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $oldQuoted = $this->quoteIdentifier($oldName);
        $newQuoted = $this->quoteIdentifier($newName);
        return "ALTER TABLE {$tableQuoted} RENAME COLUMN {$oldQuoted} TO {$newQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateIndexSql(string $name, string $table, array $columns, bool $unique = false): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $uniqueClause = $unique ? 'UNIQUE ' : '';
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE {$uniqueClause}INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        // PostgreSQL: DROP INDEX name (table is not needed)
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddForeignKeySql(
        string $name,
        string $table,
        array $columns,
        string $refTable,
        array $refColumns,
        ?string $delete = null,
        ?string $update = null
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $refTableQuoted = $this->quoteTable($refTable);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $refColsQuoted = array_map([$this, 'quoteIdentifier'], $refColumns);
        $colsList = implode(', ', $colsQuoted);
        $refColsList = implode(', ', $refColsQuoted);

        $sql = "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted}";
        $sql .= " FOREIGN KEY ({$colsList}) REFERENCES {$refTableQuoted} ({$refColsList})";

        if ($delete !== null) {
            $sql .= ' ON DELETE ' . strtoupper($delete);
        }
        if ($update !== null) {
            $sql .= ' ON UPDATE ' . strtoupper($update);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropForeignKeySql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CONSTRAINT {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameTableSql(string $table, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $newQuoted = $this->quoteTable($newName);
        return "ALTER TABLE {$tableQuoted} RENAME TO {$newQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string
    {
        $nameQuoted = $this->quoteIdentifier($name);
        $type = $schema->getType();

        // PostgreSQL type mapping
        if ($type === 'INT' || $type === 'INTEGER') {
            $type = 'INTEGER';
        } elseif ($type === 'TINYINT' || $type === 'SMALLINT') {
            $type = 'SMALLINT';
        } elseif ($type === 'BIGINT') {
            $type = 'BIGINT';
        } elseif ($type === 'TEXT') {
            $type = 'TEXT';
        } elseif ($type === 'DATETIME' || $type === 'TIMESTAMP') {
            $type = 'TIMESTAMP';
        }

        // Build type with length/scale
        $typeDef = $type;
        if ($schema->getLength() !== null) {
            if ($schema->getScale() !== null) {
                $typeDef .= '(' . $schema->getLength() . ',' . $schema->getScale() . ')';
            } else {
                // For VARCHAR and similar
                if (in_array(strtoupper($type), ['VARCHAR', 'CHAR', 'CHARACTER VARYING', 'CHARACTER'], true)) {
                    $typeDef .= '(' . $schema->getLength() . ')';
                }
            }
        }

        // SERIAL type handling (PostgreSQL auto-increment)
        if ($schema->isAutoIncrement()) {
            if ($type === 'INTEGER' || $type === 'INT') {
                $typeDef = 'SERIAL';
            } elseif ($type === 'BIGINT') {
                $typeDef = 'BIGSERIAL';
            } elseif ($type === 'SMALLINT') {
                $typeDef = 'SMALLSERIAL';
            }
        }

        $parts = [$nameQuoted, $typeDef];

        // NOT NULL / NULL
        if ($schema->isNotNull()) {
            $parts[] = 'NOT NULL';
        }

        // DEFAULT (only if not SERIAL, as SERIAL includes auto-increment)
        if ($schema->getDefaultValue() !== null && !$schema->isAutoIncrement()) {
            if ($schema->isDefaultExpression()) {
                $parts[] = 'DEFAULT ' . $schema->getDefaultValue();
            } else {
                $default = $this->formatDefaultValue($schema->getDefaultValue());
                $parts[] = 'DEFAULT ' . $default;
            }
        }

        // UNIQUE is handled separately (not in column definition)
        // It's created via CREATE INDEX or table constraint

        return implode(' ', $parts);
    }

    /**
     * Parse column definition from array.
     *
     * @param array<string, mixed> $def Definition array
     *
     * @return ColumnSchema
     */
    protected function parseColumnDefinition(array $def): ColumnSchema
    {
        $type = $def['type'] ?? 'VARCHAR';
        $length = $def['length'] ?? $def['size'] ?? null;
        $scale = $def['scale'] ?? null;

        $schema = new ColumnSchema((string)$type, $length, $scale);

        if (isset($def['null']) && $def['null'] === false) {
            $schema->notNull();
        }
        if (isset($def['default'])) {
            if (isset($def['defaultExpression']) && $def['defaultExpression']) {
                $schema->defaultExpression((string)$def['default']);
            } else {
                $schema->defaultValue($def['default']);
            }
        }
        if (isset($def['comment'])) {
            // PostgreSQL comments are set separately via COMMENT ON COLUMN
            $schema->comment((string)$def['comment']);
        }
        if (isset($def['autoIncrement']) && $def['autoIncrement']) {
            $schema->autoIncrement();
        }
        if (isset($def['unique']) && $def['unique']) {
            $schema->unique();
        }

        // PostgreSQL doesn't support FIRST/AFTER in ALTER TABLE
        // These are silently ignored

        return $schema;
    }

    /**
     * Format default value for SQL.
     *
     * @param mixed $value Default value
     *
     * @return string SQL formatted value
     */
    protected function formatDefaultValue(mixed $value): string
    {
        if ($value instanceof RawValue) {
            return $value->getValue();
        }
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        return "'" . addslashes((string)$value) . "'";
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeRawValue(string $sql): string
    {
        // PostgreSQL CAST throws errors for invalid values
        // Replace CAST with safe CASE WHEN expressions for common types
        // This allows safe type conversion without errors

        // Pattern: CAST(column AS INTEGER) -> CASE WHEN column ~ '^[0-9]+$' THEN column::INTEGER ELSE NULL END
        // Match CAST(expr AS type) where expr can be a column name (possibly qualified) or a simple expression
        // Make sure to match the closing parenthesis
        $sql = preg_replace_callback(
            '/\bCAST\s*\(\s*([a-zA-Z_][a-zA-Z0-9_.]*|"[^"]+"|`[^`]+`|\[[^\]]+\])\s+AS\s+(INTEGER|INT|BIGINT|SMALLINT|SERIAL|BIGSERIAL)\s*\)/i',
            function ($matches) {
                $column = trim($matches[1]);
                $type = strtoupper($matches[2]);
                // Use ::TYPE syntax for PostgreSQL
                return "CASE WHEN {$column} ~ '^[0-9]+$' THEN {$column}::{$type} ELSE NULL END";
            },
            $sql
        ) ?? $sql;

        // Pattern: CAST(column AS NUMERIC|DECIMAL|REAL|DOUBLE|FLOAT) -> CASE WHEN column ~ '^[0-9]+\.?[0-9]*$' THEN column::TYPE ELSE NULL END
        $sql = preg_replace_callback(
            '/\bCAST\s*\(\s*([a-zA-Z_][a-zA-Z0-9_.]*|"[^"]+"|`[^`]+`|\[[^\]]+\])\s+AS\s+(NUMERIC|DECIMAL|REAL|DOUBLE\s+PRECISION|FLOAT|DOUBLE)\s*\)/i',
            function ($matches) {
                $column = trim($matches[1]);
                $type = strtoupper($matches[2]);
                // Use ::TYPE syntax for PostgreSQL
                return "CASE WHEN {$column} ~ '^[0-9]+\\.?[0-9]*$' THEN {$column}::{$type} ELSE NULL END";
            },
            $sql
        ) ?? $sql;

        // Pattern: CAST(column AS DATE|TIMESTAMP|TIME) -> CASE WHEN column is valid date/timestamp/time THEN column::TYPE ELSE NULL END
        $sql = preg_replace_callback(
            '/\bCAST\s*\(\s*([a-zA-Z_][a-zA-Z0-9_.]*|"[^"]+"|`[^`]+`|\[[^\]]+\])\s+AS\s+(DATE|TIMESTAMP|TIME)\s*\)/i',
            function ($matches) {
                $column = trim($matches[1]);
                $type = strtoupper($matches[2]);
                // Use ::TYPE syntax for PostgreSQL
                // Try to cast, return NULL if invalid (PostgreSQL will throw error, so we use a function)
                // For DATE, check if it matches a date pattern
                if ($type === 'DATE') {
                    return "CASE WHEN {$column}::text ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN {$column}::{$type} ELSE NULL END";
                }
                // For TIMESTAMP and TIME, use similar pattern
                return "CASE WHEN {$column}::text ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN {$column}::{$type} ELSE NULL END";
            },
            $sql
        ) ?? $sql;

        // Convert standard SUBSTRING(expr, start, length) to PostgreSQL SUBSTRING(expr FROM start FOR length)
        // Pattern: SUBSTRING(expr, start, length) -> SUBSTRING(expr FROM start FOR length)
        $sql = preg_replace_callback(
            '/\bSUBSTRING\s*\(\s*([^,]+),\s*([^,]+),\s*([^)]+)\s*\)/i',
            function ($matches) {
                $expr = trim($matches[1]);
                $start = trim($matches[2]);
                $length = trim($matches[3]);
                return "SUBSTRING({$expr} FROM {$start} FOR {$length})";
            },
            $sql
        ) ?? $sql;

        // Pattern: SUBSTRING(expr, start) -> SUBSTRING(expr FROM start)
        $sql = preg_replace_callback(
            '/\bSUBSTRING\s*\(\s*([^,]+),\s*([^)]+)\s*\)/i',
            function ($matches) {
                $expr = trim($matches[1]);
                $start = trim($matches[2]);
                return "SUBSTRING({$expr} FROM {$start})";
            },
            $sql
        ) ?? $sql;

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatGreatest(array $values): string
    {
        $args = $this->resolveValues($values);
        // Apply normalizeRawValue to each argument to handle safe CAST conversion
        $normalizedArgs = array_map(function ($arg) {
            return $this->normalizeRawValue((string)$arg);
        }, $args);
        return 'GREATEST(' . implode(', ', $normalizedArgs) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeast(array $values): string
    {
        $args = $this->resolveValues($values);
        // Apply normalizeRawValue to each argument to handle safe CAST conversion
        $normalizedArgs = array_map(function ($arg) {
            return $this->normalizeRawValue((string)$arg);
        }, $args);
        return 'LEAST(' . implode(', ', $normalizedArgs) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function buildExistsExpression(string $subquery): string
    {
        return 'SELECT EXISTS(' . $subquery . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsLimitInExists(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanType(): array
    {
        return ['type' => 'BOOLEAN', 'length' => null];
    }

    /**
     * {@inheritDoc}
     */
    public function getTimestampType(): string
    {
        return 'TIMESTAMP';
    }

    /**
     * {@inheritDoc}
     */
    public function getDatetimeType(): string
    {
        return 'TIMESTAMP';
    }

    /**
     * {@inheritDoc}
     */
    public function getPrimaryKeyType(): string
    {
        return 'INTEGER';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigPrimaryKeyType(): string
    {
        return 'BIGSERIAL';
    }

    /**
     * {@inheritDoc}
     */
    public function getStringType(): string
    {
        return 'VARCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getCharType(): string
    {
        return 'CHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        if ($isMaterialized) {
            // PostgreSQL 12+: MATERIALIZED goes after AS
            // Return SQL with MATERIALIZED marker that CteManager will use
            return 'MATERIALIZED:' . $cteSql;
        }
        return $cteSql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        $tableQuoted = $this->quoteTable($tableName);
        return "CREATE TABLE {$tableQuoted} (
            version VARCHAR(255) PRIMARY KEY,
            apply_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            batch INTEGER NOT NULL
        )";
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationInsertSql(string $tableName, string $version, int $batch): array
    {
        // PostgreSQL uses named parameters
        $tableQuoted = $this->quoteTable($tableName);
        return [
            "INSERT INTO {$tableQuoted} (version, batch) VALUES (:version, :batch)",
            ['version' => $version, 'batch' => $batch],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function extractErrorCode(\PDOException $e): string
    {
        // PostgreSQL stores SQLSTATE in errorInfo[0]
        if (isset($e->errorInfo[0])) {
            return $e->errorInfo[0];
        }
        return (string) $e->getCode();
    }

    /**
     * {@inheritDoc}
     */
    public function getExplainParser(): ExplainParserInterface
    {
        return new \tommyknocker\pdodb\query\analysis\parsers\PostgreSQLExplainParser();
    }
}
