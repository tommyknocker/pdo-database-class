<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use InvalidArgumentException;
use PDO;
use PDOException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\MSSQLExplainParser;
use tommyknocker\pdodb\query\DdlQueryBuilder;
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
    public function formatConcatExpression(array $parts): string
    {
        // MSSQL uses + operator for concatenation
        return implode(' + ', $parts);
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
            $rows = $db->rawQuery("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
            foreach ($rows as $row) {
                $tables[] = (string)$row['TABLE_NAME'];
            }
        }

        foreach ($tables as $tableName) {
            // Build CREATE TABLE from INFORMATION_SCHEMA
            $quotedTable = $this->quoteTable($tableName);

            if ($dropTables) {
                $output[] = "IF OBJECT_ID('{$quotedTable}', 'U') IS NOT NULL DROP TABLE {$quotedTable};";
            }
            $columns = $db->rawQuery(
                'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE, COLUMN_DEFAULT
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                [$tableName]
            );

            if (empty($columns)) {
                continue;
            }

            $colDefs = [];
            foreach ($columns as $col) {
                $colName = $this->quoteIdentifier((string)$col['COLUMN_NAME']);
                $dataType = (string)$col['DATA_TYPE'];
                $nullable = (string)$col['IS_NULLABLE'] === 'YES';
                $default = $col['COLUMN_DEFAULT'] !== null ? (string)$col['COLUMN_DEFAULT'] : null;

                // Build type with length/precision
                // MSSQL doesn't support precision/scale for int, bigint, smallint, tinyint
                // MSSQL doesn't support length for ntext, text, image
                $typesWithoutPrecision = ['int', 'bigint', 'smallint', 'tinyint', 'bit', 'money', 'smallmoney', 'datetime', 'datetime2', 'date', 'time'];
                $typesWithoutLength = ['ntext', 'text', 'image'];
                if ($col['CHARACTER_MAXIMUM_LENGTH'] !== null && !in_array(strtolower($dataType), $typesWithoutLength, true)) {
                    $charLength = (int)$col['CHARACTER_MAXIMUM_LENGTH'];
                    // For max length types or -1 (unlimited), use (MAX) instead of the actual number
                    if ($charLength === 2147483647 || $charLength === 1073741823 || $charLength === -1) {
                        $dataType .= '(MAX)';
                    } else {
                        $dataType .= '(' . $charLength . ')';
                    }
                } elseif ($col['NUMERIC_PRECISION'] !== null && !in_array(strtolower($dataType), $typesWithoutPrecision, true)) {
                    $dataType .= '(' . (int)$col['NUMERIC_PRECISION'];
                    if ($col['NUMERIC_SCALE'] !== null) {
                        $dataType .= ',' . (int)$col['NUMERIC_SCALE'];
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
                'SELECT i.name AS index_name, i.is_unique, c.name AS column_name
                 FROM sys.indexes i
                 INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                 INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
                 INNER JOIN sys.tables t ON i.object_id = t.object_id
                 WHERE t.name = ? AND i.is_primary_key = 0
                 ORDER BY i.name, ic.key_ordinal',
                [$tableName]
            );
            $indexes = [];
            foreach ($indexRows as $idxRow) {
                $idxName = (string)$idxRow['index_name'];
                if (!isset($indexes[$idxName])) {
                    $indexes[$idxName] = [
                        'unique' => (int)$idxRow['is_unique'] === 1,
                        'columns' => [],
                    ];
                }
                $indexes[$idxName]['columns'][] = (string)$idxRow['column_name'];
            }

            foreach ($indexes as $idxName => $idxInfo) {
                $unique = $idxInfo['unique'] ? 'UNIQUE ' : '';
                $quotedColumns = array_map([$this, 'quoteIdentifier'], $idxInfo['columns']);
                $output[] = "CREATE {$unique}INDEX " . $this->quoteIdentifier($idxName) . " ON {$quotedTable} (" . implode(', ', $quotedColumns) . ');';
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
            $rows = $db->rawQuery("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
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
        // Split SQL into statements, handling comments, quoted strings, and nested parentheses
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $parenDepth = 0;

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
                if (!$inString && $char === '-' && $nextChar === '-') {
                    // Skip rest of line (comment)
                    break;
                }

                if (!$inString && ($char === '"' || $char === "'" || $char === '[')) {
                    if ($char === '[') {
                        // MSSQL bracket identifier
                        $bracketEnd = strpos($line, ']', $i);
                        if ($bracketEnd !== false) {
                            $current .= substr($line, $i, $bracketEnd - $i + 1);
                            $i = $bracketEnd;
                            continue;
                        }
                    } else {
                        $inString = true;
                        $stringChar = $char;
                        $current .= $char;
                    }
                } elseif ($inString && $char === $stringChar) {
                    if ($nextChar === $stringChar) {
                        $current .= $char . $nextChar;
                        $i++;
                    } else {
                        $inString = false;
                        $stringChar = '';
                        $current .= $char;
                    }
                } elseif (!$inString && $char === '(') {
                    $parenDepth++;
                    $current .= $char;
                } elseif (!$inString && $char === ')') {
                    $parenDepth--;
                    $current .= $char;
                } elseif (!$inString && $char === ';' && $parenDepth === 0) {
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
        foreach ($statements as $index => $stmt) {
            try {
                // Skip empty statements
                if (trim($stmt) === '') {
                    continue;
                }
                $db->rawQuery($stmt);
            } catch (\Throwable $e) {
                if (!$continueOnError) {
                    throw new \tommyknocker\pdodb\exceptions\ResourceException(
                        'Failed to execute SQL statement #' . ($index + 1) . ': ' . $e->getMessage() .
                        "\nStatement (" . strlen($stmt) . ' chars): ' . substr($stmt, 0, 300) .
                        "\nPrevious statement: " . (isset($statements[$index - 1]) ? substr($statements[$index - 1], 0, 200) : 'none')
                    );
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
        return new MSSQLDdlQueryBuilder($connection, $prefix);
    }

    /* ---------------- Monitoring ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getActiveQueries(\tommyknocker\pdodb\PdoDb $db): array
    {
        try {
            $sql = "SELECT r.session_id, r.request_id, r.start_time, r.status, r.command, r.database_id,
                           s.login_name, s.host_name, s.program_name, DB_NAME(r.database_id) as database_name,
                           SUBSTRING(t.text, (r.statement_start_offset/2)+1,
                           ((CASE r.statement_end_offset WHEN -1 THEN DATALENGTH(t.text)
                           ELSE r.statement_end_offset END - r.statement_start_offset)/2)+1) AS query_text
                    FROM sys.dm_exec_requests r
                    INNER JOIN sys.dm_exec_sessions s ON r.session_id = s.session_id
                    OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) t
                    WHERE r.session_id != @@SPID AND r.status != 'sleeping'";
            $rows = $db->rawQuery($sql);
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'session_id' => $this->toString($row['session_id'] ?? ''),
                    'request_id' => $this->toString($row['request_id'] ?? ''),
                    'login' => $this->toString($row['login_name'] ?? ''),
                    'host' => $this->toString($row['host_name'] ?? ''),
                    'database' => $this->toString($row['database_name'] ?? ''),
                    'status' => $this->toString($row['status'] ?? ''),
                    'command' => $this->toString($row['command'] ?? ''),
                    'start_time' => $this->toString($row['start_time'] ?? ''),
                    'query' => $this->toString($row['query_text'] ?? ''),
                ];
            }
            return $result;
        } catch (\Exception $e) {
            // Return empty array if query fails (insufficient permissions, etc.)
            return [];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveConnections(\tommyknocker\pdodb\PdoDb $db): array
    {
        try {
            $rows = $db->rawQuery('SELECT session_id, login_name, host_name, program_name, database_id, login_time, last_request_start_time, status FROM sys.dm_exec_sessions WHERE is_user_process = 1');
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'session_id' => $this->toString($row['session_id'] ?? ''),
                    'login' => $this->toString($row['login_name'] ?? ''),
                    'host' => $this->toString($row['host_name'] ?? ''),
                    'program' => $this->toString($row['program_name'] ?? ''),
                    'database_id' => $this->toString($row['database_id'] ?? ''),
                    'login_time' => $this->toString($row['login_time'] ?? ''),
                    'last_request' => $this->toString($row['last_request_start_time'] ?? ''),
                    'status' => $this->toString($row['status'] ?? ''),
                ];
            }

            // Get connection limits
            $maxConn = $db->rawQueryValue("SELECT value FROM sys.configurations WHERE name = 'user connections'");
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
            // Return empty structure if query fails (insufficient permissions, etc.)
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
    public function getServerMetrics(\tommyknocker\pdodb\PdoDb $db): array
    {
        $metrics = [];

        try {
            // Get version
            $version = $db->rawQueryValue('SELECT @@VERSION');
            $metrics['version'] = is_string($version) ? $version : 'unknown';

            // Get key performance counters
            $counters = $db->rawQuery("
                SELECT
                    counter_name,
                    cntr_value
                FROM sys.dm_os_performance_counters
                WHERE counter_name IN (
                    'User Connections',
                    'Batch Requests/sec',
                    'SQL Compilations/sec',
                    'Page life expectancy'
                )
            ");

            foreach ($counters as $counter) {
                $name = $counter['counter_name'] ?? '';
                $value = $counter['cntr_value'] ?? 0;
                if (is_string($name) && $name !== '') {
                    $key = strtolower(str_replace([' ', '/'], ['_', '_'], $name));
                    $metrics[$key] = is_int($value) ? $value : (is_string($value) ? (int)$value : 0);
                }
            }

            // Get server start time (uptime)
            $startTime = $db->rawQueryValue('SELECT sqlserver_start_time FROM sys.dm_os_sys_info');
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
    public function getSlowQueries(\tommyknocker\pdodb\PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        try {
            $thresholdMs = (int)($thresholdSeconds * 1000);
            $sql = 'SELECT TOP ? qs.execution_count, qs.total_elapsed_time / 1000.0 as total_time_sec,
                           qs.total_elapsed_time / qs.execution_count / 1000.0 as avg_time_sec,
                           qs.max_elapsed_time / 1000.0 as max_time_sec,
                           SUBSTRING(t.text, (qs.statement_start_offset/2)+1,
                           ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(t.text)
                           ELSE qs.statement_end_offset END - qs.statement_start_offset)/2)+1) AS query_text
                    FROM sys.dm_exec_query_stats qs
                    CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) t
                    WHERE qs.total_elapsed_time / qs.execution_count >= ?
                    ORDER BY qs.total_elapsed_time / qs.execution_count DESC';
            $rows = $db->rawQuery($sql, [$limit, $thresholdMs]);
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'executions' => $this->toString($row['execution_count'] ?? ''),
                    'total_time' => number_format($this->toFloat($row['total_time_sec'] ?? 0), 2) . 's',
                    'avg_time' => number_format($this->toFloat($row['avg_time_sec'] ?? 0), 2) . 's',
                    'max_time' => number_format($this->toFloat($row['max_time_sec'] ?? 0), 2) . 's',
                    'query' => $this->toString($row['query_text'] ?? ''),
                ];
            }
            return $result;
        } catch (\Exception $e) {
            // Return empty array if query fails (insufficient permissions, etc.)
            return [];
        }
    }

    /* ---------------- Table Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function listTables(\tommyknocker\pdodb\PdoDb $db, ?string $schema = null): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $db->rawQuery("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
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
            '1205', // Lock request time out period exceeded
            '1222', // Lock request time out period exceeded
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getErrorDescription(int|string $errorCode): string
    {
        $descriptions = [
            '18456' => 'Login failed',
            '4060' => 'Cannot open database',
            '207' => 'Invalid column name',
            '208' => 'Invalid object name',
            '2627' => 'Violation of UNIQUE KEY constraint',
            '547' => 'Foreign key constraint violation',
            '1205' => 'Lock request time out period exceeded',
            '1222' => 'Lock request time out period exceeded',
        ];

        return $descriptions[$errorCode] ?? 'Unknown error';
    }

    /* ---------------- DML Operations ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getInsertSelectColumns(string $tableName, ?array $columns, \tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface $executionEngine): array
    {
        // If columns are explicitly provided, use them
        if ($columns !== null) {
            return $columns;
        }

        // For MSSQL, we must exclude IDENTITY columns to avoid errors
        try {
            $describeSql = $this->buildDescribeSql($tableName);
            $tableColumns = $executionEngine->fetchAll($describeSql);
            $identityColumns = [];
            foreach ($tableColumns as $col) {
                // MSSQL returns 'is_identity' from COLUMNPROPERTY
                $isIdentity = $col['is_identity'] ?? $col['IDENTITY'] ?? false;
                if ($isIdentity === true || $isIdentity === 1 || $isIdentity === '1') {
                    $identityColumns[] = $col['COLUMN_NAME'] ?? $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? '';
                }
            }
            // Get all columns except IDENTITY ones
            $allColumns = [];
            foreach ($tableColumns as $col) {
                $colName = $col['COLUMN_NAME'] ?? $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? '';
                if ($colName && !in_array($colName, $identityColumns, true)) {
                    $allColumns[] = $colName;
                }
            }
            return $allColumns;
        } catch (\Exception $e) {
            // If describe fails, fall back to empty array (let database handle it)
            return [];
        }
    }

    /* ---------------- Configuration ---------------- */

    /**
     * {@inheritDoc}
     */
    public function buildConfigFromEnv(array $envVars): array
    {
        $config = parent::buildConfigFromEnv($envVars);

        // MSSQL requires 'dbname' parameter
        if (isset($envVars['PDODB_DATABASE'])) {
            $config['dbname'] = $envVars['PDODB_DATABASE'];
        }

        // MSSQL-specific options
        if (isset($envVars['PDODB_TRUST_SERVER_CERTIFICATE'])) {
            $config['trust_server_certificate'] = filter_var($envVars['PDODB_TRUST_SERVER_CERTIFICATE'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (strtolower($envVars['PDODB_TRUST_SERVER_CERTIFICATE']) === 'true');
        } else {
            $config['trust_server_certificate'] = true;
        }
        if (isset($envVars['PDODB_ENCRYPT'])) {
            $config['encrypt'] = filter_var($envVars['PDODB_ENCRYPT'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (strtolower($envVars['PDODB_ENCRYPT']) === 'true');
        } else {
            $config['encrypt'] = true;
        }

        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeConfigParams(array $config): array
    {
        $config = parent::normalizeConfigParams($config);

        // MSSQL requires 'dbname' parameter
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
     * Helper method to convert value to float.
     *
     * @param mixed $value
     *
     * @return float
     */
    protected function toFloat(mixed $value): float
    {
        return is_float($value) ? $value : (is_numeric($value) ? (float)$value : 0.0);
    }

    /**
     * {@inheritDoc}
     */
    public function killQuery(\tommyknocker\pdodb\PdoDb $db, int|string $processId): bool
    {
        try {
            $sessionId = is_int($processId) ? $processId : (int)$processId;
            $db->rawQuery("KILL {$sessionId}");
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
            // MSSQL: CAST(column AS NVARCHAR(MAX)) LIKE pattern
            return "(CAST({$columnName} AS NVARCHAR(MAX)) LIKE {$paramKey})";
        }

        if ($isArray && $searchInJson) {
            // MSSQL doesn't have native arrays, skip
            return null;
        }

        if ($isNumeric) {
            if (is_numeric($searchTerm)) {
                $exactParamKey = ':exact_' . count($params);
                $params[$exactParamKey] = $searchTerm;
                return "({$columnName} = {$exactParamKey} OR CAST({$columnName} AS NVARCHAR(MAX)) LIKE {$paramKey})";
            }
            return "(CAST({$columnName} AS NVARCHAR(MAX)) LIKE {$paramKey})";
        }

        // Text columns
        return "({$columnName} LIKE {$paramKey})";
    }
}
