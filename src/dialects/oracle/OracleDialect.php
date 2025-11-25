<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\oracle;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\OracleExplainParser;
use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class OracleDialect extends DialectAbstract
{
    /** @var OracleFeatureSupport Feature support instance */
    private OracleFeatureSupport $featureSupport;

    /** @var OracleSqlFormatter SQL formatter instance */
    private OracleSqlFormatter $sqlFormatter;

    /** @var OracleDmlBuilder DML builder instance */
    private OracleDmlBuilder $dmlBuilder;

    /** @var OracleDdlBuilder DDL builder instance */
    private OracleDdlBuilder $ddlBuilder;

    public function __construct()
    {
        $this->featureSupport = new OracleFeatureSupport();
        $this->sqlFormatter = new OracleSqlFormatter($this);
        $this->dmlBuilder = new OracleDmlBuilder($this);
        $this->ddlBuilder = new OracleDdlBuilder($this);
    }

    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'oci';
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
        // Oracle requires column qualification when JOIN is used
        return true;
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
        return $this->dmlBuilder->buildUpdateWithJoinSql($table, $setClause, $joins, $whereClause, $limit, $options);
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
        return $this->dmlBuilder->buildDeleteWithJoinSql($table, $joins, $whereClause, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDsn(array $params): string
    {
        // Oracle DSN format: oci:dbname=//host:port/service_name or oci:dbname=host:port/service_name
        // Also supports: oci:dbname=host:port/service_name;charset=UTF8
        if (empty($params['host'])) {
            throw new InvalidArgumentException("Missing 'host' parameter for Oracle");
        }

        // Oracle can use service_name or sid
        $serviceName = $params['service_name'] ?? $params['sid'] ?? null;
        if (empty($serviceName)) {
            // Fallback to dbname if provided (for compatibility)
            $serviceName = $params['dbname'] ?? null;
            if (empty($serviceName)) {
                throw new InvalidArgumentException("Missing 'service_name' or 'sid' parameter for Oracle");
            }
        }

        $host = $params['host'];
        $port = $params['port'] ?? 1521;
        $charset = $params['charset'] ?? null;

        // Oracle DSN format: oci:dbname=//host:port/service_name
        $dsn = "oci:dbname=//{$host}:{$port}/{$serviceName}";

        if (!empty($charset)) {
            $dsn .= ";charset={$charset}";
        }

        return $dsn;
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
            // Oracle returns column names in uppercase by default
            // We use quoted identifiers in lowercase, so column names will be preserved as-is
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(string $name): string
    {
        // Oracle preserves case when quoted, but unquoted identifiers are uppercase
        // We quote in uppercase to match Oracle's default behavior
        // Escape double quotes by doubling them
        $escaped = str_replace('"', '""', $name);
        return '"' . strtoupper($escaped) . '"';
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
     * Oracle does not support AS keyword for table aliases.
     */
    public function getTableAliasKeyword(): string
    {
        return ' ';
    }

    /**
     * Quote table name with optional alias.
     * Oracle does not support AS keyword for table aliases in JOIN clauses.
     *
     * @param string $table
     *
     * @return string
     */
    protected function quoteTableWithAlias(string $table): string
    {
        $table = trim($table);

        // supported formats:
        //  - "schema.table"         (without alias)
        //  - "schema.table alias"   (alias with space)
        //  - "schema.table AS alias" (AS - will be removed for Oracle)
        //  - "table alias" / "table AS alias"
        //  - "table"                (without alias)

        if (preg_match('/\s+AS\s+/i', $table)) {
            $parts = preg_split('/\s+AS\s+/i', $table, 2);
            if ($parts === false || count($parts) < 2) {
                return $this->quoteIdentifier($table);
            }
            [$name, $alias] = $parts;
            $name = trim($name);
            $alias = trim($alias);
            // Oracle does not support AS keyword for table aliases
            return $this->quoteIdentifier($name) . ' ' . $this->quoteIdentifier($alias);
        }

        $parts = preg_split('/\s+/', $table, 2);
        if ($parts === false || count($parts) === 1) {
            return $this->quoteIdentifier($table);
        }

        [$name, $alias] = $parts;
        return $this->quoteIdentifier($name) . ' ' . $this->quoteIdentifier($alias);
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
    public function buildInsertMultiSql(
        string $table,
        array $columns,
        array $tuples,
        array $options
    ): string {
        return $this->dmlBuilder->buildInsertMultiSql($table, $columns, $tuples, $options);
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
    public function enhanceInsertOptions(array $options, array $columns, string $table): array
    {
        // Oracle doesn't need RETURNING clause - we'll use sequence.currval instead
        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function extractInsertId(PDOStatement $stmt, string $sql, array $params): ?int
    {
        // For Oracle, get ID from sequence.currval after INSERT
        // This works because triggers use sequences to generate IDs
        if ($this->pdo === null) {
            return null;
        }

        // Try to determine sequence name from table name
        // Convention: table_name_seq for table "table_name"
        // Handle quoted identifiers: "table_name" or table_name
        if (preg_match('/INSERT\s+INTO\s+(?:"([^"]+)"|([a-zA-Z_][a-zA-Z0-9_]*))/i', $sql, $matches)) {
            // @phpstan-ignore-next-line
            $tableName = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? null);
            if (is_string($tableName)) {
                // Remove schema prefix if present (e.g., "schema"."table" -> table)
                $tableNameReplaced = preg_replace('/^[^"]*"\."/', '', $tableName);
                if (!is_string($tableNameReplaced)) {
                    return null;
                }
                $tableNameStr = trim($tableNameReplaced, '"\'');
                if ($tableNameStr === '') {
                    return null;
                }
                $tableNameLower = strtolower($tableNameStr);
                $sequenceName = $tableNameLower . '_seq';

                try {
                    $quotedSequence = $this->quoteIdentifier($sequenceName);
                    $stmt = $this->pdo->query("SELECT {$quotedSequence}.CURRVAL FROM DUAL");
                    if ($stmt !== false) {
                        $id = $stmt->fetchColumn();
                        if ($id !== false && $id !== null) {
                            return (int)$id;
                        }
                    }
                } catch (PDOException) {
                    // Sequence might not exist or CURRVAL not available
                    // Fall through to return null
                }
            }
        }

        return null;
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
     */
    public function buildPingSql(): string
    {
        return 'SELECT 1 FROM DUAL';
    }

    /**
     * {@inheritDoc}
     */
    public function beforeCreateTable(
        \tommyknocker\pdodb\connection\ConnectionInterface $connection,
        string $tableName,
        array $columns
    ): void {
        // Oracle needs to drop existing sequences and triggers before creating table
        $this->dropSequencesAndTriggers($connection, $tableName, $columns);
    }

    /**
     * {@inheritDoc}
     */
    public function afterCreateTable(
        \tommyknocker\pdodb\connection\ConnectionInterface $connection,
        string $tableName,
        array $columns,
        string $sql
    ): void {
        // Oracle needs to create triggers for auto-increment columns
        // Check if SQL contains sequences (indicates auto-increment columns)
        if (str_contains($sql, 'CREATE SEQUENCE')) {
            $this->createTriggersForAutoIncrement($connection, $tableName, $columns);
        }
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
            return $asTimestamp ? 'EXTRACT(EPOCH FROM SYSTIMESTAMP)' : 'SYSTIMESTAMP';
        }

        $trimmedDif = trim($diff);
        if (preg_match('/^([+-] ?)?(\d+)\s+([A-Za-z]+)$/', $trimmedDif, $matches)) {
            $signRaw = trim((string)$matches[1]);
            if ($signRaw === '') {
                $signRaw = '+';
            }
            $sign = $signRaw === '-' ? '-' : '+';
            $value = $matches[2];
            $unitRaw = strtolower($matches[3]);
            $map = [
                'day' => 'DAY', 'days' => 'DAY',
                'month' => 'MONTH', 'months' => 'MONTH',
                'year' => 'YEAR', 'years' => 'YEAR',
                'hour' => 'HOUR', 'hours' => 'HOUR',
                'minute' => 'MINUTE', 'minutes' => 'MINUTE',
                'second' => 'SECOND', 'seconds' => 'SECOND',
            ];
            $unit = $map[$unitRaw] ?? strtoupper($unitRaw);

            if ($asTimestamp) {
                return "EXTRACT(EPOCH FROM (SYSTIMESTAMP {$sign} INTERVAL '{$value}' {$unit}))";
            }

            return "SYSTIMESTAMP {$sign} INTERVAL '{$value}' {$unit}";
        }

        if ($asTimestamp) {
            return "EXTRACT(EPOCH FROM (SYSTIMESTAMP + INTERVAL '{$trimmedDif}'))";
        }

        return "SYSTIMESTAMP + INTERVAL '{$trimmedDif}'";
    }

    /**
     * {@inheritDoc}
     */
    public function ilike(string $column, string $pattern): RawValue
    {
        // Oracle doesn't have ILIKE, use UPPER() for case-insensitive comparison
        return new RawValue("UPPER({$column}) LIKE UPPER(:pattern)", ['pattern' => $pattern]);
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainSql(string $query): string
    {
        return 'EXPLAIN PLAN FOR ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainAnalyzeSql(string $query): string
    {
        // Oracle doesn't have EXPLAIN ANALYZE, use EXPLAIN PLAN
        return 'EXPLAIN PLAN FOR ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplain(\PDO $pdo, string $sql, array $params = []): array
    {
        // Oracle EXPLAIN PLAN FOR doesn't support bind parameters
        // Substitute parameter values directly into SQL
        $explainSql = $this->buildExplainSql($sql);
        if (!empty($params)) {
            // Replace named parameters with their values
            foreach ($params as $key => $value) {
                $paramName = is_string($key) && str_starts_with($key, ':') ? $key : ':' . $key;
                if (is_string($value)) {
                    $quotedValue = $pdo->quote($value);
                } elseif (is_numeric($value)) {
                    $quotedValue = (string)$value;
                } elseif (is_bool($value)) {
                    $quotedValue = $value ? '1' : '0';
                } elseif ($value === null) {
                    $quotedValue = 'NULL';
                } else {
                    $quotedValue = $pdo->quote((string)$value);
                }
                $explainSql = str_replace($paramName, $quotedValue, $explainSql);
            }
        }
        $stmt = $pdo->query($explainSql);
        if ($stmt === false) {
            return [];
        }

        // Query PLAN_TABLE for the explain results
        $planSql = 'SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY())';
        $planStmt = $pdo->query($planSql);
        if ($planStmt === false) {
            return [];
        }
        $results = $planStmt->fetchAll(PDO::FETCH_ASSOC);
        $planStmt->closeCursor();
        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplainAnalyze(\PDO $pdo, string $sql, array $params = []): array
    {
        // Oracle doesn't have EXPLAIN ANALYZE, use EXPLAIN PLAN
        return $this->executeExplain($pdo, $sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    public function buildTableExistsSql(string $table): string
    {
        // Oracle: check USER_TABLES or ALL_TABLES
        return "SELECT COUNT(*) FROM USER_TABLES WHERE TABLE_NAME = UPPER('{$table}')";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return "SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, DATA_PRECISION, DATA_SCALE, NULLABLE, DATA_DEFAULT
                FROM USER_TAB_COLUMNS
                WHERE TABLE_NAME = UPPER('{$table}')
                ORDER BY COLUMN_ID";
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        // Oracle uses LOCK TABLE syntax
        $parts = [];
        foreach ($tables as $t) {
            $tableName = $this->quoteIdentifier($prefix . $t);
            $lockMode = strtoupper($lockMethod);
            // Oracle lock modes: ROW SHARE, ROW EXCLUSIVE, SHARE UPDATE, SHARE, SHARE ROW EXCLUSIVE, EXCLUSIVE
            $parts[] = "{$tableName} IN {$lockMode} MODE";
        }
        return 'LOCK TABLE ' . implode(', ', $parts);
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        // Oracle doesn't need explicit unlock (COMMIT/ROLLBACK releases locks)
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
        // Oracle doesn't support direct SQL LOAD CSV like MySQL
        // Use SQL*Loader or external tables instead
        throw new ResourceException('Direct SQL LOAD CSV is not supported in Oracle. Use SQL*Loader or external tables.');
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): Generator
    {
        return $this->getFileLoader()->loadFromCsvGenerator($table, $filePath, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadXML(string $table, string $filePath, array $options = []): string
    {
        // Oracle doesn't support direct SQL LOAD XML like MySQL
        // Use XMLType or external tables instead
        throw new ResourceException('Direct SQL LOAD XML is not supported in Oracle. Use XMLType or external tables.');
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadXMLGenerator(string $table, string $filePath, array $options = []): Generator
    {
        return $this->getFileLoader()->loadFromXmlGenerator($table, $filePath, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadJson(string $table, string $filePath, array $options = []): string
    {
        // Oracle doesn't support direct SQL LOAD JSON like MySQL
        // Use JSON functions or external tables instead
        throw new ResourceException('Direct SQL LOAD JSON is not supported in Oracle. Use JSON functions or external tables.');
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
    public function formatLike(string $column, string $pattern): string
    {
        // Oracle: CLOB columns cannot be used directly in LIKE
        // Use CAST(SUBSTR(TO_CHAR(...), 1, 4000) AS VARCHAR2(4000)) to convert CLOB to VARCHAR2
        // This ensures the result is VARCHAR2, not CLOB, which is required for LIKE operations
        // Column is already quoted when passed from ConditionBuilder
        return "CAST(SUBSTR(TO_CHAR({$column}), 1, 4000) AS VARCHAR2(4000)) LIKE :pattern";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnForComparison(string $column): string
    {
        // Oracle: CLOB columns cannot be compared directly with VARCHAR2
        // Use TO_CHAR() to convert CLOB to VARCHAR2 for comparison operations
        // This works for both CLOB and VARCHAR2 columns
        return "TO_CHAR({$column})";
    }

    /**
     * {@inheritDoc}
     */
    /**
     * {@inheritDoc}
     */
    public function normalizeColumnKey(string $key): string
    {
        // Oracle returns column keys in uppercase
        return strtoupper($key);
    }

    public function normalizeJoinCondition(string $condition): string
    {
        // Oracle: identifiers in ON conditions must be quoted
        // Pattern: table.column or alias.column
        // Replace unquoted identifiers with quoted ones
        return preg_replace_callback(
            '/(\w+)\.(\w+)/',
            function ($matches) {
                $table = $this->quoteIdentifier($matches[1]);
                $column = $this->quoteIdentifier($matches[2]);
                return "{$table}.{$column}";
            },
            $condition
        ) ?? $condition;
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
    public function getTimestampDefaultExpression(): string
    {
        return 'SYSTIMESTAMP';
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
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
        return $this->sqlFormatter->formatLimitOffset($sql, $limit, $offset);
    }

    /**
     * {@inheritDoc}
     */
    public function appendLimitOffset(string $sql, int $limit, int $offset): string
    {
        // Oracle 12c+ uses OFFSET ... ROWS FETCH NEXT ... ROWS ONLY
        // Check if ORDER BY exists in SQL
        if (stripos($sql, 'ORDER BY') === false) {
            // Oracle requires ORDER BY for OFFSET/FETCH
            // Use a simple ordering that works for any query
            return $sql . " ORDER BY 1 OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        } else {
            // ORDER BY exists, just add OFFSET/FETCH
            return $sql . " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string
    {
        // Oracle doesn't require parentheses in UNION
        return $selectSql;
    }

    /**
     * {@inheritDoc}
     */
    public function needsUnionParentheses(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionOrderBy(array $orderBy, array $selectColumns): string
    {
        // Oracle requires positional numbers in ORDER BY after UNION
        // Map column names to their positions in SELECT clause
        $orderByFormatted = [];
        foreach ($orderBy as $orderExpr) {
            // Extract column name and direction (ASC/DESC)
            $parts = preg_split('/\s+/', trim($orderExpr), 2);
            $column = $parts[0];
            $direction = isset($parts[1]) ? ' ' . $parts[1] : '';

            // Extract column name from ORDER BY expression (handle TO_CHAR("NAME"), "NAME", etc.)
            $columnNormalized = $this->extractColumnNameFromExpression($column);
            
            $position = null;
            foreach ($selectColumns as $index => $selectCol) {
                // Extract column name from SELECT column (handle TO_CHAR("NAME"), "NAME", aliases, etc.)
                $selectColNormalized = $this->extractColumnNameFromExpression($selectCol);
                
                // Check if column matches (case-insensitive)
                if (strcasecmp($columnNormalized, $selectColNormalized) === 0) {
                    $position = $index + 1; // 1-based position
                    break;
                }
            }

            if ($position !== null) {
                $orderByFormatted[] = $position . $direction;
            } else {
                // If column not found, use as-is (might be an expression)
                $orderByFormatted[] = $orderExpr;
            }
        }

        return implode(', ', $orderByFormatted);
    }

    /**
     * Extract column name from SQL expression.
     * Handles TO_CHAR("NAME"), "NAME", table.column, etc.
     *
     * @param string $expr SQL expression
     *
     * @return string Column name (normalized)
     */
    protected function extractColumnNameFromExpression(string $expr): string
    {
        $expr = trim($expr);
        
        // Handle function calls like TO_CHAR("NAME") -> extract "NAME"
        if (preg_match('/\([`"\[]?([A-Za-z0-9_]+)[`"\]]?\)$/i', $expr, $matches)) {
            return strtoupper($matches[1]);
        }
        
        // Handle quoted identifiers like "NAME" -> NAME
        if (preg_match('/^[`"\[]?([A-Za-z0-9_]+)[`"\]]?$/i', $expr, $matches)) {
            return strtoupper($matches[1]);
        }
        
        // Handle qualified identifiers like table.column -> column
        if (preg_match('/\.([A-Za-z0-9_]+)$/i', $expr, $matches)) {
            return strtoupper($matches[1]);
        }
        
        // Handle aliases like "name AS product_name" -> product_name
        if (preg_match('/\s+AS\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $expr, $aliasMatches)) {
            return strtoupper($aliasMatches[1]);
        }
        
        // Default: return uppercase version of expression
        return strtoupper(trim($expr, '"`[]'));
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
            INDEX_NAME as index_name,
            TABLE_NAME as table_name,
            COLUMN_NAME as column_name,
            COLUMN_POSITION as column_position
            FROM USER_IND_COLUMNS
            WHERE TABLE_NAME = UPPER('{$table}')
            ORDER BY INDEX_NAME, COLUMN_POSITION";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        return "SELECT
            CONSTRAINT_NAME,
            COLUMN_NAME,
            R_TABLE_NAME as REFERENCED_TABLE_NAME,
            R_COLUMN_NAME as REFERENCED_COLUMN_NAME
            FROM USER_CONS_COLUMNS ucc
            JOIN USER_CONSTRAINTS uc ON ucc.CONSTRAINT_NAME = uc.CONSTRAINT_NAME
            WHERE uc.TABLE_NAME = UPPER('{$table}')
            AND uc.CONSTRAINT_TYPE = 'R'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        return "SELECT
            CONSTRAINT_NAME,
            CONSTRAINT_TYPE,
            TABLE_NAME,
            COLUMN_NAME
            FROM USER_CONSTRAINTS uc
            LEFT JOIN USER_CONS_COLUMNS ucc ON uc.CONSTRAINT_NAME = ucc.CONSTRAINT_NAME
            WHERE uc.TABLE_NAME = UPPER('{$table}')";
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
        // Oracle-specific normalization: handle LENGTH(COALESCE(...)) with CLOB columns
        // LENGTH(COALESCE(email, phone, 'N/A')) -> LENGTH(TO_CHAR(COALESCE(TO_CHAR("EMAIL"), TO_CHAR("PHONE"), 'N/A')))
        if (preg_match('/^LENGTH\s*\(\s*COALESCE\s*\((.*)\)\s*\)$/i', trim($sql), $lengthMatches)) {
            $coalesceArgs = $lengthMatches[1];
            // Normalize COALESCE arguments
            $normalizedCoalesce = $this->normalizeCoalesceArgs($coalesceArgs);
            return "LENGTH(TO_CHAR(COALESCE($normalizedCoalesce)))";
        }
        
        // Oracle-specific normalization: handle COALESCE with CLOB columns
        // COALESCE(phone, email, 'No contact') -> COALESCE(TO_CHAR("PHONE"), TO_CHAR("EMAIL"), 'No contact')
        if (preg_match('/^COALESCE\s*\((.*)\)$/i', trim($sql), $matches)) {
            $argsStr = $matches[1];
            // Split by comma, but respect quoted strings
            $args = [];
            $current = '';
            $inQuotes = false;
            $quoteChar = '';
            for ($i = 0; $i < strlen($argsStr); $i++) {
                $char = $argsStr[$i];
                if (($char === '"' || $char === "'" || $char === '`') && ($i === 0 || $argsStr[$i - 1] !== '\\')) {
                    if (!$inQuotes) {
                        $inQuotes = true;
                        $quoteChar = $char;
                    } elseif ($char === $quoteChar) {
                        $inQuotes = false;
                        $quoteChar = '';
                    }
                    $current .= $char;
                } elseif ($char === ',' && !$inQuotes) {
                    $args[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            if ($current !== '') {
                $args[] = trim($current);
            }
            
            $formattedArgs = [];
            foreach ($args as $arg) {
                $arg = trim($arg);
                // Check if it's a quoted string literal (single quotes for string literals in SQL)
                // Must start and end with single quote
                if (preg_match("/^'.*'$/", $arg)) {
                    // It's a string literal (single quotes) - keep as-is
                    $formattedArgs[] = $arg;
                } elseif (preg_match('/^"([^"]+)"$/', $arg, $matches)) {
                    // It's a quoted identifier - check if it contains single quotes (string literal in double quotes)
                    $inner = $matches[1];
                    if (preg_match("/^'.*'$/", $inner)) {
                        // It's a string literal wrapped in double quotes (e.g., "'No contact'")
                        // Extract the inner string literal and keep as-is
                        $formattedArgs[] = $inner;
                    } else {
                        // It's a quoted identifier (column) - apply formatColumnForComparison for CLOB compatibility
                        $formattedArgs[] = $this->formatColumnForComparison($arg);
                    }
                } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $arg)) {
                    // It's an unquoted column identifier - quote and apply formatColumnForComparison
                    $quoted = $this->quoteIdentifier($arg);
                    $formattedArgs[] = $this->formatColumnForComparison($quoted);
                } else {
                    // It's an expression or something else - check if it contains CAST or CONCAT
                    // Apply CAST normalization if needed
                    if (preg_match('/CAST\s*\(([^,]+)\s+AS\s+([^)]+)\)/i', $arg, $castMatches)) {
                        $castExpr = trim($castMatches[1]);
                        $castType = trim($castMatches[2]);
                        
                        // Check if CAST expression is a column identifier
                        if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $castExpr) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $castExpr)) {
                            // It's a column - apply TO_CHAR() for CLOB compatibility before CAST
                            $castQuoted = preg_match('/^["`\[\]]/', $castExpr) ? $castExpr : $this->quoteIdentifier($castExpr);
                            $castExprFormatted = $this->formatColumnForComparison($castQuoted);
                            
                            // For numeric types (INTEGER, NUMBER, etc.), use CASE WHEN for safe casting
                            // Oracle CAST throws error on invalid values, so we need to catch it
                            if (preg_match('/^(INTEGER|NUMBER|DECIMAL|NUMERIC|REAL|FLOAT|DOUBLE)/i', $castType)) {
                                // Use CASE WHEN REGEXP_LIKE for safe numeric casting
                                // CASE WHEN REGEXP_LIKE(TO_CHAR(column), '^-?[0-9]+(\.[0-9]+)?$') THEN CAST(TO_CHAR(column) AS type) ELSE NULL END
                                $arg = "CASE WHEN REGEXP_LIKE($castExprFormatted, '^-?[0-9]+(\\.[0-9]+)?\$') THEN CAST($castExprFormatted AS $castType) ELSE NULL END";
                            } else {
                                // For non-numeric types, use CAST directly
                                $arg = preg_replace('/CAST\s*\(' . preg_quote($castExpr, '/') . '\s+AS\s+' . preg_quote($castType, '/') . '\)/i', "CAST($castExprFormatted AS $castType)", $arg);
                            }
                        }
                    }
                    // Check if it contains || operator (Oracle concatenation)
                    // 'Name: ' || name -> 'Name: ' || TO_CHAR("NAME")
                    if (preg_match('/\|\|/', $arg)) {
                        // Handle || operator (Oracle concatenation)
                        // Split by || but respect quoted strings
                        $parts = [];
                        $current = '';
                        $inQuotes = false;
                        $quoteChar = '';
                        for ($i = 0; $i < strlen($arg); $i++) {
                            $char = $arg[$i];
                            if (($char === '"' || $char === "'") && ($i === 0 || $arg[$i - 1] !== '\\')) {
                                if (!$inQuotes) {
                                    $inQuotes = true;
                                    $quoteChar = $char;
                                } elseif ($char === $quoteChar) {
                                    $inQuotes = false;
                                    $quoteChar = '';
                                }
                                $current .= $char;
                            } elseif ($char === '|' && $i + 1 < strlen($arg) && $arg[$i + 1] === '|' && !$inQuotes) {
                                // Found || operator
                                $parts[] = trim($current);
                                $current = '';
                                $i++; // Skip second |
                            } else {
                                $current .= $char;
                            }
                        }
                        if ($current !== '') {
                            $parts[] = trim($current);
                        }
                        
                        // Process each part
                        $formattedParts = [];
                        foreach ($parts as $part) {
                            $part = trim($part);
                            // If it's a quoted string literal, keep as-is
                            if (preg_match("/^'.*'$/", $part)) {
                                $formattedParts[] = $part;
                            } elseif (preg_match('/^"([^"]+)"$/', $part, $matches)) {
                                // It's a quoted identifier (column) - apply formatColumnForComparison
                                $formattedParts[] = $this->formatColumnForComparison($part);
                            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $part)) {
                                // It's an unquoted column identifier - quote and apply formatColumnForComparison
                                $quoted = $this->quoteIdentifier($part);
                                $formattedParts[] = $this->formatColumnForComparison($quoted);
                            } else {
                                // It's already an expression or quoted identifier - keep as-is
                                $formattedParts[] = $part;
                            }
                        }
                        // Rebuild with || operator
                        $arg = implode(' || ', $formattedParts);
                    } elseif (preg_match('/CONCAT\s*\(([^)]+)\)/i', $arg, $concatMatches)) {
                        $concatArgs = $concatMatches[1];
                        // Split CONCAT arguments by comma, but respect quoted strings
                        $concatParts = [];
                        $current = '';
                        $inQuotes = false;
                        $quoteChar = '';
                        for ($i = 0; $i < strlen($concatArgs); $i++) {
                            $char = $concatArgs[$i];
                            if (($char === '"' || $char === "'") && ($i === 0 || $concatArgs[$i - 1] !== '\\')) {
                                if (!$inQuotes) {
                                    $inQuotes = true;
                                    $quoteChar = $char;
                                } elseif ($char === $quoteChar) {
                                    $inQuotes = false;
                                    $quoteChar = '';
                                }
                                $current .= $char;
                            } elseif ($char === ',' && !$inQuotes) {
                                $concatParts[] = trim($current);
                                $current = '';
                            } else {
                                $current .= $char;
                            }
                        }
                        if ($current !== '') {
                            $concatParts[] = trim($current);
                        }
                        
                        // Process each CONCAT argument
                        $formattedConcatParts = [];
                        foreach ($concatParts as $part) {
                            $part = trim($part);
                            // If it's a quoted string literal, keep as-is
                            if (preg_match("/^'.*'$/", $part)) {
                                $formattedConcatParts[] = $part;
                            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $part)) {
                                // It's an unquoted column identifier - quote and apply formatColumnForComparison
                                $quoted = $this->quoteIdentifier($part);
                                $formattedConcatParts[] = $this->formatColumnForComparison($quoted);
                            } else {
                                // It's already an expression or quoted identifier - keep as-is
                                $formattedConcatParts[] = $part;
                            }
                        }
                        // Rebuild CONCAT with formatted parts
                        $arg = 'CONCAT(' . implode(', ', $formattedConcatParts) . ')';
                    }
                    $formattedArgs[] = $arg;
                }
            }
            // Return COALESCE without wrapping in TO_CHAR()
            // COALESCE may return non-string types (INTEGER, NUMBER, etc.) from CAST operations
            // Wrapping in TO_CHAR() would cause type conversion issues
            return 'COALESCE(' . implode(', ', $formattedArgs) . ')';
        }
        
        // Oracle-specific normalization: handle NULLIF with CLOB columns
        // NULLIF(email, '') -> NULLIF(TO_CHAR("EMAIL"), '')
        if (preg_match('/^NULLIF\s*\((.*),\s*(.*)\)$/i', trim($sql), $matches)) {
            $expr1 = trim($matches[1]);
            $expr2 = trim($matches[2]);
            
            // Apply formatColumnForComparison to first expression if it's a column
            if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $expr1) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $expr1)) {
                // It's a column identifier
                $quoted = preg_match('/^["`\[\]]/', $expr1) ? $expr1 : $this->quoteIdentifier($expr1);
                $expr1Formatted = $this->formatColumnForComparison($quoted);
            } else {
                // It's an expression - keep as-is
                $expr1Formatted = $expr1;
            }
            
            return "NULLIF($expr1Formatted, $expr2)";
        }
        
        // Oracle-specific normalization: handle CAST with CLOB columns
        // CAST(text_value AS NUMBER) -> CAST(TO_CHAR("TEXT_VALUE") AS NUMBER)
        if (preg_match('/CAST\s*\(([^,]+)\s+AS\s+([^)]+)\)/i', $sql, $matches)) {
            $expr = trim($matches[1]);
            $type = trim($matches[2]);
            
            // Check if expression is a column identifier (quoted or unquoted)
            if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $expr) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $expr)) {
                // It's a column - apply TO_CHAR() for CLOB compatibility before CAST
                $quoted = preg_match('/^["`\[\]]/', $expr) ? $expr : $this->quoteIdentifier($expr);
                $exprFormatted = $this->formatColumnForComparison($quoted);
                // Replace CAST(column AS type) with CAST(TO_CHAR(column) AS type)
                $sql = preg_replace('/CAST\s*\(' . preg_quote($expr, '/') . '\s+AS\s+' . preg_quote($type, '/') . '\)/i', "CAST($exprFormatted AS $type)", $sql);
            }
        }
        
        // Oracle-specific normalization: handle LOG10() -> LOG(10, ...)
        // Oracle doesn't support LOG10(), use LOG(10, ...) instead
        if (preg_match('/LOG10\s*\(/i', $sql)) {
            $sql = preg_replace('/LOG10\s*\(/i', 'LOG(10, ', $sql);
        }
        
        // Oracle-specific normalization: handle TRUE/FALSE -> 1/0
        // Oracle doesn't support TRUE/FALSE constants, use 1/0 instead
        $sql = preg_replace('/\bTRUE\b/i', '1', $sql);
        $sql = preg_replace('/\bFALSE\b/i', '0', $sql);
        
        return $sql;
    }
    
    /**
     * Normalize COALESCE arguments for CLOB compatibility.
     *
     * @param string $argsStr The COALESCE arguments string
     *
     * @return string Normalized arguments string
     */
    protected function normalizeCoalesceArgs(string $argsStr): string
    {
        // Split by comma, but respect quoted strings
        $args = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        for ($i = 0; $i < strlen($argsStr); $i++) {
            $char = $argsStr[$i];
            if (($char === '"' || $char === "'" || $char === '`') && ($i === 0 || $argsStr[$i - 1] !== '\\')) {
                if (!$inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuotes = false;
                    $quoteChar = '';
                }
                $current .= $char;
            } elseif ($char === ',' && !$inQuotes) {
                $args[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if ($current !== '') {
            $args[] = trim($current);
        }
        
        $formattedArgs = [];
        foreach ($args as $arg) {
            $arg = trim($arg);
            // Check if it's a quoted string literal (single quotes for string literals in SQL)
            if (preg_match("/^'.*'$/", $arg)) {
                // It's a string literal (single quotes) - keep as-is
                $formattedArgs[] = $arg;
            } elseif (preg_match('/^"([^"]+)"$/', $arg, $matches)) {
                // It's a quoted identifier - check if it contains single quotes (string literal in double quotes)
                $inner = $matches[1];
                if (preg_match("/^'.*'$/", $inner)) {
                    // It's a string literal wrapped in double quotes (e.g., "'No contact'")
                    // Extract the inner string literal and keep as-is
                    $formattedArgs[] = $inner;
                } else {
                    // It's a quoted identifier (column) - apply formatColumnForComparison for CLOB compatibility
                    $formattedArgs[] = $this->formatColumnForComparison($arg);
                }
            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $arg)) {
                // It's an unquoted column identifier - quote and apply formatColumnForComparison
                $quoted = $this->quoteIdentifier($arg);
                $formattedArgs[] = $this->formatColumnForComparison($quoted);
            } else {
                // It's an expression or something else - keep as-is
                $formattedArgs[] = $arg;
            }
        }
        
        return implode(', ', $formattedArgs);
    }

    /**
     * {@inheritDoc}
     */
    public function buildExistsExpression(string $subquery): string
    {
        // The subquery is already a SELECT statement, wrap it in parentheses for EXISTS
        // Oracle requires the subquery to be wrapped in parentheses
        $subquery = trim($subquery);
        return 'SELECT CASE WHEN EXISTS(' . $subquery . ') THEN 1 ELSE 0 END FROM DUAL';
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
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        return $this->sqlFormatter->formatMaterializedCte($cteSql, $isMaterialized);
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        return 'CLOB';
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
    public function getExplainParser(): ExplainParserInterface
    {
        return new OracleExplainParser();
    }

    /**
     * {@inheritDoc}
     */
    protected function buildCreateDatabaseSql(string $databaseName): string
    {
        // Oracle doesn't have CREATE DATABASE, uses CREATE USER or CREATE SCHEMA
        // For compatibility, we'll use CREATE USER
        $quotedName = $this->quoteIdentifier($databaseName);
        return "CREATE USER {$quotedName} IDENTIFIED BY password";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildDropDatabaseSql(string $databaseName): string
    {
        // Oracle uses DROP USER
        $quotedName = $this->quoteIdentifier($databaseName);
        return "DROP USER {$quotedName} CASCADE";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildListDatabasesSql(): string
    {
        // Oracle lists users/schemas, not databases
        return 'SELECT USERNAME FROM ALL_USERS ORDER BY USERNAME';
    }

    /**
     * {@inheritDoc}
     */
    protected function extractDatabaseNames(array $result): array
    {
        $names = [];
        foreach ($result as $row) {
            $name = $row['USERNAME'] ?? null;
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

        $dbName = $db->rawQueryValue('SELECT USER FROM DUAL');
        if ($dbName !== null) {
            $info['current_user'] = $dbName;
        }

        $version = $db->rawQueryValue('SELECT BANNER FROM V$VERSION WHERE ROWNUM = 1');
        if ($version !== null) {
            $info['version'] = $version;
        }

        return $info;
    }

    /* ---------------- User Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function createUser(string $username, string $password, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // Oracle doesn't use host in user creation
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedPassword = $db->rawQueryValue('SELECT ? FROM DUAL', [$password]);
        if ($quotedPassword === null) {
            $quotedPassword = "'" . addslashes($password) . "'";
        }

        $sql = "CREATE USER {$quotedUsername} IDENTIFIED BY {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // Oracle doesn't use host in user deletion
        $quotedUsername = $this->quoteIdentifier($username);

        $sql = "DROP USER {$quotedUsername} CASCADE";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function userExists(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // Oracle doesn't use host in user checks
        $sql = 'SELECT COUNT(*) FROM ALL_USERS WHERE USERNAME = UPPER(?)';
        $count = $db->rawQueryValue($sql, [$username]);
        return (int)$count > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function listUsers(\tommyknocker\pdodb\PdoDb $db): array
    {
        $sql = 'SELECT USERNAME FROM ALL_USERS ORDER BY USERNAME';
        $result = $db->rawQuery($sql);

        $users = [];
        foreach ($result as $row) {
            $users[] = [
                'username' => $row['USERNAME'],
                'host' => null,
                'user_host' => $row['USERNAME'],
            ];
        }

        return $users;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): array
    {
        // Oracle doesn't use host in user info
        $sql = 'SELECT USERNAME, CREATED, PROFILE FROM ALL_USERS WHERE USERNAME = UPPER(?)';
        $user = $db->rawQueryOne($sql, [$username]);

        if (empty($user)) {
            return [];
        }

        $info = [
            'username' => $user['USERNAME'],
            'host' => null,
            'user_host' => $user['USERNAME'],
            'created' => $user['CREATED'] ?? null,
            'profile' => $user['PROFILE'] ?? null,
        ];

        // Get privileges
        try {
            $grantsSql = 'SELECT PRIVILEGE, GRANTEE FROM USER_SYS_PRIVS WHERE GRANTEE = UPPER(?)';
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
        // Oracle doesn't use host in grants
        $quotedUsername = $this->quoteIdentifier($username);

        $target = '';
        if ($database !== null && $table !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);
            $quotedTable = $table === '*' ? '*' : $this->quoteIdentifier($table);
            $target = "{$quotedDb}.{$quotedTable}";
        } elseif ($database !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);
            $target = $quotedDb;
        } else {
            // System privileges
            $target = '';
        }

        $sql = "GRANT {$privileges}";
        if ($target !== '') {
            $sql .= " ON {$target}";
        }
        $sql .= " TO {$quotedUsername}";
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
        // Oracle doesn't use host in revokes
        $quotedUsername = $this->quoteIdentifier($username);

        $target = '';
        if ($database !== null && $table !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);
            $quotedTable = $table === '*' ? '*' : $this->quoteIdentifier($table);
            $target = "{$quotedDb}.{$quotedTable}";
        } elseif ($database !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);
            $target = $quotedDb;
        }

        $sql = "REVOKE {$privileges}";
        if ($target !== '') {
            $sql .= " ON {$target}";
        }
        $sql .= " FROM {$quotedUsername}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function changeUserPassword(string $username, string $newPassword, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // Oracle doesn't use host in password changes
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedPassword = $db->rawQueryValue('SELECT ? FROM DUAL', [$newPassword]);
        if ($quotedPassword === null) {
            $quotedPassword = "'" . addslashes($newPassword) . "'";
        }

        $sql = "ALTER USER {$quotedUsername} IDENTIFIED BY {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dumpSchema(\tommyknocker\pdodb\PdoDb $db, ?string $table = null, bool $dropTables = true): string
    {
        $output = [];
        $tables = [];

        if ($table !== null) {
            $tables = [$table];
        } else {
            $rows = $db->rawQuery('SELECT TABLE_NAME FROM USER_TABLES ORDER BY TABLE_NAME');
            foreach ($rows as $row) {
                $tables[] = (string)$row['TABLE_NAME'];
            }
        }

        foreach ($tables as $tableName) {
            $quotedTable = $this->quoteTable($tableName);

            if ($dropTables) {
                $output[] = "DROP TABLE {$quotedTable} CASCADE CONSTRAINTS;";
            }

            // Build CREATE TABLE from USER_TAB_COLUMNS
            $columns = $db->rawQuery(
                'SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, DATA_PRECISION, DATA_SCALE, NULLABLE, DATA_DEFAULT
                 FROM USER_TAB_COLUMNS
                 WHERE TABLE_NAME = UPPER(?)
                 ORDER BY COLUMN_ID',
                [$tableName]
            );

            if (empty($columns)) {
                continue;
            }

            $colDefs = [];
            foreach ($columns as $col) {
                $colName = $this->quoteIdentifier((string)$col['COLUMN_NAME']);
                $dataType = (string)$col['DATA_TYPE'];
                $nullable = (string)$col['NULLABLE'] === 'Y';
                $default = $col['DATA_DEFAULT'] !== null ? (string)$col['DATA_DEFAULT'] : null;

                // Build type with length/precision
                if ($col['DATA_LENGTH'] !== null) {
                    $dataType .= '(' . (int)$col['DATA_LENGTH'] . ')';
                } elseif ($col['DATA_PRECISION'] !== null) {
                    $dataType .= '(' . (int)$col['DATA_PRECISION'];
                    if ($col['DATA_SCALE'] !== null) {
                        $dataType .= ',' . (int)$col['DATA_SCALE'];
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

            $output[] = "CREATE TABLE {$quotedTable} (\n  " . implode(",\n  ", $colDefs) . "\n);";

            // Get indexes
            $indexRows = $db->rawQuery(
                'SELECT INDEX_NAME, INDEX_TYPE, UNIQUENESS
                 FROM USER_INDEXES
                 WHERE TABLE_NAME = UPPER(?)
                 ORDER BY INDEX_NAME',
                [$tableName]
            );
            foreach ($indexRows as $idxRow) {
                // Get index columns
                $idxCols = $db->rawQuery(
                    'SELECT COLUMN_NAME, COLUMN_POSITION
                     FROM USER_IND_COLUMNS
                     WHERE INDEX_NAME = ?
                     ORDER BY COLUMN_POSITION',
                    [$idxRow['INDEX_NAME']]
                );
                $colNames = array_map(fn ($c) => $this->quoteIdentifier((string)$c['COLUMN_NAME']), $idxCols);
                $unique = (string)$idxRow['UNIQUENESS'] === 'UNIQUE' ? 'UNIQUE ' : '';
                $idxName = $this->quoteIdentifier((string)$idxRow['INDEX_NAME']);
                $output[] = "CREATE {$unique}INDEX {$idxName} ON {$quotedTable} (" . implode(', ', $colNames) . ');';
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
            $rows = $db->rawQuery('SELECT TABLE_NAME FROM USER_TABLES ORDER BY TABLE_NAME');
            foreach ($rows as $row) {
                $tables[] = (string)$row['TABLE_NAME'];
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
        // Split SQL into statements
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';

        $lines = explode("\n", $sql);
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '' || preg_match('/^--/', $trimmedLine)) {
                continue;
            }

            $length = strlen($line);
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                $nextChar = $i + 1 < $length ? $line[$i + 1] : '';

                if (!$inString && $char === '-' && $nextChar === '-') {
                    break;
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
        return new OracleDdlQueryBuilder($connection, $prefix);
    }

    /* ---------------- Monitoring ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getActiveQueries(\tommyknocker\pdodb\PdoDb $db): array
    {
        $sql = "SELECT SID, SERIAL#, USERNAME, STATUS, SQL_TEXT, ELAPSED_TIME, CPU_TIME
                FROM V\$SESSION s
                JOIN V\$SQLAREA sa ON s.SQL_ADDRESS = sa.ADDRESS
                WHERE s.STATUS = 'ACTIVE' AND s.SQL_TEXT IS NOT NULL";
        $rows = $db->rawQuery($sql);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'sid' => $this->toString($row['SID'] ?? ''),
                'serial' => $this->toString($row['SERIAL#'] ?? ''),
                'user' => $this->toString($row['USERNAME'] ?? ''),
                'status' => $this->toString($row['STATUS'] ?? ''),
                'elapsed_time' => $this->toString($row['ELAPSED_TIME'] ?? ''),
                'cpu_time' => $this->toString($row['CPU_TIME'] ?? ''),
                'query' => $this->toString($row['SQL_TEXT'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveConnections(\tommyknocker\pdodb\PdoDb $db): array
    {
        $rows = $db->rawQuery('SELECT SID, SERIAL#, USERNAME, STATUS, PROGRAM FROM V$SESSION WHERE USERNAME IS NOT NULL');
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'sid' => $this->toString($row['SID'] ?? ''),
                'serial' => $this->toString($row['SERIAL#'] ?? ''),
                'user' => $this->toString($row['USERNAME'] ?? ''),
                'status' => $this->toString($row['STATUS'] ?? ''),
                'program' => $this->toString($row['PROGRAM'] ?? ''),
            ];
        }

        // Get connection limits
        $maxConn = $db->rawQueryValue("SELECT VALUE FROM V\$PARAMETER WHERE NAME = 'processes'");
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
    public function getSlowQueries(\tommyknocker\pdodb\PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        // Oracle uses V$SQLAREA for slow queries
        $thresholdMicroseconds = (int)($thresholdSeconds * 1000000);
        $sql = 'SELECT SQL_TEXT, ELAPSED_TIME, CPU_TIME, EXECUTIONS, BUFFER_GETS
                FROM V$SQLAREA
                WHERE ELAPSED_TIME >= ?
                ORDER BY ELAPSED_TIME DESC
                FETCH NEXT ? ROWS ONLY';
        $rows = $db->rawQuery($sql, [$thresholdMicroseconds, $limit]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'query' => $this->toString($row['SQL_TEXT'] ?? ''),
                'elapsed_time' => $this->toString($row['ELAPSED_TIME'] ?? '') . 'μs',
                'cpu_time' => $this->toString($row['CPU_TIME'] ?? '') . 'μs',
                'executions' => $this->toString($row['EXECUTIONS'] ?? ''),
                'buffer_gets' => $this->toString($row['BUFFER_GETS'] ?? ''),
            ];
        }
        return $result;
    }

    /* ---------------- Table Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function listTables(\tommyknocker\pdodb\PdoDb $db, ?string $schema = null): array
    {
        $schemaName = $schema ?? 'USER';
        if ($schemaName === 'USER') {
            $sql = 'SELECT TABLE_NAME FROM USER_TABLES ORDER BY TABLE_NAME';
            $rows = $db->rawQuery($sql);
        } else {
            $sql = 'SELECT TABLE_NAME FROM ALL_TABLES WHERE OWNER = UPPER(:schema) ORDER BY TABLE_NAME';
            $rows = $db->rawQuery($sql, [':schema' => $schemaName]);
        }
        /** @var array<int, string> $names */
        $names = array_map(static fn (array $r): string => (string)$r['TABLE_NAME'], $rows);
        return $names;
    }

    /* ---------------- Error Handling ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getRetryableErrorCodes(): array
    {
        return [
            'ORA-00054', // Resource busy and acquire with NOWAIT specified
            'ORA-00060', // Deadlock detected
            'ORA-04021', // Timeout occurred while waiting to lock object
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getErrorDescription(int|string $errorCode): string
    {
        $descriptions = [
            'ORA-00942' => 'Table or view does not exist',
            'ORA-00904' => 'Invalid column name',
            'ORA-00001' => 'Unique constraint violated',
            'ORA-02291' => 'Integrity constraint violated - parent key not found',
            'ORA-02292' => 'Integrity constraint violated - child record found',
            'ORA-00054' => 'Resource busy and acquire with NOWAIT specified',
            'ORA-00060' => 'Deadlock detected',
            'ORA-01017' => 'Invalid username/password',
            'ORA-12514' => 'TNS:listener does not currently know of service',
            'ORA-12541' => 'TNS:no listener',
        ];

        return $descriptions[$errorCode] ?? 'Unknown error';
    }

    /**
     * {@inheritDoc}
     */
    public function extractErrorCode(PDOException $e): string
    {
        // Oracle stores error code in errorInfo[1] or exception code
        if (isset($e->errorInfo[1])) {
            return (string)$e->errorInfo[1];
        }
        return (string)$e->getCode();
    }

    /* ---------------- Configuration ---------------- */

    /**
     * {@inheritDoc}
     */
    public function buildConfigFromEnv(array $envVars): array
    {
        $config = parent::buildConfigFromEnv($envVars);

        // Oracle uses service name in dbname
        if (isset($envVars['PDODB_DATABASE'])) {
            $config['dbname'] = $envVars['PDODB_DATABASE'];
        }

        if (isset($envVars['PDODB_CHARSET'])) {
            $config['charset'] = $envVars['PDODB_CHARSET'];
        } else {
            $config['charset'] = 'UTF8';
        }

        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeConfigParams(array $config): array
    {
        $config = parent::normalizeConfigParams($config);

        // Oracle requires 'dbname' for service name
        if (isset($config['database']) && !isset($config['dbname'])) {
            $config['dbname'] = $config['database'];
        }

        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function formatConcatExpression(array $parts): string
    {
        // Oracle uses || operator for concatenation (like SQLite)
        return implode(' || ', $parts);
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanType(): array
    {
        return ['type' => 'NUMBER', 'length' => 1];
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
        return 'NUMBER';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigPrimaryKeyType(): string
    {
        return 'NUMBER(19)';
    }

    /**
     * {@inheritDoc}
     */
    public function getStringType(): string
    {
        return 'VARCHAR2';
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        $tableQuoted = $this->quoteTable($tableName);
        return "CREATE TABLE {$tableQuoted} (
            version VARCHAR2(255) PRIMARY KEY,
            apply_time TIMESTAMP DEFAULT SYSTIMESTAMP,
            batch NUMBER NOT NULL
        )";
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationInsertSql(string $tableName, string $version, int $batch): array
    {
        // Oracle uses named parameters
        $tableQuoted = $this->quoteTable($tableName);
        return [
            "INSERT INTO {$tableQuoted} (version, batch) VALUES (:version, :batch)",
            ['version' => $version, 'batch' => $batch],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getRecursiveCteKeyword(): string
    {
        // Oracle uses just 'WITH' for recursive CTEs
        return 'WITH';
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeRecursiveCteSql(string $sql, string $cteName, bool $isRecursive): string
    {
        if (!$isRecursive) {
            return $sql;
        }

        // Oracle requires CTE name qualification instead of aliases in recursive part
        // Extract unquoted CTE name for pattern matching
        $unquotedCteName = trim($cteName, '"');
        $unquotedCteNameUpper = strtoupper($unquotedCteName);

        // Replace aliases in recursive part (after UNION ALL)
        // Pattern: "CT"."COLUMN" or ct.column -> CTE_NAME.COLUMN
        if (preg_match('/UNION\s+ALL\s+(.+)$/is', $sql, $matches)) {
            $recursivePart = $matches[1];
            // Replace quoted aliases like "CT" with CTE name
            // Match pattern: "ALIAS"."COLUMN" where ALIAS matches CTE name
            $recursivePart = preg_replace_callback(
                '/"([^"]+)"\s*\.\s*"([^"]+)"/i',
                function ($m) use ($cteName, $unquotedCteNameUpper) {
                    $alias = strtoupper($m[1]);
                    $column = $m[2];
                    // Check if alias matches CTE name (case-insensitive)
                    // Also check for common alias patterns like CT, CTE, etc.
                    if ($alias === $unquotedCteNameUpper ||
                        preg_match('/^' . preg_quote($unquotedCteNameUpper, '/') . '\d*$/i', $alias) ||
                        // Common alias patterns for CTE references
                        preg_match('/^(CT|CTE|REC|RECURSIVE)$/i', $alias)) {
                        return $cteName . '.' . $this->quoteIdentifier($column);
                    }
                    return $m[0];
                },
                $recursivePart
            );
            // Replace unquoted aliases like ct.column or ct.level
            // Match pattern: alias.column (not in quotes)
            $recursivePart = preg_replace_callback(
                '/(\b)([a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*([a-zA-Z_][a-zA-Z0-9_]*|\*)(\b)/',
                function ($m) use ($cteName, $unquotedCteNameUpper) {
                    $alias = strtoupper($m[2]);
                    $column = $m[3];
                    // Check if alias matches CTE name (case-insensitive)
                    // Also check for common alias patterns
                    if ($alias === $unquotedCteNameUpper ||
                        preg_match('/^' . preg_quote($unquotedCteNameUpper, '/') . '\d*$/i', $alias) ||
                        // Common alias patterns for CTE references
                        preg_match('/^(CT|CTE|REC|RECURSIVE)$/i', $alias)) {
                        $quotedColumn = $column === '*' ? '*' : $this->quoteIdentifier($column);
                        return $m[1] . $cteName . '.' . $quotedColumn . $m[4];
                    }
                    return $m[0];
                },
                $recursivePart
            );
            // Also replace CTE aliases in JOIN conditions (e.g., "CT"."ID" -> "CATEGORY_TREE"."ID")
            // This handles cases like: INNER JOIN "CATEGORY_TREE" "CT" ON ... = "CT"."ID"
            $recursivePart = preg_replace_callback(
                '/INNER\s+JOIN\s+"([^"]+)"\s+"([^"]+)"/i',
                function ($m) use ($cteName, $unquotedCteNameUpper) {
                    $tableName = strtoupper($m[1]);
                    // If table name matches CTE name, keep the join but note the alias
                    if ($tableName === $unquotedCteNameUpper) {
                        // Keep the join structure but we'll replace alias references later
                        return 'INNER JOIN ' . $cteName . ' ' . $this->quoteIdentifier($m[2]);
                    }
                    return $m[0];
                },
                $recursivePart
            );
            $sql = preg_replace('/UNION\s+ALL\s+.+$/is', 'UNION ALL ' . $recursivePart, $sql);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatLateralJoin(string $tableSql, string $type, string $aliasQuoted, string|RawValue|null $condition = null): string
    {
        // Oracle supports LATERAL JOINs
        // Note: tableSql already contains "LATERAL" prefix from JoinBuilder
        $typeUpper = strtoupper(trim($type));
        $isCross = ($typeUpper === 'CROSS' || $typeUpper === 'CROSS JOIN');

        if ($condition !== null) {
            $onSql = $condition instanceof RawValue ? $condition->getValue() : (string)$condition;
            if ($isCross) {
                return "CROSS JOIN {$tableSql} ON {$onSql}";
            }
            return "{$typeUpper} JOIN {$tableSql} ON {$onSql}";
        }

        if ($isCross) {
            return "CROSS JOIN {$tableSql}";
        }

        return "{$typeUpper} JOIN {$tableSql} ON 1=1";
    }

    /**
     * {@inheritDoc}
     */
    public function getInsertSelectColumns(string $tableName, ?array $columns, \tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface $executionEngine): array
    {
        // Oracle doesn't have IDENTITY columns like MSSQL, return columns as-is
        return $columns ?? [];
    }

    /**
     * Create triggers for auto-increment columns.
     *
     * @param \tommyknocker\pdodb\connection\ConnectionInterface $connection Database connection
     * @param string $tableName Table name
     * @param array<string, \tommyknocker\pdodb\query\schema\ColumnSchema|array<string, mixed>|string> $columns Column definitions
     */
    protected function createTriggersForAutoIncrement(
        \tommyknocker\pdodb\connection\ConnectionInterface $connection,
        string $tableName,
        array $columns
    ): void {
        $tableQuoted = $this->quoteTable($tableName);
        $tableQuotedUpper = strtoupper($tableQuoted);

        foreach ($columns as $columnName => $def) {
            $schema = $this->normalizeColumnSchemaForTrigger($def);
            if ($schema->isAutoIncrement()) {
                $sequenceName = strtolower($tableName . '_' . $columnName . '_seq');
                $seqQuoted = $this->quoteIdentifier($sequenceName);
                $columnQuoted = $this->quoteIdentifier($columnName);
                $triggerName = strtolower($tableName . '_' . $columnName . '_trigger');
                $triggerQuoted = $this->quoteIdentifier($triggerName);

                $triggerSql = "CREATE OR REPLACE TRIGGER {$triggerQuoted} BEFORE INSERT ON {$tableQuotedUpper} FOR EACH ROW BEGIN IF :NEW.{$columnQuoted} IS NULL THEN SELECT {$seqQuoted}.NEXTVAL INTO :NEW.{$columnQuoted} FROM DUAL; END IF; END;";
                $connection->query($triggerSql);
            }
        }
    }

    /**
     * Normalize column schema from various formats for trigger creation.
     *
     * @param \tommyknocker\pdodb\query\schema\ColumnSchema|array<string, mixed>|string $def Column definition
     *
     * @return \tommyknocker\pdodb\query\schema\ColumnSchema
     */
    protected function normalizeColumnSchemaForTrigger(\tommyknocker\pdodb\query\schema\ColumnSchema|array|string $def): \tommyknocker\pdodb\query\schema\ColumnSchema
    {
        if ($def instanceof \tommyknocker\pdodb\query\schema\ColumnSchema) {
            return $def;
        }

        if (is_array($def)) {
            $schemaType = $def['type'] ?? 'VARCHAR';
            $length = $def['length'] ?? $def['size'] ?? null;
            $scale = $def['scale'] ?? null;

            $schema = new \tommyknocker\pdodb\query\schema\ColumnSchema((string)$schemaType, $length, $scale);

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
            if (isset($def['autoIncrement']) && $def['autoIncrement']) {
                $schema->autoIncrement();
            }

            return $schema;
        }

        return new \tommyknocker\pdodb\query\schema\ColumnSchema((string)$def);
    }

    /**
     * Drop sequences and triggers for auto-increment columns before table creation.
     *
     * @param \tommyknocker\pdodb\connection\ConnectionInterface $connection Database connection
     * @param string $tableName Table name
     * @param array<string, \tommyknocker\pdodb\query\schema\ColumnSchema|array<string, mixed>|string> $columns Column definitions
     */
    protected function dropSequencesAndTriggers(
        \tommyknocker\pdodb\connection\ConnectionInterface $connection,
        string $tableName,
        array $columns
    ): void {
        foreach ($columns as $columnName => $def) {
            $schema = $this->normalizeColumnSchemaForTrigger($def);
            if ($schema->isAutoIncrement()) {
                $sequenceName = strtolower($tableName . '_' . $columnName . '_seq');
                $seqQuoted = $this->quoteIdentifier($sequenceName);
                $triggerName = strtolower($tableName . '_' . $columnName . '_trigger');
                $triggerQuoted = $this->quoteIdentifier($triggerName);

                // Drop trigger if exists (Oracle doesn't support IF EXISTS)
                try {
                    $connection->query("DROP TRIGGER {$triggerQuoted}");
                } catch (\Throwable) {
                    // Trigger doesn't exist, continue
                }

                // Drop sequence if exists
                try {
                    $connection->query("DROP SEQUENCE {$seqQuoted}");
                } catch (\Throwable) {
                    // Sequence doesn't exist, continue
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isNoFieldsError(\PDOException $e): bool
    {
        // Oracle throws ORA-24374 when trying to fetch from a statement that doesn't return rows
        // This happens with DDL queries (CREATE, DROP, ALTER, etc.)
        $errorCode = $this->extractErrorCode($e);
        return $errorCode === '24374';
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
    public function normalizeRowKeys(array $rows, array $options = []): array
    {
        // Check if normalization is enabled
        if (!isset($options['normalize_row_keys']) || $options['normalize_row_keys'] !== true) {
            return $rows;
        }

        $normalized = [];
        foreach ($rows as $row) {
            $normalizedRow = [];
            foreach ($row as $key => $value) {
                // Convert CLOB resources to strings for Oracle
                if (is_resource($value) && get_resource_type($value) === 'stream') {
                    $value = stream_get_contents($value);
                }
                // Extract column name from expressions like TO_CHAR("table"."column") or TO_CHAR("column")
                // Pattern: TO_CHAR("table"."column") -> column
                // Pattern: TO_CHAR("column") -> column
                $normalizedKey = $key;
                if (preg_match('/^to_char\s*\(\s*"[^"]*"\s*\.\s*"([^"]+)"\s*\)$/i', $key, $matches)) {
                    // TO_CHAR("table"."column") -> column
                    $normalizedKey = $matches[1];
                } elseif (preg_match('/^to_char\s*\(\s*"([^"]+)"\s*\)$/i', $key, $matches)) {
                    // TO_CHAR("column") -> column
                    $normalizedKey = $matches[1];
                }
                $normalizedRow[strtolower($normalizedKey)] = $value;
            }
            $normalized[] = $normalizedRow;
        }
        return $normalized;
    }
}
