<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\postgresql;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\PostgreSQLExplainParser;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class PostgreSQLDialect extends DialectAbstract
{
    /** @var PostgreSQLFeatureSupport Feature support instance */
    private PostgreSQLFeatureSupport $featureSupport;

    /** @var PostgreSQLSqlFormatter SQL formatter instance */
    private PostgreSQLSqlFormatter $sqlFormatter;

    /** @var PostgreSQLDmlBuilder DML builder instance */
    private PostgreSQLDmlBuilder $dmlBuilder;

    /** @var PostgreSQLDdlBuilder DDL builder instance */
    private PostgreSQLDdlBuilder $ddlBuilder;

    public function __construct()
    {
        $this->featureSupport = new PostgreSQLFeatureSupport();
        $this->sqlFormatter = new PostgreSQLSqlFormatter($this);
        $this->dmlBuilder = new PostgreSQLDmlBuilder($this);
        $this->ddlBuilder = new PostgreSQLDdlBuilder($this);
    }
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
        return $this->featureSupport->supportsLateralJoin();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsJoinInUpdateDelete(): bool
    {
        return $this->featureSupport->supportsJoinInUpdateDelete();
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
        return $this->dmlBuilder->buildInsertSql($table, $columns, $placeholders, $options);
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
        return $this->dmlBuilder->buildInsertSelectSql($table, $columns, $selectSql, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function formatSelectOptions(string $sql, array $options): string
    {
        return $this->sqlFormatter->formatSelectOptions($sql, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        return $this->dmlBuilder->buildUpsertClause($updateColumns, $defaultConflictTarget, $tableName);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMerge(): bool
    {
        return $this->featureSupport->supportsMerge();
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
        return $this->dmlBuilder->buildMergeSql($targetTable, $sourceSql, $onClause, $whenClauses);
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
     * Safely replace column references in expression.
     */
    protected function replaceColumnReferences(string $expression, string $column, string $replacement): string
    {
        $result = preg_replace_callback(
            '/\b' . preg_quote($column, '/') . '\b/i',
            static function ($matches) use ($expression, $replacement) {
                $pos = strpos($expression, $matches[0]);
                if ($pos === false) {
                    return $matches[0];
                }

                // Check if it's already qualified (has a dot or excluded prefix)
                $left = $pos > 0 ? substr($expression, max(0, $pos - 9), 9) : '';
                if (str_contains($left, '.') || stripos($left, 'excluded') !== false) {
                    return $matches[0];
                }

                return $replacement;
            },
            $expression
        );

        return $result ?? $expression;
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
        return $this->dmlBuilder->buildReplaceSql($table, $columns, $placeholders, $isMultiple);
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
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): Generator
    {
        // PostgreSQL uses native COPY which loads entire file at once
        yield $this->buildLoadCsvSql($table, $filePath, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string
    {
        return $this->sqlFormatter->formatJsonGet($col, $path, $asText);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string
    {
        return $this->sqlFormatter->formatJsonContains($col, $value, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        return $this->sqlFormatter->formatJsonSet($col, $path, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        return $this->sqlFormatter->formatJsonRemove($col, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonReplace(string $col, array|string $path, mixed $value): array
    {
        return $this->sqlFormatter->formatJsonReplace($col, $path, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonExists(string $col, array|string $path): string
    {
        return $this->sqlFormatter->formatJsonExists($col, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        return $this->sqlFormatter->formatJsonOrderExpr($col, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string
    {
        return $this->sqlFormatter->formatJsonLength($col, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string
    {
        return $this->sqlFormatter->formatJsonKeys($col, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonType(string $col, array|string|null $path = null): string
    {
        return $this->sqlFormatter->formatJsonType($col, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function formatIfNull(string $expr, mixed $default): string
    {
        return $this->sqlFormatter->formatIfNull($expr, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string
    {
        return $this->sqlFormatter->formatSubstring($source, $start, $length);
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurDate(): string
    {
        return $this->sqlFormatter->formatCurDate();
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurTime(): string
    {
        return $this->sqlFormatter->formatCurTime();
    }

    /**
     * {@inheritDoc}
     */
    public function formatYear(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatYear($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatMonth(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatMonth($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatDay(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatDay($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatHour(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatHour($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatMinute(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatMinute($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatSecond(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatSecond($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        return $this->sqlFormatter->formatInterval($expr, $value, $unit, $isAdd);
    }

    /**
     * {@inheritDoc}
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        return $this->sqlFormatter->formatGroupConcat($column, $separator, $distinct);
    }

    /**
     * {@inheritDoc}
     */
    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        return $this->sqlFormatter->formatTruncate($value, $precision);
    }

    /**
     * {@inheritDoc}
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        return $this->sqlFormatter->formatPosition($substring, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeft(string|RawValue $value, int $length): string
    {
        return $this->sqlFormatter->formatLeft($value, $length);
    }

    /**
     * {@inheritDoc}
     */
    public function formatRight(string|RawValue $value, int $length): string
    {
        return $this->sqlFormatter->formatRight($value, $length);
    }

    /**
     * {@inheritDoc}
     */
    public function formatRepeat(string|RawValue $value, int $count): string
    {
        return $this->sqlFormatter->formatRepeat($value, $count);
    }

    /**
     * {@inheritDoc}
     */
    public function formatReverse(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatReverse($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string
    {
        return $this->sqlFormatter->formatPad($value, $length, $padString, $isLeft);
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpMatch(string|RawValue $value, string $pattern): string
    {
        return $this->sqlFormatter->formatRegexpMatch($value, $pattern);
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        return $this->sqlFormatter->formatRegexpReplace($value, $pattern, $replacement);
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        return $this->sqlFormatter->formatRegexpExtract($value, $pattern, $groupIndex);
    }

    /**
     * {@inheritDoc}
     */
    public function formatDateOnly(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatDateOnly($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatTimeOnly(string|RawValue $value): string
    {
        return $this->sqlFormatter->formatTimeOnly($value);
    }

    /**
     * {@inheritDoc}
     */
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        return $this->sqlFormatter->formatFulltextMatch($columns, $searchTerm, $mode, $withQueryExpansion);
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
        return $this->sqlFormatter->formatWindowFunction($function, $args, $partitionBy, $orderBy, $frameClause);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFilterClause(): bool
    {
        return $this->featureSupport->supportsFilterClause();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        return $this->featureSupport->supportsDistinctOn();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        return $this->featureSupport->supportsMaterializedCte();
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
        return $this->ddlBuilder->buildCreateTableSql($table, $columns, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableSql(string $table): string
    {
        return $this->ddlBuilder->buildDropTableSql($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableIfExistsSql(string $table): string
    {
        return $this->ddlBuilder->buildDropTableIfExistsSql($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        return $this->ddlBuilder->buildAddColumnSql($table, $column, $schema);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropColumnSql(string $table, string $column): string
    {
        return $this->ddlBuilder->buildDropColumnSql($table, $column);
    }

    /**
     * {@inheritDoc}
     */
    public function buildAlterColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        return $this->ddlBuilder->buildAlterColumnSql($table, $column, $schema);
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string
    {
        return $this->ddlBuilder->buildRenameColumnSql($table, $oldName, $newName);
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateIndexSql(
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        ?string $where = null,
        ?array $includeColumns = null,
        array $options = []
    ): string {
        return $this->ddlBuilder->buildCreateIndexSql($name, $table, $columns, $unique, $where, $includeColumns, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        return $this->ddlBuilder->buildDropIndexSql($name, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string
    {
        return $this->ddlBuilder->buildCreateFulltextIndexSql($name, $table, $columns, $parser);
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string
    {
        return $this->ddlBuilder->buildCreateSpatialIndexSql($name, $table, $columns);
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameIndexSql(string $oldName, string $table, string $newName): string
    {
        return $this->ddlBuilder->buildRenameIndexSql($oldName, $table, $newName);
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameForeignKeySql(string $oldName, string $table, string $newName): string
    {
        return $this->ddlBuilder->buildRenameForeignKeySql($oldName, $table, $newName);
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
        return $this->ddlBuilder->buildAddForeignKeySql($name, $table, $columns, $refTable, $refColumns, $delete, $update);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropForeignKeySql(string $name, string $table): string
    {
        return $this->ddlBuilder->buildDropForeignKeySql($name, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddPrimaryKeySql(string $name, string $table, array $columns): string
    {
        return $this->ddlBuilder->buildAddPrimaryKeySql($name, $table, $columns);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropPrimaryKeySql(string $name, string $table): string
    {
        return $this->ddlBuilder->buildDropPrimaryKeySql($name, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddUniqueSql(string $name, string $table, array $columns): string
    {
        return $this->ddlBuilder->buildAddUniqueSql($name, $table, $columns);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropUniqueSql(string $name, string $table): string
    {
        return $this->ddlBuilder->buildDropUniqueSql($name, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddCheckSql(string $name, string $table, string $expression): string
    {
        return $this->ddlBuilder->buildAddCheckSql($name, $table, $expression);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropCheckSql(string $name, string $table): string
    {
        return $this->ddlBuilder->buildDropCheckSql($name, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameTableSql(string $table, string $newName): string
    {
        return $this->ddlBuilder->buildRenameTableSql($table, $newName);
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string
    {
        return $this->ddlBuilder->formatColumnDefinition($name, $schema);
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
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
        return $this->sqlFormatter->formatLimitOffset($sql, $limit, $offset);
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string
    {
        return $this->sqlFormatter->formatUnionSelect($selectSql, $isBaseQuery);
    }

    /**
     * {@inheritDoc}
     */
    public function needsUnionParentheses(): bool
    {
        return $this->sqlFormatter->needsUnionParentheses();
    }

    /**
     * {@inheritDoc}
     */
    public function formatGreatest(array $values): string
    {
        return $this->sqlFormatter->formatGreatest($values);
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeast(array $values): string
    {
        return $this->sqlFormatter->formatLeast($values);
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
        return $this->featureSupport->supportsLimitInExists();
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
        return $this->sqlFormatter->formatMaterializedCte($cteSql, $isMaterialized);
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
    public function extractErrorCode(PDOException $e): string
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
        return new PostgreSQLExplainParser();
    }

    /**
     * {@inheritDoc}
     */
    protected function buildCreateDatabaseSql(string $databaseName): string
    {
        $quotedName = $this->quoteIdentifier($databaseName);
        return "CREATE DATABASE {$quotedName}";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildDropDatabaseSql(string $databaseName): string
    {
        $quotedName = $this->quoteIdentifier($databaseName);
        return "DROP DATABASE IF EXISTS {$quotedName}";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildListDatabasesSql(): string
    {
        return 'SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname';
    }

    /**
     * {@inheritDoc}
     */
    protected function extractDatabaseNames(array $result): array
    {
        $names = [];
        foreach ($result as $row) {
            $name = $row['datname'] ?? null;
            if ($name !== null && is_string($name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * {@inheritDoc}
     */
    public function getDatabaseInfo(\tommyknocker\pdodb\PdoDb $db): array
    {
        $info = [];

        $dbName = $db->rawQueryValue('SELECT current_database()');
        if ($dbName !== null) {
            $info['current_database'] = $dbName;
        }

        $version = $db->rawQueryValue('SELECT version()');
        if ($version !== null) {
            $info['version'] = $version;
        }

        $encoding = $db->rawQueryValue('SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = current_database()');
        if ($encoding !== null) {
            $info['encoding'] = $encoding;
        }

        return $info;
    }

    /* ---------------- User Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function createUser(string $username, string $password, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // PostgreSQL doesn't use host in user creation
        // Check if user already exists
        if ($this->userExists($username, $host, $db)) {
            return true; // User already exists, consider it success
        }

        $quotedUsername = $this->quoteIdentifier($username);
        $quotedPassword = $db->rawQueryValue('SELECT quote_literal(?)', [$password]);
        if ($quotedPassword === null) {
            $quotedPassword = "'" . addslashes($password) . "'";
        }

        $sql = "CREATE USER {$quotedUsername} WITH PASSWORD {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // PostgreSQL doesn't use host in user deletion
        $quotedUsername = $this->quoteIdentifier($username);

        // Use IF EXISTS to avoid errors if user doesn't exist
        $sql = "DROP USER IF EXISTS {$quotedUsername}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function userExists(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // PostgreSQL doesn't use host in user checks
        $quotedUsername = $this->quoteIdentifier($username);

        $sql = 'SELECT COUNT(*) FROM pg_roles WHERE rolname = ?';
        $count = $db->rawQueryValue($sql, [$username]);
        return (int)$count > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function listUsers(\tommyknocker\pdodb\PdoDb $db): array
    {
        $sql = 'SELECT rolname FROM pg_roles WHERE rolcanlogin = true ORDER BY rolname';
        $result = $db->rawQuery($sql);

        $users = [];
        foreach ($result as $row) {
            $users[] = [
                'username' => $row['rolname'],
                'host' => null,
                'user_host' => $row['rolname'],
            ];
        }

        return $users;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): array
    {
        // PostgreSQL doesn't use host in user info
        $quotedUsername = $this->quoteIdentifier($username);

        $sql = 'SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolcanlogin FROM pg_roles WHERE rolname = ?';
        $user = $db->rawQueryOne($sql, [$username]);

        if (empty($user)) {
            return [];
        }

        $info = [
            'username' => $user['rolname'],
            'host' => null,
            'user_host' => $user['rolname'],
            'is_superuser' => $user['rolsuper'] === 't' || $user['rolsuper'] === true,
            'can_create_roles' => $user['rolcreaterole'] === 't' || $user['rolcreaterole'] === true,
            'can_create_databases' => $user['rolcreatedb'] === 't' || $user['rolcreatedb'] === true,
            'can_login' => $user['rolcanlogin'] === 't' || $user['rolcanlogin'] === true,
        ];

        // Get privileges
        try {
            $grantsSql = 'SELECT
                table_schema,
                table_name,
                privilege_type
            FROM information_schema.role_table_grants
            WHERE grantee = ?';
            $grants = $db->rawQuery($grantsSql, [$username]);
            $privileges = [];
            foreach ($grants as $grant) {
                $privileges[] = $grant;
            }
            $info['privileges'] = $privileges;
        } catch (\Throwable $e) {
            $info['privileges'] = [];
        }

        return $info;
    }

    /**
     * {@inheritDoc}
     */
    public function grantPrivileges(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        \tommyknocker\pdodb\PdoDb $db
    ): bool {
        // PostgreSQL doesn't use host in grants
        $quotedUsername = $this->quoteIdentifier($username);

        $target = '';
        if ($database !== null && $table !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);
            $quotedTable = $table === '*' ? '*' : $this->quoteIdentifier($table);
            $target = "{$quotedDb}.{$quotedTable}";
        } elseif ($database !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);

            // Check if privileges are database-level (CONNECT, CREATE, TEMPORARY)
            // or table-level (SELECT, INSERT, UPDATE, DELETE, etc.)
            $dbLevelPrivileges = ['CONNECT', 'CREATE', 'TEMPORARY'];
            $isDbLevel = false;
            foreach ($dbLevelPrivileges as $dbPriv) {
                if (stripos($privileges, $dbPriv) !== false) {
                    $isDbLevel = true;
                    break;
                }
            }

            if ($isDbLevel) {
                // For database-level privileges, use DATABASE syntax
                $target = "DATABASE {$quotedDb}";
            } else {
                // For table-level privileges (SELECT, INSERT, UPDATE, DELETE, etc.),
                // grant on all tables in the public schema
                $target = 'ALL TABLES IN SCHEMA public';
            }
        } else {
            // Server-level grants are not directly supported in PostgreSQL
            // Use ALL TABLES IN SCHEMA public as fallback
            $target = 'ALL TABLES IN SCHEMA public';
        }

        $sql = "GRANT {$privileges} ON {$target} TO {$quotedUsername}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function revokePrivileges(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        \tommyknocker\pdodb\PdoDb $db
    ): bool {
        // PostgreSQL doesn't use host in revokes
        $quotedUsername = $this->quoteIdentifier($username);

        $target = '';
        if ($database !== null && $table !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);
            $quotedTable = $table === '*' ? '*' : $this->quoteIdentifier($table);
            $target = "{$quotedDb}.{$quotedTable}";
        } elseif ($database !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);

            // Check if privileges are database-level (CONNECT, CREATE, TEMPORARY)
            // or table-level (SELECT, INSERT, UPDATE, DELETE, etc.)
            $dbLevelPrivileges = ['CONNECT', 'CREATE', 'TEMPORARY'];
            $isDbLevel = false;
            foreach ($dbLevelPrivileges as $dbPriv) {
                if (stripos($privileges, $dbPriv) !== false) {
                    $isDbLevel = true;
                    break;
                }
            }

            if ($isDbLevel) {
                // For database-level privileges, use DATABASE syntax
                $target = "DATABASE {$quotedDb}";
            } else {
                // For table-level privileges (SELECT, INSERT, UPDATE, DELETE, etc.),
                // revoke from all tables in the public schema
                $target = 'ALL TABLES IN SCHEMA public';
            }
        } else {
            // Server-level revokes are not directly supported in PostgreSQL
            // Use ALL TABLES IN SCHEMA public as fallback
            $target = 'ALL TABLES IN SCHEMA public';
        }

        $sql = "REVOKE {$privileges} ON {$target} FROM {$quotedUsername}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function changeUserPassword(string $username, string $newPassword, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // PostgreSQL doesn't use host in password changes
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedPassword = $db->rawQueryValue('SELECT quote_literal(?)', [$newPassword]);
        if ($quotedPassword === null) {
            $quotedPassword = "'" . addslashes($newPassword) . "'";
        }

        $sql = "ALTER USER {$quotedUsername} WITH PASSWORD {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dumpSchema(\tommyknocker\pdodb\PdoDb $db, ?string $table = null): string
    {
        $output = [];
        $tables = [];

        if ($table !== null) {
            $tables = [$table];
        } else {
            $rows = $db->rawQuery(
                'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = \'public\' ORDER BY tablename'
            );
            foreach ($rows as $row) {
                $tables[] = (string)$row['tablename'];
            }
        }

        foreach ($tables as $tableName) {
            // Build CREATE TABLE from information_schema
            $quotedTable = $this->quoteTable($tableName);
            $columns = $db->rawQuery(
                "SELECT column_name, data_type, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = ?
                 ORDER BY ordinal_position",
                [$tableName]
            );

            if (empty($columns)) {
                continue;
            }

            $colDefs = [];
            foreach ($columns as $col) {
                $colName = $this->quoteIdentifier((string)$col['column_name']);
                $dataType = (string)$col['data_type'];
                $nullable = (string)$col['is_nullable'] === 'YES';
                $default = $col['column_default'] !== null ? (string)$col['column_default'] : null;

                // Build type with length/precision
                // PostgreSQL doesn't support precision/scale for integer, bigint, smallint, serial types
                $typesWithoutPrecision = ['integer', 'bigint', 'smallint', 'serial', 'bigserial', 'smallserial', 'real', 'double precision', 'money'];
                if ($col['character_maximum_length'] !== null) {
                    $dataType .= '(' . (int)$col['character_maximum_length'] . ')';
                } elseif ($col['numeric_precision'] !== null && !in_array(strtolower($dataType), $typesWithoutPrecision, true)) {
                    $dataType .= '(' . (int)$col['numeric_precision'];
                    if ($col['numeric_scale'] !== null) {
                        $dataType .= ',' . (int)$col['numeric_scale'];
                    }
                    $dataType .= ')';
                }

                $def = $colName . ' ' . $dataType;
                if (!$nullable) {
                    $def .= ' NOT NULL';
                }
                if ($default !== null) {
                    $def .= ' DEFAULT ' . $default;
                }
                $colDefs[] = $def;
            }

            // Check for sequences that need to be created before the table
            $sequences = [];
            foreach ($columns as $col) {
                $default = $col['column_default'] !== null ? (string)$col['column_default'] : null;
                if ($default !== null && preg_match("/nextval\('([^']+)'::regclass\)/", $default, $matches)) {
                    $seqName = $matches[1];
                    if (!in_array($seqName, $sequences, true)) {
                        $sequences[] = $seqName;
                    }
                }
            }

            // Create sequences before table
            foreach ($sequences as $seqName) {
                $quotedSeq = $this->quoteIdentifier($seqName);
                $output[] = "CREATE SEQUENCE IF NOT EXISTS {$quotedSeq};";
            }

            $output[] = "CREATE TABLE {$quotedTable} (\n  " . implode(",\n  ", $colDefs) . "\n);";

            // Get indexes (exclude primary key indexes as they're already in CREATE TABLE)
            $indexRows = $db->rawQuery(
                "SELECT i.indexname, i.indexdef
                 FROM pg_indexes i
                 INNER JOIN pg_class idx ON idx.relname = i.indexname
                 INNER JOIN pg_index pgidx ON pgidx.indexrelid = idx.oid
                 INNER JOIN pg_class t ON pgidx.indrelid = t.oid
                 WHERE i.schemaname = 'public' AND t.relname = ? AND NOT pgidx.indisprimary
                 ORDER BY i.indexname",
                [$tableName]
            );
            foreach ($indexRows as $idxRow) {
                $idxDef = (string)$idxRow['indexdef'];
                if (str_contains($idxDef, 'CREATE UNIQUE INDEX')) {
                    $output[] = $idxDef . ';';
                } elseif (str_contains($idxDef, 'CREATE INDEX')) {
                    $output[] = $idxDef . ';';
                }
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * {@inheritDoc}
     */
    public function dumpData(\tommyknocker\pdodb\PdoDb $db, ?string $table = null): string
    {
        $output = [];
        $tables = [];

        if ($table !== null) {
            $tables = [$table];
        } else {
            $rows = $db->rawQuery(
                'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = \'public\' ORDER BY tablename'
            );
            foreach ($rows as $row) {
                $tables[] = (string)$row['tablename'];
            }
        }

        foreach ($tables as $tableName) {
            $quotedTable = $this->quoteTable($tableName);
            $rows = $db->rawQuery("SELECT * FROM {$quotedTable}");

            if (empty($rows)) {
                continue;
            }

            $columns = array_keys($rows[0]);
            $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
            $columnsList = implode(', ', $quotedColumns);

            $batchSize = 100;
            $batch = [];
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $col) {
                    $val = $row[$col];
                    if ($val === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($val) || is_float($val)) {
                        $values[] = (string)$val;
                    } else {
                        $values[] = "'" . str_replace(["'", '\\'], ["''", '\\\\'], (string)$val) . "'";
                    }
                }
                $batch[] = '(' . implode(', ', $values) . ')';

                if (count($batch) >= $batchSize) {
                    $output[] = "INSERT INTO {$quotedTable} ({$columnsList}) VALUES\n" . implode(",\n", $batch) . ';';
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                $output[] = "INSERT INTO {$quotedTable} ({$columnsList}) VALUES\n" . implode(",\n", $batch) . ';';
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreFromSql(\tommyknocker\pdodb\PdoDb $db, string $sql, bool $continueOnError = false): void
    {
        // PostgreSQL uses dollar-quoted strings, need more sophisticated parsing
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $inDollarQuote = false;
        $dollarTag = '';

        $lines = explode("\n", $sql);
        foreach ($lines as $line) {
            // Skip comment-only lines
            $trimmedLine = trim($line);
            if ($trimmedLine === '' || preg_match('/^--/', $trimmedLine)) {
                continue;
            }

            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                $nextChar = $i + 1 < $length ? $line[$i + 1] : '';

                // Handle comments (-- style)
                if (!$inString && !$inDollarQuote && $char === '-' && $nextChar === '-') {
                    // Skip rest of line (comment)
                    break;
                }

                // Check for dollar-quoted strings ($tag$ ... $tag$)
                if (!$inString && !$inDollarQuote && $char === '$') {
                    $tagEnd = strpos($line, '$', $i + 1);
                    if ($tagEnd !== false) {
                        $dollarTag = substr($line, $i, $tagEnd - $i + 1);
                        $inDollarQuote = true;
                        $current .= $dollarTag;
                        $i = $tagEnd;
                        continue;
                    }
                }

                if ($inDollarQuote) {
                    $tagLen = strlen($dollarTag);
                    if (substr($line, $i, $tagLen) === $dollarTag) {
                        $inDollarQuote = false;
                        $current .= $dollarTag;
                        $i += $tagLen - 1;
                        $dollarTag = '';
                        continue;
                    }
                    $current .= $char;
                    continue;
                }

                if (!$inString && ($char === '"' || $char === "'")) {
                    $inString = true;
                    $stringChar = $char;
                    $current .= $char;
                } elseif ($inString && $char === $stringChar) {
                    if ($nextChar === $stringChar) {
                        $current .= $char . $nextChar;
                        $i++;
                    } else {
                        $inString = false;
                        $stringChar = '';
                        $current .= $char;
                    }
                } elseif (!$inString && $char === ';') {
                    $stmt = trim($current);
                    if ($stmt !== '' && !preg_match('/^--/', $stmt)) {
                        $statements[] = $stmt;
                    }
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
        }

        $stmt = trim($current);
        if ($stmt !== '' && !preg_match('/^--/', $stmt)) {
            $statements[] = $stmt;
        }

        $errors = [];
        foreach ($statements as $stmt) {
            try {
                $db->rawQuery($stmt);
            } catch (\Throwable $e) {
                if (!$continueOnError) {
                    throw new \tommyknocker\pdodb\exceptions\ResourceException('Failed to execute SQL statement: ' . $e->getMessage() . "\nStatement: " . substr($stmt, 0, 200));
                }
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors) && $continueOnError) {
            throw new \tommyknocker\pdodb\exceptions\ResourceException('Restore completed with ' . count($errors) . ' errors. First error: ' . $errors[0]);
        }
    }
}
