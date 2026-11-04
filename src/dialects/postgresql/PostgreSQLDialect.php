<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\postgresql;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\catalog\CatalogQueriesInterface;
use tommyknocker\pdodb\dialects\catalog\PgsqlCatalogQueriesTrait;
use tommyknocker\pdodb\dialects\config\ConfigOptimizerStrategy;
use tommyknocker\pdodb\dialects\config\PostgreSQLConfigStrategy;
use tommyknocker\pdodb\dialects\ConnectionInitHandler;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\InsertId;
use tommyknocker\pdodb\dialects\InsertStatement;
use tommyknocker\pdodb\dialects\roles\DialectInsertReturningInterface;
use tommyknocker\pdodb\dialects\roles\DialectJsonNumberComparisonInterface;
use tommyknocker\pdodb\dialects\SqlScriptSplitter;
use tommyknocker\pdodb\dialects\traits\OnConflictUpsertTrait;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\outbox\claim\OutboxClaimStrategy;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\PostgreSQLExplainParser;
use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class PostgreSQLDialect extends DialectAbstract implements CatalogQueriesInterface, DialectInsertReturningInterface, DialectJsonNumberComparisonInterface
{
    use OnConflictUpsertTrait;

    use PgsqlCatalogQueriesTrait;
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

    public function foldUnquotedIdentifier(string $identifier): string
    {
        return strtolower($identifier);
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
        $this->assertDsnSafe($params, ['host', 'dbname', 'hostaddr', 'service', 'port', 'options', 'sslmode', 'sslkey', 'sslcert', 'sslrootcert', 'application_name', 'connect_timeout', 'target_session_attrs']);

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
     *
     * A backslash is an escape character unless standard_conforming_strings is on, so values containing one use the
     * E'' form, which reads the same under both settings.
     */
    public function quoteStringLiteral(string $value): string
    {
        $value = str_replace("\0", '', $value);
        if (str_contains($value, '\\')) {
            return "E'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(mixed $name): string
    {
        return $name instanceof RawValue ? $name->getValue() : '"' . str_replace('"', '""', (string)$name) . '"';
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
    public function formatConcatExpression(array $parts): string
    {
        // PostgreSQL uses || operator for concatenation
        return implode(' || ', $parts);
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

        $trimmedDif = $this->assertSafeNowDiff(trim($diff));
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
        return 'SELECT to_regclass(' . $this->quoteStringLiteral($table) . ') IS NOT NULL AS exists';
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return 'SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_name = ' . $this->quoteStringLiteral($table);
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
    public function formatJsonNumberComparison(string $col, array|string $path, string $operator, string $placeholder): string
    {
        return $this->sqlFormatter->formatJsonNumberComparison($col, $path, $operator, $placeholder);
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
    public function formatRegexpLike(string|RawValue $value, string $pattern): string
    {
        return $this->sqlFormatter->formatRegexpLike($value, $pattern);
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
        return 'SELECT
            indexname as index_name,
            tablename as table_name,
            indexdef as definition
            FROM pg_indexes
            WHERE tablename = ' . $this->quoteStringLiteral($table);
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
            AND tc.table_name = " . $this->quoteStringLiteral($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        return 'SELECT
            tc.constraint_name,
            tc.constraint_type,
            tc.table_name,
            kcu.column_name
            FROM information_schema.table_constraints tc
            LEFT JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
            WHERE tc.table_name = ' . $this->quoteStringLiteral($table);
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
    public function getPrimaryKeyColumns(PdoDb $db, string $table): array
    {
        // PostgreSQL: use constraints instead of indexes
        try {
            $constraints = $db->constraints($table);
            $constraintName = null;

            // Find PRIMARY KEY constraint name
            foreach ($constraints as $constraint) {
                $type = $constraint['constraint_type'] ?? $constraint['CONSTRAINT_TYPE'] ?? null;
                if (is_string($type) && strtoupper($type) === 'PRIMARY KEY') {
                    $constraintName = $constraint['constraint_name'] ?? $constraint['CONSTRAINT_NAME'] ?? null;
                    break;
                }
            }

            if ($constraintName === null) {
                return [];
            }

            // Query key_column_usage for all PK columns in proper order
            // This works for both single and composite keys
            $quotedTable = $this->quoteTable($table);
            $quotedConstraint = $this->quoteIdentifier($constraintName);
            $sql = "SELECT column_name
                    FROM information_schema.key_column_usage
                    WHERE table_name = {$quotedTable}
                    AND constraint_name = {$quotedConstraint}
                    ORDER BY ordinal_position";

            try {
                $result = $db->rawQuery($sql);
                $columns = [];
                foreach ($result as $row) {
                    $col = $row['column_name'] ?? $row['COLUMN_NAME'] ?? null;
                    if (is_string($col)) {
                        $columns[] = $col;
                    }
                }
                return $columns;
            } catch (\Exception $e) {
                // Fall back: collect columns from constraints (may not be in order)
                $pkColumns = [];
                foreach ($constraints as $constraint) {
                    $type = $constraint['constraint_type'] ?? $constraint['CONSTRAINT_TYPE'] ?? null;
                    $constName = $constraint['constraint_name'] ?? $constraint['CONSTRAINT_NAME'] ?? null;
                    if (is_string($type) && strtoupper($type) === 'PRIMARY KEY' &&
                        is_string($constName) && $constName === $constraintName) {
                        $column = $constraint['column_name'] ?? $constraint['COLUMN_NAME'] ?? null;
                        if (is_string($column)) {
                            $pkColumns[] = $column;
                        }
                    }
                }
                return array_unique($pkColumns);
            }
        } catch (\Exception $e) {
            return [];
        }
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
    public function getDatabaseInfo(PdoDb $db): array
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
    public function createUser(string $username, string $password, ?string $host, PdoDb $db): bool
    {
        // PostgreSQL doesn't use host in user creation
        // Check if user already exists
        if ($this->userExists($username, $host, $db)) {
            return true; // User already exists, consider it success
        }

        $quotedUsername = $this->quoteIdentifier($username);
        $quotedPassword = $db->rawQueryValue('SELECT quote_literal(?)', [$password]);
        if ($quotedPassword === null) {
            $quotedPassword = $this->quoteStringLiteral($password);
        }

        $sql = "CREATE USER {$quotedUsername} WITH PASSWORD {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(string $username, ?string $host, PdoDb $db): bool
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
    public function userExists(string $username, ?string $host, PdoDb $db): bool
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
    public function listUsers(PdoDb $db): array
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
    public function getUserInfo(string $username, ?string $host, PdoDb $db): array
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
        PdoDb $db
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
        PdoDb $db
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
    public function changeUserPassword(string $username, string $newPassword, ?string $host, PdoDb $db): bool
    {
        // PostgreSQL doesn't use host in password changes
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedPassword = $db->rawQueryValue('SELECT quote_literal(?)', [$newPassword]);
        if ($quotedPassword === null) {
            $quotedPassword = $this->quoteStringLiteral($newPassword);
        }

        $sql = "ALTER USER {$quotedUsername} WITH PASSWORD {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dumpSchema(PdoDb $db, ?string $table = null, bool $dropTables = true): string
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

            if ($dropTables) {
                $output[] = "DROP TABLE IF EXISTS {$quotedTable} CASCADE;";
            }

            // Build CREATE TABLE from information_schema
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
     * Get dialect-specific DDL query builder.
     *
     * @param ConnectionInterface $connection
     * @param string $prefix
     *
     * @return DdlQueryBuilder
     */
    public function getDdlQueryBuilder(ConnectionInterface $connection, string $prefix = ''): DdlQueryBuilder
    {
        return new PostgreSQLDdlQueryBuilder($connection, $prefix);
    }

    /* ---------------- Monitoring ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getActiveQueries(PdoDb $db): array
    {
        $sql = "SELECT pid, usename, application_name, datname, state, query_start, state_change, wait_event_type, wait_event, query
                FROM pg_stat_activity
                WHERE state != 'idle' AND query IS NOT NULL AND query != ''";
        $rows = $db->rawQuery($sql);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'pid' => $this->toString($row['pid'] ?? ''),
                'user' => $this->toString($row['usename'] ?? ''),
                'application' => $this->toString($row['application_name'] ?? ''),
                'database' => $this->toString($row['datname'] ?? ''),
                'state' => $this->toString($row['state'] ?? ''),
                'query_start' => $this->toString($row['query_start'] ?? ''),
                'wait_event' => $this->toString($row['wait_event'] ?? ''),
                'query' => $this->toString($row['query'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveConnections(PdoDb $db): array
    {
        $rows = $db->rawQuery('SELECT pid, usename, application_name, datname, state, backend_start, state_change, wait_event_type, wait_event FROM pg_stat_activity');
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'pid' => $this->toString($row['pid'] ?? ''),
                'user' => $this->toString($row['usename'] ?? ''),
                'application' => $this->toString($row['application_name'] ?? ''),
                'database' => $this->toString($row['datname'] ?? ''),
                'state' => $this->toString($row['state'] ?? ''),
                'backend_start' => $this->toString($row['backend_start'] ?? ''),
                'wait_event' => $this->toString($row['wait_event'] ?? ''),
            ];
        }

        // Get connection limits
        $maxConn = $db->rawQueryValue("SELECT setting FROM pg_settings WHERE name = 'max_connections'");
        $current = count($result);
        $maxVal = $maxConn ?? 0;
        $max = is_int($maxVal) ? $maxVal : (is_string($maxVal) ? (int)$maxVal : 0);

        return [
            'connections' => $result,
            'summary' => [
                'current' => $current,
                'max' => $max,
                'usage_percent' => $max > 0 ? round(($current / $max) * 100, 2) : 0,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getServerMetrics(PdoDb $db): array
    {
        $metrics = [];

        try {
            // Get version
            $version = $db->rawQueryValue('SELECT version()');
            $metrics['version'] = is_string($version) ? $version : 'unknown';

            // Get database stats
            $dbStats = $db->rawQuery('
                SELECT
                    numbackends as connections,
                    xact_commit as commits,
                    xact_rollback as rollbacks,
                    blks_read as blocks_read,
                    blks_hit as blocks_hit,
                    tup_returned as tuples_returned,
                    tup_fetched as tuples_fetched,
                    tup_inserted as tuples_inserted,
                    tup_updated as tuples_updated,
                    tup_deleted as tuples_deleted
                FROM pg_stat_database
                WHERE datname = current_database()
            ');

            if (!empty($dbStats)) {
                $stats = $dbStats[0];
                foreach ($stats as $key => $value) {
                    $metrics[$key] = is_int($value) ? $value : (is_string($value) ? (int)$value : 0);
                }
            }

            // Get server start time (uptime)
            $startTime = $db->rawQueryValue('SELECT pg_postmaster_start_time()');
            if ($startTime !== null) {
                $startTimestamp = is_string($startTime) ? strtotime($startTime) : 0;
                if ($startTimestamp > 0) {
                    $metrics['uptime_seconds'] = time() - $startTimestamp;
                }
            }
        } catch (\Throwable $e) {
            // Return empty metrics on error
            $metrics['error'] = $e->getMessage();
        }

        return $metrics;
    }

    /**
     * {@inheritDoc}
     */
    public function configOptimizerStrategy(): ?ConfigOptimizerStrategy
    {
        return new PostgreSQLConfigStrategy();
    }

    /**
     * {@inheritDoc}
     */
    public function getServerVariables(PdoDb $db): array
    {
        try {
            $rows = $db->rawQuery('SELECT name, setting as value FROM pg_settings ORDER BY name');
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'name' => $row['name'] ?? '',
                    'value' => $row['value'] ?? '',
                ];
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function extractEnumValues(array $column, PdoDb $db, string $tableName, string $columnName): array
    {
        $type = $column['Type'] ?? $column['data_type'] ?? $column['type'] ?? '';
        if (!is_string($type)) {
            return [];
        }

        // PostgreSQL: ENUM is a custom type
        // data_type is 'USER-DEFINED' for ENUM, need to get actual type name from udt_name
        $udtName = $column['udt_name'] ?? null;
        if ($udtName !== null && is_string($udtName) && str_ends_with($udtName, '_enum')) {
            return $this->extractPostgresEnumValues($udtName, $db);
        }

        // If udt_name is not available, try to query it
        if ($type === 'USER-DEFINED') {
            try {
                $typeInfo = $db->rawQueryOne(
                    "SELECT udt_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?",
                    [$tableName, $columnName]
                );
                if ($typeInfo !== null && isset($typeInfo['udt_name']) && is_string($typeInfo['udt_name']) && str_ends_with($typeInfo['udt_name'], '_enum')) {
                    return $this->extractPostgresEnumValues($typeInfo['udt_name'], $db);
                }
            } catch (\Exception $e) {
                // Ignore, fall through to empty array
            }
        }

        // Fallback: if type ends with _enum
        if (str_ends_with($type, '_enum')) {
            return $this->extractPostgresEnumValues($type, $db);
        }

        return [];
    }

    /**
     * Extract ENUM values from PostgreSQL custom type.
     *
     * @param string $enumTypeName ENUM type name (e.g., "test_users_status_enum")
     * @param PdoDb $db Database instance
     *
     * @return array<int, string>
     */
    protected function extractPostgresEnumValues(string $enumTypeName, PdoDb $db): array
    {
        try {
            // Query PostgreSQL system catalog to get ENUM values
            $query = '
                SELECT enumlabel as value
                FROM pg_enum
                JOIN pg_type ON pg_enum.enumtypid = pg_type.oid
                WHERE pg_type.typname = ?
                ORDER BY pg_enum.enumsortorder
            ';
            $result = $db->rawQuery($query, [$enumTypeName]);
            $values = [];
            foreach ($result as $row) {
                $value = $row['value'] ?? $row['VALUE'] ?? null;
                if ($value !== null && is_string($value)) {
                    $values[] = $value;
                }
            }
            return $values;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getSlowQueries(PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        // Try pg_stat_statements first
        $hasExtension = false;

        try {
            $ext = $db->rawQueryValue("SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_stat_statements'");
            $extVal = $ext ?? 0;
            $extInt = is_int($extVal) ? $extVal : (is_string($extVal) ? (int)$extVal : 0);
            $hasExtension = $extInt > 0;
        } catch (\Exception $e) {
            // Extension not available
        }

        if ($hasExtension) {
            $sql = 'SELECT query, calls, total_exec_time, mean_exec_time, max_exec_time
                    FROM pg_stat_statements
                    WHERE mean_exec_time >= ?
                    ORDER BY mean_exec_time DESC
                    LIMIT ?';
            $rows = $db->rawQuery($sql, [$thresholdSeconds * 1000, $limit]); // PostgreSQL uses milliseconds
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'query' => $this->toString($row['query'] ?? ''),
                    'calls' => $this->toString($row['calls'] ?? ''),
                    'total_time' => $this->toString($row['total_exec_time'] ?? '') . 'ms',
                    'avg_time' => $this->toString($row['mean_exec_time'] ?? '') . 'ms',
                    'max_time' => $this->toString($row['max_exec_time'] ?? '') . 'ms',
                ];
            }
            return $result;
        }

        // Fallback to pg_stat_activity
        $sql = "SELECT pid, usename, datname, state, query_start, now() - query_start AS duration, query
                FROM pg_stat_activity
                WHERE state != 'idle' AND query IS NOT NULL AND query != ''
                AND (now() - query_start) >= INTERVAL '1 second' * ?
                ORDER BY duration DESC
                LIMIT ?";
        $rows = $db->rawQuery($sql, [$thresholdSeconds, $limit]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'pid' => $this->toString($row['pid'] ?? ''),
                'user' => $this->toString($row['usename'] ?? ''),
                'database' => $this->toString($row['datname'] ?? ''),
                'duration' => $this->toString($row['duration'] ?? ''),
                'query' => $this->toString($row['query'] ?? ''),
            ];
        }
        return $result;
    }

    /* ---------------- Table Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function listTables(PdoDb $db, ?string $schema = null): array
    {
        $schemaName = $schema ?? 'public';
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $db->rawQuery(
            'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = :schema ORDER BY tablename',
            [':schema' => $schemaName]
        );
        /** @var array<int, string> $names */
        $names = array_map(static fn (array $r): string => (string)$r['tablename'], $rows);
        return $names;
    }

    /* ---------------- Error Handling ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getRetryableErrorCodes(): array
    {
        return [
            '08006',
            '08003',
            '08001',
            '40P01',
            '55P03',
            '53300',
            '57014',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getErrorDescription(int|string $errorCode): string
    {
        $descriptions = [
            '08000' => 'Connection exception',
            '08001' => 'SQL server connection failure',
            '08003' => 'Connection does not exist',
            '08006' => 'Connection failure',
            '08007' => 'Transaction resolution unknown',
            '28P01' => 'Authentication failed',
            '28P02' => 'Invalid password',
            '28P03' => 'Invalid authorization specification',
            '28000' => 'Invalid authorization specification',
            '3D000' => 'Database does not exist',
            '42P01' => 'Undefined table',
            '42703' => 'Undefined column',
            '42601' => 'Syntax error',
            '42501' => 'Insufficient privilege',
            '23505' => 'Unique constraint violation',
            '23503' => 'Foreign key violation',
            '23502' => 'Not null violation',
            '23514' => 'Check constraint violation',
            '23506' => 'Exclusion violation',
            '25P02' => 'Transaction is aborted',
            '25P03' => 'Transaction is not in progress',
            '40001' => 'Serialization failure',
            '40P01' => 'Deadlock detected',
            '55P03' => 'Lock not available',
            '53300' => 'Too many connections',
            '53400' => 'Configuration file error',
            '57014' => 'Query canceled',
        ];

        return $descriptions[(string) $errorCode] ?? 'Unknown error';
    }

    /* ---------------- Configuration ---------------- */

    /**
     * {@inheritDoc}
     */
    public function buildConfigFromEnv(array $envVars): array
    {
        $config = parent::buildConfigFromEnv($envVars);

        // PostgreSQL requires 'dbname' parameter
        if (isset($envVars['PDODB_DATABASE'])) {
            $config['dbname'] = $envVars['PDODB_DATABASE'];
        }

        // PostgreSQL specific options
        if (isset($envVars['PDODB_SSLMODE'])) {
            $config['sslmode'] = $envVars['PDODB_SSLMODE'];
        }
        if (isset($envVars['PDODB_SSLKEY'])) {
            $config['sslkey'] = $envVars['PDODB_SSLKEY'];
        }
        if (isset($envVars['PDODB_SSLCERT'])) {
            $config['sslcert'] = $envVars['PDODB_SSLCERT'];
        }
        if (isset($envVars['PDODB_SSLROOTCERT'])) {
            $config['sslrootcert'] = $envVars['PDODB_SSLROOTCERT'];
        }
        if (isset($envVars['PDODB_APPLICATION_NAME'])) {
            $config['application_name'] = $envVars['PDODB_APPLICATION_NAME'];
        }
        if (isset($envVars['PDODB_CONNECT_TIMEOUT'])) {
            $config['connect_timeout'] = (int)$envVars['PDODB_CONNECT_TIMEOUT'];
        }
        if (isset($envVars['PDODB_HOSTADDR'])) {
            $config['hostaddr'] = $envVars['PDODB_HOSTADDR'];
        }
        if (isset($envVars['PDODB_SERVICE'])) {
            $config['service'] = $envVars['PDODB_SERVICE'];
        }
        if (isset($envVars['PDODB_TARGET_SESSION_ATTRS'])) {
            $config['target_session_attrs'] = $envVars['PDODB_TARGET_SESSION_ATTRS'];
        }
        if (isset($envVars['PDODB_OPTIONS'])) {
            $config['options'] = $envVars['PDODB_OPTIONS'];
        }

        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeConfigParams(array $config): array
    {
        $config = parent::normalizeConfigParams($config);

        // PostgreSQL requires 'dbname' parameter
        if (isset($config['database']) && !isset($config['dbname'])) {
            $config['dbname'] = $config['database'];
        }

        return $config;
    }

    /**
     * Helper method to convert value to string.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function toString(mixed $value): string
    {
        return (string)$value;
    }

    /**
     * {@inheritDoc}
     */
    public function killQuery(PdoDb $db, int|string $processId): bool
    {
        try {
            $pid = is_int($processId) ? $processId : (int)$processId;
            $result = $db->rawQueryValue("SELECT pg_terminate_backend({$pid})");
            return (bool)$result;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function buildColumnSearchCondition(
        string $columnName,
        string $searchTerm,
        array $columnMetadata,
        bool $searchInJson,
        array &$params
    ): ?string {
        $isJson = $this->isJsonColumn($columnMetadata);
        $isArray = $this->isArrayColumn($columnMetadata);
        $isNumeric = $this->isNumericColumn($columnMetadata);

        $paramKey = ':search_' . count($params);
        $params[$paramKey] = '%' . $searchTerm . '%';

        if ($isJson && $searchInJson) {
            // PostgreSQL: column::text ILIKE pattern (case-insensitive)
            return "({$columnName}::text ILIKE {$paramKey})";
        }

        if ($isArray && $searchInJson) {
            // PostgreSQL: column::text ILIKE pattern
            return "({$columnName}::text ILIKE {$paramKey})";
        }

        if ($isNumeric) {
            if (is_numeric($searchTerm)) {
                $exactParamKey = ':exact_' . count($params);
                $params[$exactParamKey] = $searchTerm;
                return "({$columnName} = {$exactParamKey} OR {$columnName}::text ILIKE {$paramKey})";
            }
            return "({$columnName}::text ILIKE {$paramKey})";
        }

        // Text columns: use ILIKE for case-insensitive search
        return "({$columnName} ILIKE {$paramKey})";
    }

    /**
     * {@inheritDoc}
     *
     * `lastval()` is the last sequence value of the whole session, whatever table it belonged to, and `currval()` is
     * only meaningful for a row this statement inserted. The id is therefore read only when it can be trusted:
     *  - the value the insert supplied for the table's key column, when it supplied one;
     *  - otherwise `currval()` of the sequence behind the key column, when the table's primary key is that one column
     *    (a table without a primary key uses its first serial or identity column).
     * A key that is not a sequence (UUID, text, composite) and a secondary counter column are not ids. A skipped
     * `ON CONFLICT DO NOTHING` has no id, and neither has a conflicting `DO UPDATE` unless the key was supplied: the
     * sequence advanced for the attempt, so `currval()` would name a row that does not exist.
     */
    public function extractInsertId(PDOStatement $stmt, string $sql, array $params): int|string|false|null
    {
        if ($this->pdo === null) {
            return null;
        }

        $insert = InsertStatement::parse($sql);
        if ($insert === null) {
            return null;
        }

        // The row has no id to report. Answering false here, rather than null, keeps the caller from asking lastval()
        $noId = false;

        if ($stmt->rowCount() === 0) {
            return $noId; // nothing was inserted (ON CONFLICT DO NOTHING)
        }
        $upsert = preg_match('/\bON\s+CONFLICT\b/i', $insert->tail) === 1;

        // The cache can be stale after DDL (a column made serial or identity later): on a failed currval() look the table
        // up again once
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $generated = null;
            $sequences = $this->inSavepoint(fn (): array => $this->keySequencesOf($insert->qualifiedTable)) ?? [];
            foreach ($sequences as $candidate) {
                if ($insert->positionOf($candidate['column']) !== null) {
                    return $insert->suppliedId($candidate['column'], $params) ?? $noId;
                }
                $generated ??= $candidate['sequence'];
            }
            if ($generated === null || $upsert) {
                return $noId;
            }

            $pdo = $this->pdo;
            $id = $this->inSavepoint(static function () use ($pdo, $generated): mixed {
                $statement = $pdo->prepare('SELECT currval(?::oid::regclass)');
                $statement->execute([$generated]);

                return $statement->fetchColumn();
            });
            if (is_string($id) || is_int($id)) {
                return InsertId::normalize($id) ?? $noId;
            }
            unset($this->keySequences[$insert->qualifiedTable]);
        }

        return $noId;
    }

    /**
     * Run catalog statements that may fail (an unknown sequence, currval() before nextval()) without aborting the
     * caller's transaction. Null when they fail.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T|null
     */
    private function inSavepoint(callable $work): mixed
    {
        if ($this->pdo === null) {
            return null;
        }

        if (!$this->pdo->inTransaction()) {
            try {
                return $work();
            } catch (PDOException) {
                return null;
            }
        }

        $this->pdo->exec('SAVEPOINT pdodb_insert_id');

        try {
            $result = $work();
            $this->pdo->exec('RELEASE SAVEPOINT pdodb_insert_id');

            return $result;
        } catch (PDOException) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT pdodb_insert_id');
            $this->pdo->exec('RELEASE SAVEPOINT pdodb_insert_id');

            return null;
        }
    }

    /**
     * @var array<string, array{oid: string, primaryKey: ?string, sequences: list<array{column: string, sequence: string}>}> What
     *                                                                                                                       is known of a
     *                                                                                                                       table, by the
     *                                                                                                                       name as written
     */
    private array $keySequences = [];

    /**
     * {@inheritDoc}
     *
     * Appends `RETURNING <primary key>` when the table has a single-column primary key, so the key comes from the
     * statement itself: the new row, the row an upsert updated, or no row for a skipped `ON CONFLICT DO NOTHING`.
     */
    public function insertIdReturningSql(string $sql): ?string
    {
        if ($this->pdo === null) {
            return null;
        }
        $insert = InsertStatement::parse($sql);
        if ($insert === null || preg_match('/\bRETURNING\b/i', $insert->tail) === 1) {
            return null;
        }

        $primaryKey = $this->inSavepoint(fn (): ?string => $this->tableKeyOf($insert->qualifiedTable)['primaryKey']);
        if ($primaryKey === null) {
            return null;
        }

        return rtrim($sql, " \t\n\r;") . ' RETURNING ' . $this->quoteIdentifier($primaryKey);
    }

    /**
     * @return list<array{column: string, sequence: string}>
     */
    private function keySequencesOf(string $qualifiedTable): array
    {
        return $this->tableKeyOf($qualifiedTable)['sequences'];
    }

    /**
     * The single-column primary key of a table and its key sequences, found once per relation.
     *
     * The table is resolved to its OID on every call, so the same spelling (`t`) that means another relation after a
     * change of `search_path` is looked up again. A sequence is named by OID for the same reason.
     *
     * @return array{primaryKey: ?string, sequences: list<array{column: string, sequence: string}>}
     */
    private function tableKeyOf(string $qualifiedTable): array
    {
        $none = ['primaryKey' => null, 'sequences' => []];
        if ($this->pdo === null) {
            return $none;
        }

        // One round trip answers both questions. The OID says which relation the name means now (a changed search_path is
        // another table). The primary key's name is read every time, not cached: RENAME COLUMN keeps the OID, and the name
        // is written into the INSERT.
        $lookup = $this->pdo->prepare(
            'SELECT r.oid::text AS oid, (SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid '
            . 'AND a.attnum = i.indkey[0] WHERE i.indrelid = r.oid AND i.indisprimary AND i.indnatts = 1) AS primary_key '
            . 'FROM (SELECT to_regclass(?)::oid AS oid) r'
        );
        $lookup->execute([$qualifiedTable]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['oid'] ?? null)) {
            return $none; // not a relation visible under this name
        }
        $oid = $row['oid'];
        $primaryKey = is_string($row['primary_key'] ?? null) ? $row['primary_key'] : null;
        $cached = $this->keySequences[$qualifiedTable] ?? null;
        if ($cached !== null && $cached['oid'] === $oid && $cached['primaryKey'] === $primaryKey) {
            return ['primaryKey' => $primaryKey, 'sequences' => $cached['sequences']];
        }

        // serial and identity columns have an owned sequence; a hand-made DEFAULT nextval('seq') only shows in the default
        $statement = $this->pdo->prepare(
            'SELECT a.attname, '
            . 'COALESCE(to_regclass(pg_get_serial_sequence(a.attrelid::regclass::text, a.attname)), '
            . "to_regclass(substring(pg_get_expr(d.adbin, d.adrelid) from '^nextval\\(''(.+)''::regclass\\)\$')))::oid AS sequence, "
            . '(SELECT i.indnatts FROM pg_index i WHERE i.indrelid = a.attrelid AND i.indisprimary) AS pk_columns, '
            . 'EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = a.attrelid AND i.indisprimary AND a.attnum = ANY (i.indkey)) AS in_pk '
            . 'FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum '
            . 'WHERE a.attrelid = ?::oid AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum'
        );
        $statement->execute([$oid]);

        $found = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sequence = (string)($row['sequence'] ?? '');
            if ($sequence === '') {
                continue;
            }
            // With a primary key, only a single-column one is an id: a secondary counter or a composite key is not
            $primaryKeyColumns = $row['pk_columns'] ?? null;
            if ($primaryKeyColumns !== null && ((int)$primaryKeyColumns !== 1 || !filter_var($row['in_pk'], FILTER_VALIDATE_BOOLEAN))) {
                continue;
            }
            $found[] = ['column' => (string)$row['attname'], 'sequence' => $sequence];
        }

        // An empty answer is not kept: the table may get its key column later
        if ($found !== [] || $primaryKey !== null) {
            $this->keySequences[$qualifiedTable] = ['oid' => $oid, 'primaryKey' => $primaryKey, 'sequences' => $found];
        }

        return ['primaryKey' => $primaryKey, 'sequences' => $found];
    }

    /**
     * {@inheritDoc}
     */
    public function buildInboxSchemaSql(string $tableName): array
    {
        $tableQuoted = $this->quoteTable($tableName);

        return [
            "CREATE TABLE IF NOT EXISTS {$tableQuoted} (
                message_id VARCHAR(190) NOT NULL,
                consumer VARCHAR(190) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (message_id, consumer)
            )",
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function buildOutboxSchemaSql(string $tableName): array
    {
        $tableQuoted = $this->quoteTable($tableName);
        $indexName = $this->quoteIdentifier($tableName . '_pending_idx');
        $partitionIndex = $this->quoteIdentifier($tableName . '_partition_idx');

        return [
            "CREATE TABLE IF NOT EXISTS {$tableQuoted} (
                id VARCHAR(36) PRIMARY KEY,
                event_type VARCHAR(255) NOT NULL,
                payload JSONB NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at TIMESTAMPTZ NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                locked_by VARCHAR(128) NULL,
                locked_until TIMESTAMPTZ NULL,
                last_error TEXT NULL,
                published_at TIMESTAMPTZ NULL,
                headers TEXT NULL,
                partition_key VARCHAR(190) NULL
            )",
            "CREATE INDEX IF NOT EXISTS {$indexName} ON {$tableQuoted} (status, available_at)",
            "CREATE INDEX IF NOT EXISTS {$partitionIndex} ON {$tableQuoted} (partition_key, status, id)",
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function outboxNowSql(): string
    {
        return 'NOW()';
    }

    /**
     * {@inheritDoc}
     */
    public function outboxNowPlusSecondsSql(int $seconds): string
    {
        return "NOW() + INTERVAL '" . $seconds . " seconds'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildOutboxReleaseStaleClaimsSql(string $tableName): array
    {
        $tableQuoted = $this->quoteTable($tableName);

        return [
            "UPDATE {$tableQuoted}
                SET status = 'pending', locked_by = NULL, locked_until = NULL
                WHERE status = 'processing'
                  AND locked_until IS NOT NULL
                  AND locked_until < NOW()",
            [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function outboxClaimStrategy(): OutboxClaimStrategy
    {
        return new PostgreSQLOutboxClaim($this);
    }

    public function catalogQueries(): CatalogQueriesInterface
    {
        return $this;
    }

    public function connectionInitHandler(): ConnectionInitHandler
    {
        return ConnectionInitHandler::PgsqlTcp;
    }

    public function defaultTcpPort(): int
    {
        return 5432;
    }

    public function requiredPdoExtension(): string
    {
        return 'pdo_pgsql';
    }

    protected function catalogDialect(): DialectInterface
    {
        return $this;
    }

    public function supportsMcpStatementTimeout(): bool
    {
        return true;
    }

    public function readMcpStatementTimeout(PdoDb $db): string
    {
        $read = $db->connection->getPdo()->query("SELECT setting FROM pg_settings WHERE name = 'statement_timeout'");
        if ($read === false) {
            throw new RuntimeException('Unable to read the current statement timeout');
        }
        $previous = $read->fetchColumn();
        $read->closeCursor();
        if (!is_scalar($previous) || is_bool($previous)) {
            throw new RuntimeException('Unable to read the current statement timeout');
        }

        return (string)$previous;
    }

    public function setMcpStatementTimeout(PdoDb $db, int $milliseconds): void
    {
        $statement = $db->connection->getPdo()->prepare("SELECT set_config('statement_timeout', ?, false)");
        $statement->execute([(string)$milliseconds]);
        $statement->closeCursor();
    }

    public function restoreMcpStatementTimeout(PdoDb $db, string $previousValue): void
    {
        $statement = $db->connection->getPdo()->prepare("SELECT set_config('statement_timeout', ?, false)");
        $statement->execute([$previousValue]);
        $statement->closeCursor();
    }

    public function mcpStatementTimeoutStoredInMilliseconds(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>|null
     */
    protected function formatAiExplainPlanNode(array $row, array $settings): ?array
    {
        $text = (string)($row['QUERY PLAN'] ?? '');
        if (!str_contains($text, 'cost=')) {
            return null;
        }
        $node = ['operation' => trim(preg_replace('/\s+\(cost=.*$/', '', $text) ?? '')];
        if (preg_match('/cost=([\d.]+)\.\.([\d.]+) rows=(\d+) width=(\d+)/', $text, $numbers)) {
            $node += [
                'startup_cost' => (float)$numbers[1],
                'total_cost' => (float)$numbers[2],
                'estimated_rows' => (int)$numbers[3],
                'width' => (int)$numbers[4],
            ];
        }

        return $node;
    }

    /**
     * {@inheritDoc}
     */
    protected function listDumpTables(PdoDb $db): array
    {
        $rows = $db->rawQuery("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename");

        return array_values(array_map(static fn (array $row): string => (string)$row['tablename'], $rows));
    }

    /**
     * {@inheritDoc}
     */
    protected function restoreSplitter(): SqlScriptSplitter
    {
        // $tag$ ... $tag$ bodies (functions) may contain semicolons
        return new SqlScriptSplitter("'\"", dollarQuotes: true);
    }
}
