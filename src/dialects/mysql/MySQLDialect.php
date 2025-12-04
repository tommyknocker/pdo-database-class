<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class MySQLDialect extends DialectAbstract
{
    /** @var MySQLFeatureSupport Feature support instance */
    private MySQLFeatureSupport $featureSupport;

    /** @var MySQLSqlFormatter SQL formatter instance */
    private MySQLSqlFormatter $sqlFormatter;

    /** @var MySQLDmlBuilder DML builder instance */
    private MySQLDmlBuilder $dmlBuilder;

    /** @var MySQLDdlBuilder DDL builder instance */
    private MySQLDdlBuilder $ddlBuilder;

    public function __construct()
    {
        $this->featureSupport = new MySQLFeatureSupport();
        $this->sqlFormatter = new MySQLSqlFormatter($this);
        $this->dmlBuilder = new MySQLDmlBuilder($this);
        $this->ddlBuilder = new MySQLDdlBuilder($this);
    }
    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'mysql';
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
        return "mysql:host={$params['host']};dbname={$params['dbname']}"
            . (!empty($params['port']) ? ";port={$params['port']}" : '')
            . (!empty($params['charset']) ? ";charset={$params['charset']}" : '')
            . (!empty($params['unix_socket']) ? ";unix_socket={$params['unix_socket']}" : '')
            . (!empty($params['sslca']) ? ";sslca={$params['sslca']}" : '')
            . (!empty($params['sslcert']) ? ";sslcert={$params['sslcert']}" : '')
            . (!empty($params['sslkey']) ? ";sslkey={$params['sslkey']}" : '')
            . (!empty($params['compress']) ? ";compress={$params['compress']}" : '');
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
            PDO::MYSQL_ATTR_LOCAL_INFILE => true,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(mixed $name): string
    {
        return $name instanceof RawValue ? $name->getValue() : "`{$name}`";
    }

    /**
     * {@inheritDoc}
     */
    public function quoteTable(mixed $table): string
    {
        // Support for schema.table and alias: "schema"."table" AS `t`
        // Simple implementation: escape parts split by dot
        $parts = explode(' ', $table, 3); // partial support for "table AS t", better to parse beforehand if needed

        $name = $parts[0];
        $alias = $parts[1] ?? null;

        $segments = explode('.', $name);
        $quotedSegments = array_map(static fn ($s) => '`' . str_replace('`', '``', $s) . '`', $segments);
        $quoted = implode('.', $quotedSegments);

        if ($alias) {
            return $quoted . ' ' . implode(' ', array_slice($parts, 1));
        }
        return $quoted;
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
     *
     * @param array<string, mixed> $options
     */
    public function formatSelectOptions(string $sql, array $options): string
    {
        $middle = [];
        $tail = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            if (in_array($u, ['LOCK IN SHARE MODE', 'FOR UPDATE'])) {
                $tail[] = $opt;
            } else {
                $middle[] = $opt;
            }
        }
        if ($middle) {
            $result = preg_replace('/^SELECT\s+/i', 'SELECT ' . implode(',', $middle) . ' ', $sql, 1);
            $sql = $result !== null ? $result : $sql;
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
            return $asTimestamp ? 'UNIX_TIMESTAMP()' : 'NOW()';
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
                return "UNIX_TIMESTAMP(NOW() {$sign} INTERVAL {$value} {$unit})";
            }

            return "NOW() {$sign} INTERVAL {$value} {$unit}";
        }

        if ($asTimestamp) {
            return "UNIX_TIMESTAMP(NOW() + INTERVAL {$trimmedDif})";
        }

        return "NOW() + INTERVAL {$trimmedDif}";
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
        // MySQL 8.0+ supports EXPLAIN ANALYZE with JSON format
        return 'EXPLAIN FORMAT=JSON ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildTableExistsSql(string $table): string
    {
        return "SHOW TABLES LIKE '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return "DESCRIBE {$table}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        $parts = [];
        foreach ($tables as $t) {
            $parts[] = $this->quoteIdentifier($prefix . $t) . " {$lockMethod}";
        }
        return 'LOCK TABLES ' . implode(', ', $parts);
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        return 'UNLOCK TABLES';
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
    public function buildLoadXML(string $table, string $filePath, array $options = []): string
    {
        $defaults = [
            'rowTag' => '<row>',
            'linesToIgnore' => null,
        ];
        $options = array_merge($defaults, $options);

        if ($this->pdo === null) {
            throw new RuntimeException('PDO instance not set. Call setPdo() first.');
        }
        $pdo = $this->pdo;

        return 'LOAD XML LOCAL INFILE ' . $pdo->quote($filePath) .
            ' INTO TABLE ' . $this->quoteTableWithAlias($table) .
            ' ROWS IDENTIFIED BY ' . $pdo->quote($options['rowTag']) .
            ($options['linesToIgnore'] ? sprintf(' IGNORE %d LINES', $options['linesToIgnore']) : '');
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadCsvSql(string $table, string $filePath, array $options = []): string
    {
        $defaults = [
            'fieldChar' => ';',
            'fieldEnclosure' => null,
            'fields' => [],
            'lineChar' => null,
            'linesToIgnore' => null,
            'lineStarting' => null,
            'local' => false,
        ];
        $options = array_merge($defaults, $options);

        if ($this->pdo === null) {
            throw new RuntimeException('PDO instance not set. Call setPdo() first.');
        }
        $pdo = $this->pdo;

        $localPrefix = $options['local'] ? 'LOCAL ' : '';
        $quotedPath = $pdo->quote($filePath);

        $sql = "LOAD DATA {$localPrefix}INFILE {$quotedPath} INTO TABLE {$table}";

        // FIELDS
        $sql .= sprintf(" FIELDS TERMINATED BY '%s'", $options['fieldChar']);
        if ($options['fieldEnclosure']) {
            $sql .= sprintf(" ENCLOSED BY '%s'", $options['fieldEnclosure']);
        }

        // LINES
        if ($options['lineChar']) {
            $sql .= sprintf(" LINES TERMINATED BY '%s'", $options['lineChar']);
        }
        if ($options['lineStarting']) {
            $sql .= sprintf(" STARTING BY '%s'", $options['lineStarting']);
        }

        // IGNORE LINES
        if ($options['linesToIgnore']) {
            $sql .= sprintf(' IGNORE %d LINES', $options['linesToIgnore']);
        }

        // FIELDS LIST (in the end!)
        if ($options['fields']) {
            $sql .= ' (' . implode(', ', $options['fields']) . ')';
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        // MySQL uses native LOAD DATA INFILE which loads entire file at once
        yield $this->buildLoadCsvSql($table, $filePath, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadXMLGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        // MySQL uses native LOAD XML LOCAL INFILE which loads entire file at once
        yield $this->buildLoadXML($table, $filePath, $options);
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
        return "SHOW INDEXES FROM {$this->quoteTable($table)}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        $dbName = $this->getCurrentDatabase();
        return "SELECT
            CONSTRAINT_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = '$dbName'
            AND TABLE_NAME = '{$table}'
            AND REFERENCED_TABLE_NAME IS NOT NULL";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        $dbName = $this->getCurrentDatabase();
        return "SELECT
            tc.CONSTRAINT_NAME,
            tc.CONSTRAINT_TYPE,
            tc.TABLE_NAME,
            kcu.COLUMN_NAME
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
            LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
            AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
            AND tc.TABLE_NAME = kcu.TABLE_NAME
            WHERE tc.TABLE_SCHEMA = '$dbName'
            AND tc.TABLE_NAME = '{$table}'";
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
        $stmt = $this->pdo->query('SELECT DATABASE()');
        if ($stmt === false) {
            return '';
        }
        $result = $stmt->fetchColumn();
        return (string)($result ?: '');
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
        // MySQL doesn't need special normalization
        return $sql;
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
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        return $this->sqlFormatter->formatMaterializedCte($cteSql, $isMaterialized);
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
    public function getExplainParser(): ExplainParserInterface
    {
        return new \tommyknocker\pdodb\query\analysis\parsers\MySQLExplainParser();
    }

    /**
     * {@inheritDoc}
     */
    protected function buildCreateDatabaseSql(string $databaseName): string
    {
        $quotedName = $this->quoteIdentifier($databaseName);
        return "CREATE DATABASE IF NOT EXISTS {$quotedName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
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
        return 'SHOW DATABASES';
    }

    /**
     * {@inheritDoc}
     */
    protected function extractDatabaseNames(array $result): array
    {
        $names = [];
        foreach ($result as $row) {
            $name = $row['Database'] ?? null;
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

        $dbName = $db->rawQueryValue('SELECT DATABASE()');
        if ($dbName !== null) {
            $info['current_database'] = $dbName;
        }

        $version = $db->rawQueryValue('SELECT VERSION()');
        if ($version !== null) {
            $info['version'] = $version;
        }

        $charset = $db->rawQueryValue('SELECT @@character_set_database');
        if ($charset !== null) {
            $info['charset'] = $charset;
        }

        $collation = $db->rawQueryValue('SELECT @@collation_database');
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
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);
        $quotedPassword = $db->rawQueryValue('SELECT QUOTE(?)', [$password]);
        if ($quotedPassword === null) {
            $quotedPassword = "'" . addslashes($password) . "'";
        }

        $sql = "CREATE USER {$quotedUsername}@{$quotedHost} IDENTIFIED BY {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);

        $sql = "DROP USER {$quotedUsername}@{$quotedHost}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function userExists(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);

        $sql = 'SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?';
        $count = $db->rawQueryValue($sql, [$username, $host]);
        return (int)$count > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function listUsers(\tommyknocker\pdodb\PdoDb $db): array
    {
        $sql = 'SELECT User, Host FROM mysql.user ORDER BY User, Host';
        $result = $db->rawQuery($sql);

        $users = [];
        foreach ($result as $row) {
            $users[] = [
                'username' => $row['User'],
                'host' => $row['Host'],
                'user_host' => $row['User'] . '@' . $row['Host'],
            ];
        }

        return $users;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): array
    {
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);

        $sql = 'SELECT User, Host FROM mysql.user WHERE User = ? AND Host = ?';
        $user = $db->rawQueryOne($sql, [$username, $host]);

        if (empty($user)) {
            return [];
        }

        $info = [
            'username' => $user['User'],
            'host' => $user['Host'],
            'user_host' => $user['User'] . '@' . $user['Host'],
        ];

        // Get privileges
        $grantsSql = "SHOW GRANTS FOR {$quotedUsername}@{$quotedHost}";

        try {
            $grants = $db->rawQuery($grantsSql);
            $privileges = [];
            foreach ($grants as $grant) {
                $grantLine = $grant['Grants for ' . $username . '@' . $host] ?? ($grant[array_key_first($grant)] ?? '');
                $privileges[] = $grantLine;
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
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);

        $target = '';
        if ($database !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);
            if ($table !== null) {
                $quotedTable = $table === '*' ? '*' : $this->quoteIdentifier($table);
                $target = "{$quotedDb}.{$quotedTable}";
            } else {
                $target = "{$quotedDb}.*";
            }
        } else {
            $target = '*.*';
        }

        $sql = "GRANT {$privileges} ON {$target} TO {$quotedUsername}@{$quotedHost}";
        $db->rawQuery($sql);
        $db->rawQuery('FLUSH PRIVILEGES');
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
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);

        $target = '';
        if ($database !== null) {
            $quotedDb = $database === '*' ? '*' : $this->quoteIdentifier($database);
            if ($table !== null) {
                $quotedTable = $table === '*' ? '*' : $this->quoteIdentifier($table);
                $target = "{$quotedDb}.{$quotedTable}";
            } else {
                $target = "{$quotedDb}.*";
            }
        } else {
            $target = '*.*';
        }

        $sql = "REVOKE {$privileges} ON {$target} FROM {$quotedUsername}@{$quotedHost}";
        $db->rawQuery($sql);
        $db->rawQuery('FLUSH PRIVILEGES');
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function changeUserPassword(string $username, string $newPassword, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);
        $quotedPassword = $db->rawQueryValue('SELECT QUOTE(?)', [$newPassword]);
        if ($quotedPassword === null) {
            $quotedPassword = "'" . addslashes($newPassword) . "'";
        }

        $sql = "ALTER USER {$quotedUsername}@{$quotedHost} IDENTIFIED BY {$quotedPassword}";
        $db->rawQuery($sql);
        $db->rawQuery('FLUSH PRIVILEGES');
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
            $rows = $db->rawQuery('SHOW FULL TABLES WHERE Table_Type = "BASE TABLE"');
            foreach ($rows as $row) {
                $vals = array_values($row);
                if (isset($vals[0]) && is_string($vals[0])) {
                    $tables[] = $vals[0];
                }
            }
            sort($tables);
        }

        foreach ($tables as $tableName) {
            // Check if table exists before trying to dump it
            $schema = $db->schema();
            if (!$schema->tableExists($tableName)) {
                continue;
            }

            if ($dropTables) {
                $quotedTable = $this->quoteTable($tableName);
                $output[] = "DROP TABLE IF EXISTS {$quotedTable};";
            }

            // Get CREATE TABLE statement (includes indexes in MySQL)
            try {
                $createRows = $db->rawQuery('SHOW CREATE TABLE ' . $this->quoteTable($tableName));
                if (!empty($createRows)) {
                    $vals = array_values($createRows[0]);
                    $createSql = isset($vals[1]) && is_string($vals[1]) ? $vals[1] : '';
                    if ($createSql !== '') {
                        $output[] = $createSql . ';';
                    }
                }
            } catch (\Exception $e) {
                // Table might have been dropped, skip it
                continue;
            }

            // Note: MySQL's SHOW CREATE TABLE already includes all indexes in the CREATE TABLE statement,
            // so we don't need to add separate CREATE INDEX statements
        }

        return implode("\n", $output);
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
            $rows = $db->rawQuery('SHOW FULL TABLES WHERE Table_Type = "BASE TABLE"');
            foreach ($rows as $row) {
                $vals = array_values($row);
                if (isset($vals[0]) && is_string($vals[0])) {
                    $tables[] = $vals[0];
                }
            }
            sort($tables);
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
        // Split SQL into statements, handling comments and quoted strings
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $inComment = false;

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

                if (!$inString && ($char === '"' || $char === "'" || $char === '`')) {
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
        return new MySQLDdlQueryBuilder($connection, $prefix);
    }

    /* ---------------- Monitoring ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getActiveQueries(\tommyknocker\pdodb\PdoDb $db): array
    {
        $rows = $db->rawQuery('SHOW FULL PROCESSLIST');
        $result = [];
        foreach ($rows as $row) {
            $command = $row['Command'] ?? '';
            $info = $row['Info'] ?? '';
            if (is_string($command) && $command !== 'Sleep' && is_string($info) && $info !== '') {
                $result[] = [
                    'id' => $this->toString($row['Id'] ?? ''),
                    'user' => $this->toString($row['User'] ?? ''),
                    'host' => $this->toString($row['Host'] ?? ''),
                    'db' => $this->toString($row['db'] ?? ''),
                    'command' => $command,
                    'time' => $this->toString($row['Time'] ?? ''),
                    'state' => $this->toString($row['State'] ?? ''),
                    'query' => $info,
                ];
            }
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveConnections(\tommyknocker\pdodb\PdoDb $db): array
    {
        $rows = $db->rawQuery('SHOW PROCESSLIST');
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => $this->toString($row['Id'] ?? ''),
                'user' => $this->toString($row['User'] ?? ''),
                'host' => $this->toString($row['Host'] ?? ''),
                'db' => $this->toString($row['db'] ?? ''),
                'command' => $this->toString($row['Command'] ?? ''),
                'time' => $this->toString($row['Time'] ?? ''),
                'state' => $this->toString($row['State'] ?? ''),
            ];
        }

        // Get connection limits
        $status = $db->rawQuery("SHOW STATUS LIKE 'Threads_connected'");
        $maxConn = $db->rawQuery("SHOW VARIABLES LIKE 'max_connections'");
        $currentVal = $status[0]['Value'] ?? 0;
        $maxVal = $maxConn[0]['Value'] ?? 0;
        $current = is_int($currentVal) ? $currentVal : (is_string($currentVal) ? (int)$currentVal : 0);
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
    public function getServerMetrics(\tommyknocker\pdodb\PdoDb $db): array
    {
        $metrics = [];

        try {
            // Get version
            $version = $db->rawQueryValue('SELECT VERSION()');
            $metrics['version'] = is_string($version) ? $version : 'unknown';

            // Get uptime
            $uptime = $db->rawQueryValue("SHOW STATUS WHERE Variable_name = 'Uptime'");
            $uptimeValue = is_int($uptime) ? $uptime : (is_string($uptime) ? (int)$uptime : 0);
            $metrics['uptime_seconds'] = $uptimeValue;

            // Get key status variables
            $statusVars = [
                'Threads_connected',
                'Threads_running',
                'Questions',
                'Queries',
                'Slow_queries',
                'Connections',
            ];

            foreach ($statusVars as $var) {
                $result = $db->rawQuery("SHOW STATUS WHERE Variable_name = '{$var}'");
                if (!empty($result)) {
                    $value = $result[0]['Value'] ?? 0;
                    $metrics[strtolower($var)] = is_int($value) ? $value : (is_string($value) ? (int)$value : 0);
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
    public function getServerVariables(\tommyknocker\pdodb\PdoDb $db): array
    {
        try {
            $rows = $db->rawQuery('SHOW VARIABLES');
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'name' => $row['Variable_name'] ?? '',
                    'value' => $row['Value'] ?? '',
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
    public function getSlowQueries(\tommyknocker\pdodb\PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        $threshold = (int)($thresholdSeconds);
        $rows = $db->rawQuery('SHOW FULL PROCESSLIST');
        $result = [];
        foreach ($rows as $row) {
            $timeVal = $row['Time'] ?? 0;
            $time = is_int($timeVal) ? $timeVal : (is_string($timeVal) ? (int)$timeVal : 0);
            if ($time >= $threshold && ($row['Info'] ?? '') !== '') {
                $result[] = [
                    'id' => $this->toString($row['Id'] ?? ''),
                    'user' => $this->toString($row['User'] ?? ''),
                    'host' => $this->toString($row['Host'] ?? ''),
                    'db' => $this->toString($row['db'] ?? ''),
                    'time' => (string)$time,
                    'query' => $this->toString($row['Info'] ?? ''),
                ];
            }
        }
        usort($result, static fn ($a, $b): int => (int)$b['time'] <=> (int)$a['time']);
        return array_slice($result, 0, $limit);
    }

    /* ---------------- Table Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function listTables(\tommyknocker\pdodb\PdoDb $db, ?string $schema = null): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $db->rawQuery('SHOW FULL TABLES WHERE Table_Type = "BASE TABLE"');
        /** @var array<int, string> $names */
        $names = array_values(array_filter(array_map(
            static function (array $r): string {
                $vals = array_values($r);
                return isset($vals[0]) && is_string($vals[0]) ? $vals[0] : '';
            },
            $rows
        ), static fn (string $s): bool => $s !== ''));
        sort($names);
        return $names;
    }

    /* ---------------- Error Handling ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getRetryableErrorCodes(): array
    {
        return [
            1205, // Lock wait timeout exceeded
            1213, // Deadlock found when trying to get lock
            2006, // MySQL server has gone away
            2013, // Lost connection to MySQL server during query
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getErrorDescription(int|string $errorCode): string
    {
        $descriptions = [
            1045 => 'Access denied for user',
            1049 => 'Unknown database',
            1054 => 'Unknown column',
            1062 => 'Duplicate entry',
            1064 => 'SQL syntax error',
            1146 => 'Table doesn\'t exist',
            1205 => 'Lock wait timeout exceeded',
            1213 => 'Deadlock found',
            2006 => 'MySQL server has gone away',
            2013 => 'Lost connection to MySQL server',
        ];

        return $descriptions[$errorCode] ?? 'Unknown error';
    }

    /* ---------------- Configuration ---------------- */

    /**
     * {@inheritDoc}
     */
    public function buildConfigFromEnv(array $envVars): array
    {
        $config = parent::buildConfigFromEnv($envVars);

        if (isset($envVars['PDODB_CHARSET'])) {
            $config['charset'] = $envVars['PDODB_CHARSET'];
        } else {
            $config['charset'] = 'utf8mb4';
        }

        // MySQL/MariaDB specific options
        if (isset($envVars['PDODB_UNIX_SOCKET'])) {
            $config['unix_socket'] = $envVars['PDODB_UNIX_SOCKET'];
        }
        if (isset($envVars['PDODB_SSLCA'])) {
            $config['sslca'] = $envVars['PDODB_SSLCA'];
        }
        if (isset($envVars['PDODB_SSLCERT'])) {
            $config['sslcert'] = $envVars['PDODB_SSLCERT'];
        }
        if (isset($envVars['PDODB_SSLKEY'])) {
            $config['sslkey'] = $envVars['PDODB_SSLKEY'];
        }
        if (isset($envVars['PDODB_COMPRESS'])) {
            $config['compress'] = filter_var($envVars['PDODB_COMPRESS'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (strtolower($envVars['PDODB_COMPRESS']) === 'true');
        }

        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeConfigParams(array $config): array
    {
        $config = parent::normalizeConfigParams($config);

        // MySQL/MariaDB require 'dbname' for DSN
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
    public function killQuery(\tommyknocker\pdodb\PdoDb $db, int|string $processId): bool
    {
        try {
            $processIdInt = is_int($processId) ? $processId : (int)$processId;
            $db->rawQuery("KILL {$processIdInt}");
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
            // MySQL: CAST(column AS CHAR) LIKE pattern
            return "(CAST({$columnName} AS CHAR) LIKE {$paramKey})";
        }

        if ($isArray && $searchInJson) {
            // MySQL doesn't have native arrays, skip
            return null;
        }

        if ($isNumeric) {
            if (is_numeric($searchTerm)) {
                $exactParamKey = ':exact_' . count($params);
                $params[$exactParamKey] = $searchTerm;
                return "({$columnName} = {$exactParamKey} OR CAST({$columnName} AS CHAR) LIKE {$paramKey})";
            }
            return "(CAST({$columnName} AS CHAR) LIKE {$paramKey})";
        }

        // Text columns
        return "({$columnName} LIKE {$paramKey})";
    }
}
