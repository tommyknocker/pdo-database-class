<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use InvalidArgumentException;
use PDO;
use PDOException;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\MSSQLExplainParser;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class MSSQLDialect extends DialectAbstract
{
    /** @var MSSQLFeatureSupport Feature support instance */
    private MSSQLFeatureSupport $featureSupport;

    /** @var MSSQLSqlFormatter SQL formatter instance */
    private MSSQLSqlFormatter $sqlFormatter;

    /** @var MSSQLDmlBuilder DML builder instance */
    private MSSQLDmlBuilder $dmlBuilder;

    /** @var MSSQLDdlBuilder DDL builder instance */
    private MSSQLDdlBuilder $ddlBuilder;

    public function __construct()
    {
        $this->featureSupport = new MSSQLFeatureSupport();
        $this->sqlFormatter = new MSSQLSqlFormatter($this);
        $this->dmlBuilder = new MSSQLDmlBuilder($this);
        $this->ddlBuilder = new MSSQLDdlBuilder($this);
    }

    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'sqlsrv';
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
    public function formatLateralJoin(string $tableSql, string $type, string $aliasQuoted, string|RawValue|null $condition = null): string
    {
        return $this->sqlFormatter->formatLateralJoin($tableSql, $type, $aliasQuoted, $condition);
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
        foreach (['host', 'dbname', 'username', 'password'] as $requiredParam) {
            if (empty($params[$requiredParam])) {
                throw new InvalidArgumentException("Missing '$requiredParam' parameter");
            }
        }
        $port = $params['port'] ?? 1433;
        $dsn = "sqlsrv:Server={$params['host']},{$port};Database={$params['dbname']}";

        // Add SSL options (default to TrustServerCertificate=yes for self-signed certs)
        $trustCert = $params['trust_server_certificate'] ?? true;
        $encrypt = $params['encrypt'] ?? true;
        $dsn .= ';TrustServerCertificate=' . ($trustCert ? 'yes' : 'no');
        $dsn .= ';Encrypt=' . ($encrypt ? 'yes' : 'no');

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
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(mixed $name): string
    {
        return $name instanceof RawValue ? $name->getValue() : "[{$name}]";
    }

    /**
     * {@inheritDoc}
     */
    public function quoteTable(mixed $table): string
    {
        // Support for schema.table and alias: [schema].[table] AS [t]
        $parts = explode(' ', $table, 3);
        $name = $parts[0];
        $alias = $parts[1] ?? null;

        $segments = explode('.', $name);
        $quotedSegments = array_map(static fn ($s) => '[' . str_replace(']', ']]', $s) . ']', $segments);
        $quoted = implode('.', $quotedSegments);

        if ($alias) {
            return $quoted . ' ' . implode(' ', array_slice($parts, 1));
        }
        return $quoted;
    }

    /**
     * {@inheritDoc}
     * MSSQL: NTEXT/NVARCHAR(MAX) doesn't work with LOWER directly, need CAST to NVARCHAR(MAX).
     */
    public function ilike(string $column, string $pattern): RawValue
    {
        // Use CAST to NVARCHAR(MAX) to ensure compatibility with NTEXT, NVARCHAR(MAX), TEXT, etc.
        return new RawValue("LOWER(CAST($column AS NVARCHAR(MAX))) LIKE LOWER(CAST(:pattern AS NVARCHAR(MAX)))", ['pattern' => $pattern]);
    }

    /**
     * {@inheritDoc}
     */
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
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
        return $this->sqlFormatter->formatLimitOffset($sql, $limit, $offset);
    }

    /**
     * {@inheritDoc}
     */
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
    public function buildReplaceSql(string $table, array $columns, array $placeholders, bool $isMultiple = false): string
    {
        return $this->dmlBuilder->buildReplaceSql($table, $columns, $placeholders, $isMultiple);
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
    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string
    {
        return $this->sqlFormatter->formatMod($dividend, $divisor);
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
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        return $this->sqlFormatter->formatMaterializedCte($cteSql, $isMaterialized);
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
    public function normalizeRawValue(string $sql): string
    {
        return $this->sqlFormatter->normalizeRawValue($sql);
    }

    /**
     * {@inheritDoc}
     */
    public function registerRegexpFunctions(PDO $pdo, bool $force = false): void
    {
        $this->sqlFormatter->registerRegexpFunctions($pdo, $force);
    }

    /**
     * {@inheritDoc}
     */
    public function concat(ConcatValue $value): RawValue
    {
        $parts = $value->getValues();
        $mapped = [];

        foreach ($parts as $part) {
            if ($part instanceof RawValue) {
                $mapped[] = $part->getValue();
                continue;
            }

            if (is_numeric($part)) {
                // MSSQL requires explicit CAST for concatenating numbers with strings
                // Convert number to string using CAST
                $mapped[] = "CAST({$part} AS NVARCHAR)";
                continue;
            }

            $s = (string)$part;

            // already quoted literal?
            if (preg_match("/^'.*'\$/s", $s) || preg_match('/^".*"\$/s', $s)) {
                $mapped[] = $s;
                continue;
            }

            // Check if it's a simple string literal:
            // - Contains spaces OR special chars like : ; ! ? etc. (but not SQL operators)
            // - Does NOT contain SQL operators or parentheses
            // - Does NOT look like SQL keywords
            // Simple words without spaces/special chars are treated as identifiers, not literals
            $isSimpleWord = preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $s) && !str_contains($s, '.');
            $isSqlKeyword = preg_match('/^(SELECT|FROM|WHERE|AND|OR|JOIN|NULL|TRUE|FALSE|AS|ON|IN|IS|LIKE|BETWEEN|EXISTS|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TABLE|INDEX|PRIMARY|KEY|FOREIGN|CONSTRAINT|UNIQUE|CHECK|DEFAULT|NOT|GROUP|BY|ORDER|HAVING|LIMIT|OFFSET|DISTINCT|COUNT|SUM|AVG|MAX|MIN)$/i', $s);

            // Only quote as string literal if it contains spaces or special chars (not operators)
            // Simple words are treated as identifiers
            if (
                preg_match('/[\s:;!?@#\$&]/', $s) &&
                !preg_match('/[()%<>=+*\/]/', $s)
            ) {
                // Quote as string literal
                $mapped[] = "'" . str_replace("'", "''", $s) . "'";
                continue;
            }

            // contains parentheses, math/comparison operators — treat as raw SQL
            if (preg_match('/[()%<>=]/', $s)) {
                $mapped[] = $s;
                continue;
            }

            // simple identifier — quote with square brackets
            $pieces = explode('.', $s);
            foreach ($pieces as &$p) {
                $p = '[' . str_replace(']', ']]', $p) . ']';
            }
            $mapped[] = implode('.', $pieces);
        }

        // MSSQL uses + operator for concatenation
        $sql = implode(' + ', $mapped);

        return new RawValue($sql);
    }

    /**
     * {@inheritDoc}
     */
    public function now(?string $diff = '', bool $asTimestamp = false): string
    {
        if ($diff === '' || $diff === null) {
            if ($asTimestamp) {
                // MSSQL doesn't have UNIX_TIMESTAMP, use DATEDIFF(SECOND, '1970-01-01', GETDATE())
                return "DATEDIFF(SECOND, '1970-01-01', GETDATE())";
            }
            return 'GETDATE()';
        }

        // Parse diff like "+1 DAY" or "-2 MONTH"
        preg_match('/^([+-]?)\s*(\d+)\s+(\w+)$/i', $diff, $matches);
        if (count($matches) === 4) {
            $sign = $matches[1] === '-' ? '-' : '';
            $value = $matches[2];
            $unit = strtoupper($matches[3]);

            $mssqlUnit = match ($unit) {
                'DAY' => 'day',
                'MONTH' => 'month',
                'YEAR' => 'year',
                'HOUR' => 'hour',
                'MINUTE' => 'minute',
                'SECOND' => 'second',
                'WEEK' => 'week',
                default => 'day',
            };

            $dateExpr = "DATEADD({$mssqlUnit}, {$sign}{$value}, GETDATE())";
            if ($asTimestamp) {
                // Convert to Unix timestamp
                return "DATEDIFF(SECOND, '1970-01-01', {$dateExpr})";
            }
            return $dateExpr;
        }

        if ($asTimestamp) {
            return "DATEDIFF(SECOND, '1970-01-01', GETDATE())";
        }
        return 'GETDATE()';
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainSql(string $query): string
    {
        // MSSQL uses SET SHOWPLAN_ALL ON
        // Note: SET SHOWPLAN statements must be the only statements in the batch
        // So we return just the query - the caller should handle SET SHOWPLAN separately
        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainAnalyzeSql(string $query): string
    {
        // MSSQL uses SET STATISTICS XML ON for explain analyze
        // Return the query as-is; executeExplainAnalyze will handle SET STATISTICS XML ON/OFF
        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplain(PDO $pdo, string $sql, array $params = []): array
    {
        // MSSQL SET SHOWPLAN_ALL ON doesn't work well with PDO prepared statements
        // Return a minimal execution plan structure to satisfy the API
        // In production, consider using sys.dm_exec_query_plan or SET STATISTICS XML ON
        return [
            [
                'StmtText' => $sql,
                'Rows' => 0,
                'EstimateRows' => 0,
                'TotalSubtreeCost' => 0,
                'StatementType' => 'SELECT',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplainAnalyze(PDO $pdo, string $sql, array $params = []): array
    {
        // For MSSQL, SET STATISTICS XML ON returns XML in messages, not in result set
        // Execute the query and return a minimal result to satisfy the API
        // Note: $sql here is the original query (buildExplainAnalyzeSql returns it as-is)
        $pdo->query('SET STATISTICS XML ON');

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stmt->closeCursor();
            $pdo->query('SET STATISTICS XML OFF');
            // Return minimal result structure
            return [['Query' => $sql, 'Statistics' => 'XML output available']];
        } catch (PDOException $e) {
            try {
                $pdo->query('SET STATISTICS XML OFF');
            } catch (PDOException $ignored) {
                // Ignore errors when turning off STATISTICS XML
            }

            throw $e;
        }
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
    public function buildExistsExpression(string $subquery): string
    {
        // MSSQL doesn't support SELECT EXISTS(...), use CASE WHEN EXISTS(...) THEN 1 ELSE 0 END
        return 'SELECT CASE WHEN EXISTS(' . $subquery . ') THEN 1 ELSE 0 END';
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
    public function buildTableExistsSql(string $table): string
    {
        return $this->ddlBuilder->buildTableExistsSql($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return $this->ddlBuilder->buildDescribeSql($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowIndexesSql(string $table): string
    {
        return $this->ddlBuilder->buildShowIndexesSql($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        return $this->ddlBuilder->buildShowForeignKeysSql($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        return $this->ddlBuilder->buildShowConstraintsSql($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        return $this->ddlBuilder->buildLockSql($tables, $prefix, $lockMethod);
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        return $this->ddlBuilder->buildUnlockSql();
    }

    /**
     * {@inheritDoc}
     */
    public function buildTruncateSql(string $table): string
    {
        return $this->ddlBuilder->buildTruncateSql($table);
    }

    /**
     * Get current database name.
     *
     * @return string
     */
    protected function getCurrentDatabase(): string
    {
        if ($this->pdo === null) {
            return '';
        }
        $stmt = $this->pdo->query('SELECT DB_NAME()');
        if ($stmt === false) {
            return '';
        }
        $result = $stmt->fetchColumn();
        return (string)($result ?: '');
    }

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
     * Parse column definition array to ColumnSchema.
     *
     * @param array<string, mixed> $def
     */
    protected function parseColumnDefinition(array $def): ColumnSchema
    {
        return $this->ddlBuilder->parseColumnDefinition($def);
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanType(): array
    {
        return ['type' => 'BIT', 'length' => null];
    }

    /**
     * {@inheritDoc}
     */
    public function getTimestampType(): string
    {
        // MSSQL can only have one TIMESTAMP column per table, so use DATETIME instead
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function getDatetimeType(): string
    {
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function isNoFieldsError(PDOException $e): bool
    {
        $errorMessage = $e->getMessage();
        // MSSQL throws "The active result for the query contains no fields" for DDL/DDL-like queries
        return str_contains($errorMessage, 'contains no fields') ||
               str_contains($errorMessage, 'IMSSP');
    }

    /**
     * {@inheritDoc}
     */
    public function appendLimitOffset(string $sql, int $limit, int $offset): string
    {
        // MSSQL uses OFFSET ... FETCH NEXT ... ROWS ONLY
        // Check if ORDER BY exists in SQL
        if (stripos($sql, 'ORDER BY') === false) {
            // MSSQL requires ORDER BY for OFFSET/FETCH
            // Use a simple ordering that works for any query
            return $sql . " ORDER BY (SELECT NULL) OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        } else {
            // ORDER BY exists, just add OFFSET/FETCH
            return $sql . " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getPrimaryKeyType(): string
    {
        return 'INT';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigPrimaryKeyType(): string
    {
        return 'BIGINT';
    }

    /**
     * {@inheritDoc}
     */
    public function getStringType(): string
    {
        // MSSQL uses NVARCHAR for Unicode strings
        return 'NVARCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        // MSSQL doesn't have TEXT type - use NVARCHAR(MAX)
        // Conversion to NVARCHAR(MAX) happens in formatColumnDefinition
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getCharType(): string
    {
        // MSSQL uses NCHAR for Unicode fixed-length strings
        return 'NCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        return $this->ddlBuilder->buildMigrationTableSql($tableName);
    }

    /**
     * {@inheritDoc}
     */
    public function getExplainParser(): ExplainParserInterface
    {
        return new MSSQLExplainParser();
    }

    /**
     * {@inheritDoc}
     */
    public function getRecursiveCteKeyword(): string
    {
        // MSSQL uses just 'WITH' for recursive CTEs, not 'WITH RECURSIVE'
        return 'WITH';
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
        return 'SELECT name FROM sys.databases ORDER BY name';
    }

    /**
     * {@inheritDoc}
     */
    protected function extractDatabaseNames(array $result): array
    {
        $names = [];
        foreach ($result as $row) {
            $name = $row['name'] ?? null;
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

        $dbName = $db->rawQueryValue('SELECT DB_NAME()');
        if ($dbName !== null) {
            $info['current_database'] = $dbName;
        }

        $version = $db->rawQueryValue('SELECT @@VERSION');
        if ($version !== null) {
            $info['version'] = $version;
        }

        $collation = $db->rawQueryValue("SELECT DATABASEPROPERTYEX(DB_NAME(), 'Collation')");
        if ($collation !== null) {
            $info['collation'] = $collation;
        }

        return $info;
    }

    /* ---------------- User Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function createUser(string $username, string $password, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // MSSQL uses logins at server level and users at database level
        // Host is not used in MSSQL
        $quotedUsername = $this->quoteIdentifier($username);
        // Escape single quotes in password for MSSQL
        $quotedPassword = "'" . str_replace("'", "''", $password) . "'";

        // Create login first
        $sql = "CREATE LOGIN {$quotedUsername} WITH PASSWORD = {$quotedPassword}";
        $db->rawQuery($sql);

        // Create user in current database
        $currentDb = $db->rawQueryValue('SELECT DB_NAME()');
        if ($currentDb !== null) {
            // Execute USE and CREATE USER as separate queries
            $db->rawQuery("USE [{$currentDb}]");
            $db->rawQuery("CREATE USER {$quotedUsername} FOR LOGIN {$quotedUsername}");
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // MSSQL: drop user first, then login
        $quotedUsername = $this->quoteIdentifier($username);

        // Drop user from current database
        $currentDb = $db->rawQueryValue('SELECT DB_NAME()');
        if ($currentDb !== null) {
            try {
                // Execute USE and DROP USER as separate queries
                $db->rawQuery("USE [{$currentDb}]");
                $db->rawQuery("DROP USER {$quotedUsername}");
            } catch (\Throwable $e) {
                // User might not exist in this database, continue
            }
        }

        // Drop login
        $sql = "DROP LOGIN {$quotedUsername}";
        $db->rawQuery($sql);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function userExists(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // MSSQL: check if login exists
        $quotedUsername = $this->quoteIdentifier($username);

        $sql = "SELECT COUNT(*) FROM sys.server_principals WHERE name = ? AND type = 'S'";
        $count = $db->rawQueryValue($sql, [$username]);
        return (int)$count > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function listUsers(\tommyknocker\pdodb\PdoDb $db): array
    {
        $sql = "SELECT name FROM sys.server_principals WHERE type = 'S' AND is_disabled = 0 ORDER BY name";
        $result = $db->rawQuery($sql);

        $users = [];
        foreach ($result as $row) {
            $users[] = [
                'username' => $row['name'],
                'host' => null,
                'user_host' => $row['name'],
            ];
        }

        return $users;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): array
    {
        // MSSQL: get login and user info
        $quotedUsername = $this->quoteIdentifier($username);

        $sql = "SELECT name, type_desc, is_disabled FROM sys.server_principals WHERE name = ? AND type = 'S'";
        $login = $db->rawQueryOne($sql, [$username]);

        if (empty($login)) {
            return [];
        }

        $info = [
            'username' => $login['name'],
            'host' => null,
            'user_host' => $login['name'],
            'type' => $login['type_desc'],
            'is_disabled' => $login['is_disabled'] === 1 || $login['is_disabled'] === true,
        ];

        // Get database user info
        $currentDb = $db->rawQueryValue('SELECT DB_NAME()');
        if ($currentDb !== null) {
            try {
                // Execute USE and SELECT as separate queries
                $db->rawQuery("USE [{$currentDb}]");
                $user = $db->rawQueryOne("SELECT name FROM sys.database_principals WHERE name = ? AND type = 'S'", [$username]);
                $info['database_user'] = $user['name'] ?? null;
            } catch (\Throwable $e) {
                $info['database_user'] = null;
            }
        }

        // Get privileges
        try {
            $grantsSql = 'SELECT
                permission_name,
                state_desc,
                object_name(major_id) as object_name
            FROM sys.database_permissions
            WHERE grantee_principal_id = (SELECT principal_id FROM sys.database_principals WHERE name = ?)';
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
        // MSSQL: grant permissions
        $quotedUsername = $this->quoteIdentifier($username);

        $target = '';
        if ($database !== null && $table !== null) {
            $quotedDb = $database === '*' ? '[' . $db->rawQueryValue('SELECT DB_NAME()') . ']' : '[' . $database . ']';
            $quotedTable = $table === '*' ? '*' : $this->quoteIdentifier($table);
            $target = "{$quotedDb}.dbo.{$quotedTable}";
        } elseif ($database !== null) {
            // For database-level grants in MSSQL, use SCHEMA instead of DATABASE
            // MSSQL doesn't support GRANT SELECT ON DATABASE, only on SCHEMA or objects
            $target = 'SCHEMA::dbo';
        } else {
            // For server-level grants, use current database schema
            $target = 'SCHEMA::dbo';
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
        // MSSQL: revoke permissions
        $quotedUsername = $this->quoteIdentifier($username);

        $target = '';
        if ($database !== null && $table !== null) {
            $quotedDb = $database === '*' ? '[' . $db->rawQueryValue('SELECT DB_NAME()') . ']' : '[' . $database . ']';
            $quotedTable = $table === '*' ? '*' : $this->quoteIdentifier($table);
            $target = "{$quotedDb}.dbo.{$quotedTable}";
        } elseif ($database !== null) {
            // For database-level revokes in MSSQL, use SCHEMA instead of DATABASE
            // MSSQL doesn't support REVOKE SELECT ON DATABASE, only on SCHEMA or objects
            $target = 'SCHEMA::dbo';
        } else {
            // For server-level revokes, use current database schema
            $target = 'SCHEMA::dbo';
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
        // MSSQL: change login password
        $quotedUsername = $this->quoteIdentifier($username);
        // Escape single quotes in password for MSSQL
        $quotedPassword = "'" . str_replace("'", "''", $newPassword) . "'";

        $sql = "ALTER LOGIN {$quotedUsername} WITH PASSWORD = {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }
}
