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
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\OracleExplainParser;
use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
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
    public function registerRegexpFunctions(PDO $pdo, bool $force = false): void
    {
        // Set Oracle session date/timestamp formats to match PHP's date() output
        // This allows datetime strings like "2025-11-25" and "2025-11-25 16:20:30"
        // to be inserted directly without TO_DATE/TO_TIMESTAMP wrappers
        try {
            $pdo->exec("ALTER SESSION SET NLS_DATE_FORMAT='YYYY-MM-DD'");
            $pdo->exec("ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS'");
        } catch (PDOException $e) {
            // Silently ignore errors - session settings are not critical
            // and may fail in restricted environments
        }
    }

    /**
     * {@inheritDoc}
     */
    public function transformValueForBinding(mixed $value, string $columnName): mixed
    {
        // Convert UUID strings to hex format for RAW columns
        // UUID format: 8-4-4-4-12 hexadecimal digits with hyphens
        // Oracle RAW type accepts hex strings (uppercase, no hyphens) via PDO binding
        if (is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            // Remove hyphens and convert to uppercase hex
            return strtoupper(str_replace('-', '', $value));
        }

        return $value;
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
        ConnectionInterface $connection,
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
        ConnectionInterface $connection,
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
    public function executeExplain(PDO $pdo, string $sql, array $params = []): array
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
    public function executeExplainAnalyze(PDO $pdo, string $sql, array $params = []): array
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
        // Oracle stores table names case-sensitively if created with quoted identifiers
        // Check both exact match and UPPER() match to handle both cases
        $tableEscaped = str_replace("'", "''", $table);
        return "SELECT COUNT(*) FROM USER_TABLES WHERE TABLE_NAME = '{$tableEscaped}' OR TABLE_NAME = UPPER('{$tableEscaped}')";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        // Oracle stores table names case-sensitively if created with quoted identifiers
        // Try both exact match and UPPER() match to handle both cases
        $tableEscaped = str_replace("'", "''", $table);
        return "SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, DATA_PRECISION, DATA_SCALE, NULLABLE, DATA_DEFAULT
                FROM USER_TAB_COLUMNS
                WHERE TABLE_NAME = '{$tableEscaped}' OR TABLE_NAME = UPPER('{$tableEscaped}')
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
        // Return column as-is for proper type handling
        // TO_CHAR() was breaking TIMESTAMP comparisons (ORA-01843)
        // CLOB comparisons work without TO_CHAR() in WHERE clauses
        return $column;
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
    public function formatToTimestamp(string $timestampString, string $format): string
    {
        $timestampEscaped = str_replace("'", "''", $timestampString);
        $formatEscaped = str_replace("'", "''", $format);
        return "TO_TIMESTAMP('{$timestampEscaped}', '{$formatEscaped}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatToDate(string|RawValue $dateString, string $format): string
    {
        // If it's a RawValue, use getValue() directly (it's already SQL)
        // If it's a string, treat it as a literal date string
        if ($dateString instanceof RawValue) {
            $dateExpr = $dateString->getValue();
        } else {
            $dateEscaped = str_replace("'", "''", $dateString);
            $dateExpr = "'{$dateEscaped}'";
        }
        $formatEscaped = str_replace("'", "''", $format);
        return "TO_DATE({$dateExpr}, '{$formatEscaped}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatToChar(string|RawValue $value): string
    {
        // If it's a RawValue, use getValue() directly (it's already SQL)
        // If it's a string, check if it looks like a column name (simple identifier)
        // or if it's already a SQL expression (contains parentheses, quotes, etc.)
        if ($value instanceof RawValue) {
            $val = $value->getValue();
        } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
            // Simple identifier - quote it
            $val = $this->quoteIdentifier($value);
        } else {
            // Already a SQL expression - use as is
            $val = $value;
        }
        return "TO_CHAR({$val})";
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
            if ($parts === false) {
                continue;
            }
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
        // Oracle stores table names case-sensitively if created with quoted identifiers
        // Try both exact match and UPPER() match to handle both cases
        $tableEscaped = str_replace("'", "''", $table);
        return "SELECT
            INDEX_NAME as index_name,
            TABLE_NAME as table_name,
            COLUMN_NAME as column_name,
            COLUMN_POSITION as column_position
            FROM USER_IND_COLUMNS
            WHERE TABLE_NAME = '{$tableEscaped}' OR TABLE_NAME = UPPER('{$tableEscaped}')
            ORDER BY INDEX_NAME, COLUMN_POSITION";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        // Oracle stores table names case-sensitively if created with quoted identifiers
        // Use UPPER() for both sides to handle case-insensitive comparison
        // This works for both quoted (case-sensitive) and unquoted (uppercase) table names
        $tableEscaped = str_replace("'", "''", $table);
        return "SELECT
            ucc.CONSTRAINT_NAME,
            ucc.COLUMN_NAME,
            uc.R_OWNER as REFERENCED_TABLE_SCHEMA,
            (SELECT TABLE_NAME FROM USER_CONSTRAINTS WHERE CONSTRAINT_NAME = uc.R_CONSTRAINT_NAME AND ROWNUM = 1) as REFERENCED_TABLE_NAME,
            (SELECT COLUMN_NAME FROM USER_CONS_COLUMNS WHERE CONSTRAINT_NAME = uc.R_CONSTRAINT_NAME AND POSITION = ucc.POSITION AND ROWNUM = 1) as REFERENCED_COLUMN_NAME
            FROM USER_CONS_COLUMNS ucc
            JOIN USER_CONSTRAINTS uc ON ucc.CONSTRAINT_NAME = uc.CONSTRAINT_NAME
            WHERE UPPER(uc.TABLE_NAME) = UPPER('{$tableEscaped}')
            AND uc.CONSTRAINT_TYPE = 'R'
            ORDER BY ucc.CONSTRAINT_NAME, ucc.POSITION";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        return "SELECT
            uc.CONSTRAINT_NAME,
            uc.CONSTRAINT_TYPE,
            uc.TABLE_NAME,
            ucc.COLUMN_NAME
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
    public function normalizeSqlForExecution(string $sql): string
    {
        // Oracle PDO doesn't support semicolons at the end of SQL statements
        // But PL/SQL blocks (BEGIN...END) require semicolon, so don't remove it for them
        if (!preg_match('/\bBEGIN\b.*\bEND\b/si', $sql)) {
            $sql = rtrim($sql, " \t\n\r\0\x0B;");
        }
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeRawValue(string $sql): string
    {
        /** @var string $sql */
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
                    // Check if it's a SQL keyword that should not be quoted
                    $upperArg = strtoupper($arg);
                    if (in_array($upperArg, ['NULL', 'TRUE', 'FALSE', 'DEFAULT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'SYSDATE', 'SYSTIMESTAMP'])) {
                        // It's a SQL keyword - keep as-is without quoting
                        $formattedArgs[] = $upperArg;
                    } else {
                        // It's an unquoted column identifier - quote and apply formatColumnForComparison
                        $quoted = $this->quoteIdentifier($arg);
                        $formattedArgs[] = $this->formatColumnForComparison($quoted);
                    }
                } else {
                    // It's an expression or something else - check if it contains CAST or CONCAT
                    // Apply CAST normalization if needed
                    if (preg_match('/CAST\s*\(([^,]+)\s+AS\s+([^)]+)\)/i', $arg, $castMatches)) {
                        $castExpr = trim($castMatches[1]);
                        $castType = trim($castMatches[2]);

                        // Check if CAST expression is a column identifier
                        $isCastQuotedIdentifier = preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $castExpr);
                        $isCastUnquotedIdentifier = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $castExpr);
                        $upperCastExpr = strtoupper($castExpr);
                        $isCastSqlKeyword = in_array($upperCastExpr, ['NULL', 'TRUE', 'FALSE', 'DEFAULT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'SYSDATE', 'SYSTIMESTAMP']);

                        if (($isCastQuotedIdentifier || $isCastUnquotedIdentifier) && !$isCastSqlKeyword) {
                            // It's a column - apply TO_CHAR() for CLOB compatibility before CAST
                            $castQuoted = $isCastQuotedIdentifier ? $castExpr : $this->quoteIdentifier($castExpr);
                            $castExprFormatted = $this->formatColumnForComparison($castQuoted);

                            // For numeric types (INTEGER, NUMBER, etc.), use CASE WHEN for safe casting
                            // Oracle CAST throws error on invalid values, so we need to catch it
                            if (preg_match('/^(INTEGER|NUMBER|DECIMAL|NUMERIC|REAL|FLOAT|DOUBLE)/i', $castType)) {
                                // Use CASE WHEN REGEXP_LIKE for safe numeric casting
                                // CASE WHEN REGEXP_LIKE(TO_CHAR(column), '^-?[0-9]+(\.[0-9]+)?$') THEN CAST(TO_CHAR(column) AS type) ELSE NULL END
                                $arg = "CASE WHEN REGEXP_LIKE($castExprFormatted, '^-?[0-9]+(\\.[0-9]+)?\$') THEN CAST($castExprFormatted AS $castType) ELSE NULL END";
                            } elseif (preg_match('/^(DATE|TIMESTAMP)/i', $castType)) {
                                // Use CASE WHEN with TO_DATE for safe date casting
                                // Try common date formats: YYYY-MM-DD, DD-MON-YYYY, etc.
                                // CASE WHEN REGEXP_LIKE(column, '^\d{4}-\d{2}-\d{2}') THEN TO_DATE(column, 'YYYY-MM-DD') ELSE NULL END
                                $arg = "CASE WHEN REGEXP_LIKE($castExprFormatted, '^\\d{4}-\\d{2}-\\d{2}') THEN TO_DATE($castExprFormatted, 'YYYY-MM-DD') ELSE NULL END";
                            } else {
                                // For other types, use CAST directly
                                $arg = preg_replace('/CAST\s*\(' . preg_quote($castExpr, '/') . '\s+AS\s+' . preg_quote($castType, '/') . '\)/i', "CAST($castExprFormatted AS $castType)", $arg);
                            }
                        }
                    }
                    // Check if it contains || operator (Oracle concatenation)
                    // 'Name: ' || name -> 'Name: ' || TO_CHAR("NAME")
                    if ($arg !== null && preg_match('/\|\|/', $arg)) {
                        // Handle || operator (Oracle concatenation)
                        // Split by || but respect quoted strings
                        $parts = [];
                        $current = '';
                        $inQuotes = false;
                        $quoteChar = '';
                        $argLength = strlen($arg);
                        for ($i = 0; $i < $argLength; $i++) {
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
                            } elseif ($char === '|' && $i + 1 < $argLength && $arg[$i + 1] === '|' && !$inQuotes) {
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
                                // Check if it's a SQL keyword that should not be quoted
                                $upperPart = strtoupper($part);
                                if (in_array($upperPart, ['NULL', 'TRUE', 'FALSE', 'DEFAULT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'SYSDATE', 'SYSTIMESTAMP'])) {
                                    // It's a SQL keyword - keep as-is without quoting
                                    $formattedParts[] = $upperPart;
                                } else {
                                    // It's an unquoted column identifier - quote and apply formatColumnForComparison
                                    $quoted = $this->quoteIdentifier($part);
                                    $formattedParts[] = $this->formatColumnForComparison($quoted);
                                }
                            } else {
                                // It's already an expression or quoted identifier - keep as-is
                                $formattedParts[] = $part;
                            }
                        }
                        // Rebuild with || operator
                        $arg = implode(' || ', $formattedParts);
                    } elseif ($arg !== null && preg_match('/CONCAT\s*\(([^)]+)\)/i', $arg, $concatMatches)) {
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
                $result = preg_replace('/CAST\s*\(' . preg_quote($expr, '/') . '\s+AS\s+' . preg_quote($type, '/') . '\)/i', "CAST($exprFormatted AS $type)", $sql);
                if (is_string($result)) {
                    $sql = $result;
                }
            }
        }

        // Oracle-specific normalization: handle LOG10() -> LOG(10, ...)
        // Oracle doesn't support LOG10(), use LOG(10, ...) instead
        if (preg_match('/LOG10\s*\(/i', $sql)) {
            $result = preg_replace('/LOG10\s*\(/i', 'LOG(10, ', $sql);
            if (is_string($result)) {
                $sql = $result;
            }
        }

        // Oracle-specific normalization: handle TRUE/FALSE -> 1/0
        // Oracle doesn't support TRUE/FALSE constants, use 1/0 instead
        if ($sql !== '') {
            $result = preg_replace('/\bTRUE\b/i', '1', $sql);
            if (is_string($result)) {
                $sql = $result;
            }
            $result = preg_replace('/\bFALSE\b/i', '0', $sql);
            if (is_string($result)) {
                $sql = $result;
            }
        }

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
    public function getDatabaseInfo(PdoDb $db): array
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
    public function createUser(string $username, string $password, ?string $host, PdoDb $db): bool
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
    public function dropUser(string $username, ?string $host, PdoDb $db): bool
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
    public function userExists(string $username, ?string $host, PdoDb $db): bool
    {
        // Oracle doesn't use host in user checks
        $sql = 'SELECT COUNT(*) FROM ALL_USERS WHERE USERNAME = UPPER(?)';
        $count = $db->rawQueryValue($sql, [$username]);
        return (int)$count > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function listUsers(PdoDb $db): array
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
    public function getUserInfo(string $username, ?string $host, PdoDb $db): array
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
        PdoDb $db
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
        PdoDb $db
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
    public function changeUserPassword(string $username, string $newPassword, ?string $host, PdoDb $db): bool
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
    public function extractEnumValues(array $column, PdoDb $db, string $tableName, string $columnName): array
    {
        // Oracle does not support ENUM types natively
        // Return empty array to indicate no ENUM values
        return [];
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
            $rows = $db->rawQuery('SELECT TABLE_NAME FROM USER_TABLES ORDER BY TABLE_NAME');
            foreach ($rows as $row) {
                // Oracle may return keys in different cases depending on PDO settings
                $tableName = $row['TABLE_NAME'] ?? $row['table_name'] ?? $row['Table_Name'] ?? null;
                if ($tableName !== null) {
                    $tables[] = (string)$tableName;
                }
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

            // Normalize column keys to uppercase (PDO returns them in lowercase by default)
            $columns = array_map(fn ($row) => array_change_key_case($row, CASE_UPPER), $columns);

            $colDefs = [];
            foreach ($columns as $col) {
                $colName = $this->quoteIdentifier((string)$col['COLUMN_NAME']);
                $dataType = (string)$col['DATA_TYPE'];
                $nullable = (string)$col['NULLABLE'] === 'Y';
                $default = $col['DATA_DEFAULT'] !== null ? (string)$col['DATA_DEFAULT'] : null;

                // Build type with length/precision
                // Skip if type already contains precision (e.g., TIMESTAMP(6))
                if (!preg_match('/\(\d+\)/', $dataType)) {
                    // NUMBER types use DATA_PRECISION/DATA_SCALE
                    if (stripos($dataType, 'NUMBER') !== false && $col['DATA_PRECISION'] !== null) {
                        $dataType .= '(' . (int)$col['DATA_PRECISION'];
                        if ($col['DATA_SCALE'] !== null && (int)$col['DATA_SCALE'] > 0) {
                            $dataType .= ',' . (int)$col['DATA_SCALE'];
                        }
                        $dataType .= ')';
                    }
                    // VARCHAR2, CHAR, etc. use DATA_LENGTH
                    elseif (stripos($dataType, 'CHAR') !== false && $col['DATA_LENGTH'] !== null) {
                        $dataType .= '(' . (int)$col['DATA_LENGTH'] . ')';
                    }
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

            // Add primary key constraint
            $pkRows = $db->rawQuery(
                "SELECT ac.CONSTRAINT_NAME, acc.COLUMN_NAME
                 FROM USER_CONSTRAINTS ac
                 JOIN USER_CONS_COLUMNS acc ON ac.CONSTRAINT_NAME = acc.CONSTRAINT_NAME
                 WHERE ac.TABLE_NAME = UPPER(?) AND ac.CONSTRAINT_TYPE = 'P'
                 ORDER BY acc.POSITION",
                [$tableName]
            );
            $pkRows = array_map(fn ($row) => array_change_key_case($row, CASE_UPPER), $pkRows);

            if (!empty($pkRows)) {
                $pkName = $this->quoteIdentifier((string)$pkRows[0]['CONSTRAINT_NAME']);
                $pkCols = array_map(fn ($r) => $this->quoteIdentifier((string)$r['COLUMN_NAME']), $pkRows);
                $output[] = "ALTER TABLE {$quotedTable} ADD CONSTRAINT {$pkName} PRIMARY KEY (" . implode(', ', $pkCols) . ');';
            }

            // Get indexes
            $indexRows = $db->rawQuery(
                'SELECT INDEX_NAME, INDEX_TYPE, UNIQUENESS
                 FROM USER_INDEXES
                 WHERE TABLE_NAME = UPPER(?)
                 ORDER BY INDEX_NAME',
                [$tableName]
            );
            // Normalize keys to uppercase
            $indexRows = array_map(fn ($row) => array_change_key_case($row, CASE_UPPER), $indexRows);

            foreach ($indexRows as $idxRow) {
                $indexName = (string)$idxRow['INDEX_NAME'];

                // Skip system-generated indexes (SYS_C*)
                // Oracle auto-creates these for PK and UNIQUE constraints
                if (str_starts_with($indexName, 'SYS_C')) {
                    continue;
                }

                // Get index columns
                $idxCols = $db->rawQuery(
                    'SELECT COLUMN_NAME, COLUMN_POSITION
                     FROM USER_IND_COLUMNS
                     WHERE INDEX_NAME = ?
                     ORDER BY COLUMN_POSITION',
                    [$indexName]
                );
                // Normalize keys to uppercase
                $idxCols = array_map(fn ($row) => array_change_key_case($row, CASE_UPPER), $idxCols);

                $colNames = array_map(fn ($c) => $this->quoteIdentifier((string)$c['COLUMN_NAME']), $idxCols);
                $unique = (string)$idxRow['UNIQUENESS'] === 'UNIQUE' ? 'UNIQUE ' : '';
                $idxName = $this->quoteIdentifier($indexName);
                $output[] = "CREATE {$unique}INDEX {$idxName} ON {$quotedTable} (" . implode(', ', $colNames) . ');';
            }
        }

        // Add foreign key constraints
        $fkQuery = "SELECT
                ac.CONSTRAINT_NAME,
                ac.TABLE_NAME,
                acc.COLUMN_NAME,
                ac.R_CONSTRAINT_NAME,
                ac.DELETE_RULE,
                ac2.TABLE_NAME AS R_TABLE_NAME,
                acc2.COLUMN_NAME AS R_COLUMN_NAME
            FROM USER_CONSTRAINTS ac
            JOIN USER_CONS_COLUMNS acc ON ac.CONSTRAINT_NAME = acc.CONSTRAINT_NAME
            JOIN USER_CONSTRAINTS ac2 ON ac.R_CONSTRAINT_NAME = ac2.CONSTRAINT_NAME
            JOIN USER_CONS_COLUMNS acc2 ON ac2.CONSTRAINT_NAME = acc2.CONSTRAINT_NAME
            WHERE ac.CONSTRAINT_TYPE = 'R'";

        if ($table !== null) {
            $fkQuery .= ' AND ac.TABLE_NAME = ?';
            $fkRows = $db->rawQuery($fkQuery, [mb_strtoupper($table, 'UTF-8')]);
        } else {
            $fkQuery .= ' ORDER BY ac.TABLE_NAME, ac.CONSTRAINT_NAME';
            $fkRows = $db->rawQuery($fkQuery);
        }

        // Normalize keys to uppercase
        $fkRows = array_map(fn ($row) => array_change_key_case($row, CASE_UPPER), $fkRows);

        foreach ($fkRows as $fk) {
            $fkName = $this->quoteIdentifier((string)$fk['CONSTRAINT_NAME']);
            $fkTable = $this->quoteIdentifier((string)$fk['TABLE_NAME']);
            $fkColumn = $this->quoteIdentifier((string)$fk['COLUMN_NAME']);
            $refTable = $this->quoteIdentifier((string)$fk['R_TABLE_NAME']);
            $refColumn = $this->quoteIdentifier((string)$fk['R_COLUMN_NAME']);
            $deleteRule = (string)$fk['DELETE_RULE'];

            $fkSql = "ALTER TABLE {$fkTable} ADD CONSTRAINT {$fkName} FOREIGN KEY ({$fkColumn}) REFERENCES {$refTable} ({$refColumn})";
            if ($deleteRule === 'CASCADE') {
                $fkSql .= ' ON DELETE CASCADE';
            } elseif ($deleteRule === 'SET NULL') {
                $fkSql .= ' ON DELETE SET NULL';
            }
            $output[] = $fkSql . ';';
        }

        return implode("\n\n", $output);
    }

    /**
     * {@inheritDoc}
     */
    public function dumpData(PdoDb $db, ?string $table = null): string
    {
        $output = [];
        $tables = [];

        if ($table !== null) {
            $tables = [$table];
        } else {
            $rows = $db->rawQuery('SELECT TABLE_NAME FROM USER_TABLES ORDER BY TABLE_NAME');
            foreach ($rows as $row) {
                // Oracle may return keys in different cases depending on PDO settings
                $tableName = $row['TABLE_NAME'] ?? $row['table_name'] ?? $row['Table_Name'] ?? null;
                if ($tableName !== null) {
                    $tables[] = (string)$tableName;
                }
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

            // Oracle doesn't support multi-row INSERT VALUES syntax
            // Use INSERT ALL ... SELECT FROM DUAL instead
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
                $batch[] = "  INTO {$quotedTable} ({$columnsList}) VALUES (" . implode(', ', $values) . ')';

                if (count($batch) >= $batchSize) {
                    $output[] = "INSERT ALL\n" . implode("\n", $batch) . "\nSELECT * FROM DUAL;";
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                $output[] = "INSERT ALL\n" . implode("\n", $batch) . "\nSELECT * FROM DUAL;";
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreFromSql(PdoDb $db, string $sql, bool $continueOnError = false): void
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
                $errorMsg = $e->getMessage();
                $isDrop = stripos($stmt, 'DROP TABLE') === 0 || stripos($stmt, 'DROP ') === 0;
                $isTableNotFound = str_contains($errorMsg, 'ORA-00942') || str_contains($errorMsg, 'table or view does not exist');

                // Ignore "table does not exist" errors for DROP statements
                if ($isDrop && $isTableNotFound) {
                    continue;
                }

                if (!$continueOnError) {
                    throw new ResourceException('Failed to execute SQL statement: ' . $e->getMessage() . "\nStatement: " . substr($stmt, 0, 200));
                }
                $errors[] = $errorMsg;
            }
        }

        if (!empty($errors) && $continueOnError) {
            throw new ResourceException('Restore completed with ' . count($errors) . ' errors. First error: ' . $errors[0]);
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
    public function getActiveQueries(PdoDb $db): array
    {
        // V$SESSION requires SELECT_CATALOG_ROLE or DBA privileges
        // Return empty array if user doesn't have access
        try {
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
        } catch (\Exception $e) {
            // User doesn't have required privileges
            return [];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveConnections(PdoDb $db): array
    {
        // V$SESSION requires SELECT_CATALOG_ROLE or DBA privileges
        // Return empty result if user doesn't have access
        try {
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
        } catch (\Exception $e) {
            // User doesn't have required privileges
            return [
                'connections' => [],
                'summary' => [
                    'current' => 0,
                    'max' => 0,
                    'usage_percent' => 0,
                ],
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getServerMetrics(PdoDb $db): array
    {
        $metrics = [];

        try {
            // Get version
            $version = $db->rawQueryValue('SELECT banner FROM v$version WHERE rownum = 1');
            $metrics['version'] = is_string($version) ? $version : 'unknown';

            // Get instance info
            $instance = $db->rawQuery('SELECT instance_name, host_name, status FROM v$instance');
            if (!empty($instance)) {
                $inst = $instance[0];
                $metrics['instance_name'] = $inst['instance_name'] ?? 'unknown';
                $metrics['host_name'] = $inst['host_name'] ?? 'unknown';
                $metrics['status'] = $inst['status'] ?? 'unknown';
            }

            // Get session stats
            $sessions = $db->rawQueryValue('SELECT COUNT(*) FROM v$session');
            $metrics['sessions'] = is_int($sessions) ? $sessions : (is_string($sessions) ? (int)$sessions : 0);

            // Get uptime
            $uptime = $db->rawQueryValue('
                SELECT ROUND((SYSDATE - startup_time) * 24 * 60 * 60) as uptime_seconds
                FROM v$instance
            ');
            $metrics['uptime_seconds'] = is_int($uptime) ? $uptime : (is_string($uptime) ? (int)$uptime : 0);
        } catch (\Throwable $e) {
            // Return empty metrics on error
            $metrics['error'] = $e->getMessage();
        }

        return $metrics;
    }

    /**
     * {@inheritDoc}
     */
    public function getServerVariables(PdoDb $db): array
    {
        try {
            $rows = $db->rawQuery('
                SELECT name, value
                FROM v$parameter
                ORDER BY name
            ');
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
    public function getSlowQueries(PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        // V$SQLAREA requires SELECT_CATALOG_ROLE or DBA privileges
        // Return empty array if user doesn't have access
        try {
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
        } catch (\Exception $e) {
            // User doesn't have required privileges
            return [];
        }
    }

    /* ---------------- Table Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function listTables(PdoDb $db, ?string $schema = null): array
    {
        $schemaName = $schema ?? 'USER';
        if ($schemaName === 'USER') {
            // Exclude Oracle system tables and recycle bin objects for better performance
            $sql = "SELECT TABLE_NAME FROM USER_TABLES
                    WHERE TABLE_NAME NOT LIKE 'BIN$%'
                    AND TABLE_NAME NOT LIKE 'SYS_%'
                    AND TABLE_NAME NOT IN ('DUAL')
                    ORDER BY TABLE_NAME";
            $rows = $db->rawQuery($sql);
        } else {
            $sql = 'SELECT TABLE_NAME FROM ALL_TABLES WHERE OWNER = UPPER(:schema) ORDER BY TABLE_NAME';
            $rows = $db->rawQuery($sql, [':schema' => $schemaName]);
        }
        /** @var array<int, string> $names */
        $names = array_map(static function (array $r): string {
            // Oracle may return keys in different cases depending on PDO settings
            // Check both uppercase and lowercase variants
            if (isset($r['TABLE_NAME'])) {
                return (string)$r['TABLE_NAME'];
            }
            if (isset($r['table_name'])) {
                return (string)$r['table_name'];
            }
            if (isset($r['Table_Name'])) {
                return (string)$r['Table_Name'];
            }
            // Fallback: try to get first value if key format is unexpected
            $values = array_values($r);
            if (!empty($values)) {
                return (string)$values[0];
            }
            return '';
        }, $rows);
        // Filter out empty strings and system tables
        return array_filter($names, static function (string $name): bool {
            if ($name === '') {
                return false;
            }
            // Additional filtering for system tables (case-insensitive)
            $nameUpper = strtoupper($name);
            // Skip Oracle system tables and recycle bin
            if (str_starts_with($nameUpper, 'BIN$') ||
                str_starts_with($nameUpper, 'SYS_') ||
                $nameUpper === 'DUAL') {
                return false;
            }
            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getPrimaryKeyColumns(PdoDb $db, string $table): array
    {
        // Oracle: use constraints instead of indexes (PRIMARY KEY is a constraint, not an index)
        try {
            // Oracle stores table names case-sensitively if created with quoted identifiers
            // Try both exact match and UPPER() match to handle both cases
            $pkRows = $db->rawQuery(
                "SELECT acc.COLUMN_NAME
                 FROM USER_CONSTRAINTS ac
                 JOIN USER_CONS_COLUMNS acc ON ac.CONSTRAINT_NAME = acc.CONSTRAINT_NAME
                 WHERE (ac.TABLE_NAME = ? OR ac.TABLE_NAME = UPPER(?)) AND ac.CONSTRAINT_TYPE = 'P'
                 ORDER BY acc.POSITION",
                [$table, $table]
            );

            // Normalize keys to uppercase (same as in buildDumpSql)
            $pkRows = array_map(static fn (array $row): array => array_change_key_case($row, CASE_UPPER), $pkRows);

            $columns = [];
            foreach ($pkRows as $row) {
                $columnName = $row['COLUMN_NAME'] ?? null;
                if (is_string($columnName) && $columnName !== '') {
                    $columns[] = $columnName;
                }
            }

            return $columns;
        } catch (\Exception $e) {
            return [];
        }
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

        // Oracle specific options
        if (isset($envVars['PDODB_SERVICE_NAME'])) {
            $config['service_name'] = $envVars['PDODB_SERVICE_NAME'];
        }
        if (isset($envVars['PDODB_SID'])) {
            $config['sid'] = $envVars['PDODB_SID'];
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
        /** @var string $sql */

        // Oracle requires CTE name qualification instead of aliases in recursive part
        // Extract unquoted CTE name for pattern matching
        $unquotedCteName = trim($cteName, '"');
        $unquotedCteNameUpper = strtoupper($unquotedCteName);

        // Oracle requires column list for recursive CTEs (ORA-32039 without it)
        // When column list is present, column aliases in SELECT must be removed
        // Pattern: AS identifier or AS "identifier" (case-insensitive)
        // This removes aliases like "0 as level" -> "0" or "name AS \"PATH\"" -> "name"
        $result = preg_replace('/\s+AS\s+(?:"[^"]+"|[a-zA-Z_][a-zA-Z0-9_]*)/i', '', $sql);
        if (is_string($result)) {
            $sql = $result;
        }

        // Replace aliases in recursive part (after UNION ALL)
        // Pattern: "CT"."COLUMN" or ct.column -> CTE_NAME.COLUMN
        if (preg_match('/UNION\s+ALL\s+(.+)$/is', $sql, $matches)) {
            $recursivePart = $matches[1];

            // Extract CTE alias from JOIN clause to avoid false matches
            // Pattern: JOIN "CTE_NAME" "ALIAS" or JOIN cte_name alias
            $cteAlias = null;
            if (preg_match('/JOIN\s+"?' . preg_quote($unquotedCteNameUpper, '/') . '"?\s+"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i', $recursivePart, $joinMatch)) {
                $cteAlias = strtoupper($joinMatch[1]);
            }

            // Replace quoted aliases like "CT" with CTE name
            // Match pattern: "ALIAS"."COLUMN" where ALIAS matches CTE name
            $result = preg_replace_callback(
                '/"([^"]+)"\s*\.\s*"([^"]+)"/i',
                function ($m) use ($cteName, $unquotedCteNameUpper, $cteAlias) {
                    $alias = strtoupper($m[1]);
                    $column = $m[2];
                    // Check if alias matches CTE name or the detected CTE alias
                    $isCtReference = (
                        $alias === $unquotedCteNameUpper ||
                        ($cteAlias !== null && $alias === $cteAlias)
                    );

                    if ($isCtReference) {
                        return $cteName . '.' . $this->quoteIdentifier($column);
                    }
                    return $m[0];
                },
                $recursivePart
            );
            if (is_string($result)) {
                $recursivePart = $result;
            }
            // Replace unquoted aliases like ct.column or ct.level
            // Match pattern: alias.column (not in quotes)
            $result = preg_replace_callback(
                '/(\b)([a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*([a-zA-Z_][a-zA-Z0-9_]*|\*)(\b)/',
                function ($m) use ($cteName, $unquotedCteNameUpper, $cteAlias) {
                    $alias = strtoupper($m[2]);
                    $column = $m[3];
                    // Check if alias matches CTE name or the detected CTE alias
                    $isCtReference = (
                        $alias === $unquotedCteNameUpper ||
                        ($cteAlias !== null && $alias === $cteAlias)
                    );

                    if ($isCtReference) {
                        $quotedColumn = $column === '*' ? '*' : $this->quoteIdentifier($column);
                        return $m[1] . $cteName . '.' . $quotedColumn . $m[4];
                    }
                    return $m[0];
                },
                $recursivePart
            );
            if (is_string($result)) {
                $recursivePart = $result;
            }
            // Remove aliases from CTE references in JOIN
            // Oracle doesn't allow aliases for CTE in recursive queries
            // Change: INNER JOIN "TREE" "T" -> INNER JOIN "TREE"
            if ($recursivePart !== '') {
                $result = preg_replace_callback(
                    '/INNER\s+JOIN\s+"([^"]+)"\s+"([^"]+)"/i',
                    function ($m) use ($cteName, $unquotedCteNameUpper) {
                        $tableName = strtoupper($m[1]);
                        // If table name matches CTE name, remove the alias
                        if ($tableName === $unquotedCteNameUpper) {
                            return 'INNER JOIN ' . $cteName;
                        }
                        return $m[0];
                    },
                    $recursivePart
                );
                if (is_string($result)) {
                    $recursivePart = $result;
                }
                if ($recursivePart !== '') {
                    $result = preg_replace('/UNION\s+ALL\s+.+$/is', 'UNION ALL ' . $recursivePart, $sql);
                    if (is_string($result)) {
                        $sql = $result;
                    }
                }
            }
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
    public function getInsertSelectColumns(string $tableName, ?array $columns, ExecutionEngineInterface $executionEngine): array
    {
        // Oracle doesn't have IDENTITY columns like MSSQL, return columns as-is
        return $columns ?? [];
    }

    /**
     * Create triggers for auto-increment columns.
     *
     * @param ConnectionInterface $connection Database connection
     * @param string $tableName Table name
     * @param array<string, ColumnSchema|array<string, mixed>|string> $columns Column definitions
     */
    protected function createTriggersForAutoIncrement(
        ConnectionInterface $connection,
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
     * @param ColumnSchema|array<string, mixed>|string $def Column definition
     *
     * @return ColumnSchema
     */
    protected function normalizeColumnSchemaForTrigger(ColumnSchema|array|string $def): ColumnSchema
    {
        if ($def instanceof ColumnSchema) {
            return $def;
        }

        if (is_array($def)) {
            $schemaType = $def['type'] ?? 'VARCHAR';
            $length = $def['length'] ?? $def['size'] ?? null;
            $scale = $def['scale'] ?? null;

            $schema = new ColumnSchema((string)$schemaType, $length, $scale);

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

        return new ColumnSchema((string)$def);
    }

    /**
     * Drop sequences and triggers for auto-increment columns before table creation.
     *
     * @param ConnectionInterface $connection Database connection
     * @param string $tableName Table name
     * @param array<string, ColumnSchema|array<string, mixed>|string> $columns Column definitions
     */
    protected function dropSequencesAndTriggers(
        ConnectionInterface $connection,
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
    public function isNoFieldsError(PDOException $e): bool
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

                // Only apply regex normalization to string keys (not numeric)
                if (is_string($key)) {
                    if (preg_match('/^to_char\s*\(\s*"[^"]*"\s*\.\s*"([^"]+)"\s*\)$/i', $key, $matches)) {
                        // TO_CHAR("table"."column") -> column
                        $normalizedKey = $matches[1];
                    } elseif (preg_match('/^to_char\s*\(\s*"([^"]+)"\s*\)$/i', $key, $matches)) {
                        // TO_CHAR("column") -> column
                        $normalizedKey = $matches[1];
                    }
                }

                // For numeric keys, keep them as-is, otherwise convert to lowercase
                $finalKey = is_int($normalizedKey) ? $normalizedKey : strtolower((string)$normalizedKey);
                $normalizedRow[$finalKey] = $value;
            }
            $normalized[] = $normalizedRow;
        }
        return $normalized;
    }

    /**
     * {@inheritDoc}
     */
    public function killQuery(PdoDb $db, int|string $processId): bool
    {
        // Oracle requires SID and SERIAL# for killing sessions
        // This is a simplified version - in practice, you'd need both values
        // For now, we'll try to extract from processId if it's in format "sid,serial"
        try {
            if (is_string($processId) && str_contains($processId, ',')) {
                [$sid, $serial] = explode(',', $processId, 2);
                $sidInt = (int)$sid;
                $serialInt = (int)$serial;
                $db->rawQuery("ALTER SYSTEM KILL SESSION '{$sidInt},{$serialInt}'");
                return true;
            }
            // If only one value provided, try to use it as SID (may not work)
            $sid = is_int($processId) ? $processId : (int)$processId;
            $db->rawQuery("ALTER SYSTEM KILL SESSION '{$sid},0'");
            return true;
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
            // Oracle: TO_CHAR(column) LIKE pattern
            return "(TO_CHAR({$columnName}) LIKE {$paramKey})";
        }

        if ($isArray && $searchInJson) {
            // Oracle: TO_CHAR(column) LIKE pattern for array types
            return "(TO_CHAR({$columnName}) LIKE {$paramKey})";
        }

        if ($isNumeric) {
            if (is_numeric($searchTerm)) {
                $exactParamKey = ':exact_' . count($params);
                $params[$exactParamKey] = $searchTerm;
                return "({$columnName} = {$exactParamKey} OR TO_CHAR({$columnName}) LIKE {$paramKey})";
            }
            return "(TO_CHAR({$columnName}) LIKE {$paramKey})";
        }

        // Text columns: Oracle LIKE is case-sensitive by default
        // Use UPPER() for case-insensitive search
        $upperColumn = "UPPER({$columnName})";
        $upperParamKey = ':upper_search_' . count($params);
        $params[$upperParamKey] = '%' . strtoupper($searchTerm) . '%';
        return "({$upperColumn} LIKE {$upperParamKey})";
    }
}
