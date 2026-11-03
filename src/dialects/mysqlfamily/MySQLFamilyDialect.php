<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysqlfamily;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use tommyknocker\pdodb\dialects\catalog\CatalogQueriesInterface;
use tommyknocker\pdodb\dialects\catalog\MySqlCatalogQueriesTrait;
use tommyknocker\pdodb\dialects\config\ConfigOptimizerStrategy;
use tommyknocker\pdodb\dialects\config\MySQLConfigStrategy;
use tommyknocker\pdodb\dialects\ConnectionInitHandler;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\FeatureSupportInterface;
use tommyknocker\pdodb\dialects\InsertId;
use tommyknocker\pdodb\dialects\InsertStatement;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\outbox\claim\OutboxClaimStrategy;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\MySQLExplainParser;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Behaviour shared by MySQL and MariaDB. Engine differences live in the subclasses.
 */
abstract class MySQLFamilyDialect extends DialectAbstract implements CatalogQueriesInterface
{
    use MySqlCatalogQueriesTrait;
    private const int PRIMARY_KEY_CACHE_SECONDS = 30;

    /**
     * @var array<string, array{until: float, key: ?string, autoIncrement: bool}> Single-column primary key by table
     */
    private array $primaryKeys = [];

    /**
     * {@inheritDoc}
     *
     * The connection reports only AUTO_INCREMENT values. The id of the written row is therefore:
     *  - nothing, when a plain insert wrote no row (`INSERT IGNORE` skipped it), and for an upsert that updated a row;
     *  - the key the statement supplied for the table's single-column primary key, of any type (a UUID, a text key);
     *  - otherwise the connection's value (read first, before any other query), when that key is AUTO_INCREMENT;
     *  - nothing for a key the database fills in from a default, which MySQL cannot report.
     * A table without a single-column primary key keeps the connection's answer.
     */
    public function extractInsertId(PDOStatement $stmt, string $sql, array $params): int|string|null
    {
        if ($this->pdo === null) {
            return null;
        }
        $insert = InsertStatement::parse($sql);
        if ($insert === null) {
            return null;
        }
        // The connection's id belongs to the last statement: read it before the catalog query below replaces it
        $connectionId = $this->pdo->lastInsertId();
        $affected = $stmt->rowCount();
        if (preg_match('/\bON\s+DUPLICATE\s+KEY\b/i', $insert->tail) === 1) {
            // An upsert reports 1 for an inserted row, 2 for an updated one and 0 for an update that changed nothing. When
            // the row was updated, neither the key the statement carried nor the connection's id (still the previous
            // insert's) names it, unless the update says so with LAST_INSERT_ID(<key>).
            if ($affected !== 1) {
                return preg_match('/\bLAST_INSERT_ID\s*\(/i', $insert->tail) === 1 ? (InsertId::from($connectionId) ?? 0) : 0;
            }
        } elseif ($affected === 0) {
            return 0; // INSERT IGNORE skipped the row
        }

        $primaryKey = $this->primaryKeyOf($insert, true);
        if ($primaryKey['key'] === null) {
            return null;
        }
        $supplied = $insert->suppliedKey($primaryKey['key'], $params);
        if ($supplied !== null) {
            return $supplied;
        }

        return $primaryKey['autoIncrement'] ? (InsertId::from($connectionId) ?? 0) : 0;
    }

    /**
     * The single-column primary key of the table an INSERT writes to, and whether it is AUTO_INCREMENT.
     *
     * @return array{key: ?string, autoIncrement: bool}
     */
    protected function primaryKeyOf(InsertStatement $insert, bool $useCache): array
    {
        $known = $this->primaryKeys[$insert->qualifiedTable] ?? null;
        if ($useCache && $known !== null && $known['until'] > microtime(true)) {
            return ['key' => $known['key'], 'autoIncrement' => $known['autoIncrement']];
        }

        $parts = preg_split('/\s*\.\s*/', $insert->qualifiedTable) ?: [$insert->qualifiedTable];
        $schema = count($parts) > 1 ? trim($parts[count($parts) - 2], '`" ') : null;
        $pdo = $this->pdo;
        assert($pdo !== null);
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS '
            . "WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI'"
        );
        $statement->execute([$schema, $insert->table]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $found = count($rows) === 1
            ? ['key' => (string)$rows[0]['COLUMN_NAME'], 'autoIncrement' => stripos((string)$rows[0]['EXTRA'], 'auto_increment') !== false]
            : ['key' => null, 'autoIncrement' => false];
        $this->primaryKeys[$insert->qualifiedTable] = ['until' => microtime(true) + self::PRIMARY_KEY_CACHE_SECONDS] + $found;

        return $found;
    }

    public function doubleQuotedTextIsLiteral(): bool
    {
        return SessionQuoteMode::doubleQuotesAreLiterals($this->getPdo());
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
        foreach (['host', 'dbname', 'username'] as $requiredParam) {
            if (empty($params[$requiredParam])) {
                throw new InvalidArgumentException("Missing '$requiredParam' parameter");
            }
        }
        $this->assertDsnSafe($params, ['host', 'dbname', 'port', 'charset', 'unix_socket', 'sslca', 'sslcert', 'sslkey', 'compress']);

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
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Backslash is an escape character in the default sql_mode, so it is escaped as well. With a connection
     * the driver quotes the value for the active session mode.
     */
    public function quoteStringLiteral(string $value): string
    {
        $value = str_replace("\0", '', $value);
        if ($this->pdo !== null) {
            $quoted = $this->pdo->quote($value);
            if ($quoted !== false) {
                return $quoted;
            }
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(mixed $name): string
    {
        return $name instanceof RawValue ? $name->getValue() : '`' . str_replace('`', '``', (string)$name) . '`';
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
    public function buildUpsertClauseForValues(array $updateColumns, array $insertColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        return $this->dmlBuilder->buildUpsertClauseForValues($updateColumns, $insertColumns, $defaultConflictTarget, $tableName);
    }

    public function buildIdempotentUpsertSql(
        string $table,
        array $row,
        array $conflictColumns,
        array $updateColumns
    ): array {
        return $this->buildDuplicateKeyUpsertSql($table, $row, $conflictColumns, $updateColumns);
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

        $trimmedDif = $this->assertSafeNowDiff(trim($diff));
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
        // MySQL/MariaDB 8.0+ supports EXPLAIN ANALYZE with JSON format
        return 'EXPLAIN FORMAT=JSON ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildTableExistsSql(string $table): string
    {
        // LIKE treats % and _ as wildcards: escape them so the name matches literally.
        return 'SHOW TABLES LIKE ' . $this->quoteStringLiteral(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table));
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return 'DESCRIBE ' . $this->quoteTable($table);
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
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): Generator
    {
        // MySQL/MariaDB uses native LOAD DATA INFILE which loads entire file at once
        yield $this->buildLoadCsvSql($table, $filePath, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadXMLGenerator(string $table, string $filePath, array $options = []): Generator
    {
        // MySQL/MariaDB uses native LOAD XML LOCAL INFILE which loads entire file at once
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
            AND TABLE_NAME = " . $this->quoteStringLiteral($table) . '
            AND REFERENCED_TABLE_NAME IS NOT NULL';
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
            AND tc.TABLE_NAME = " . $this->quoteStringLiteral($table);
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
        return $this->sqlFormatter->normalizeRawValue($sql);
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
        return new MySQLExplainParser();
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
    public function getDatabaseInfo(PdoDb $db): array
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
    public function createUser(string $username, string $password, ?string $host, PdoDb $db): bool
    {
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);
        $quotedPassword = $db->rawQueryValue('SELECT QUOTE(?)', [$password]);
        if ($quotedPassword === null) {
            $quotedPassword = $this->quoteStringLiteral($password);
        }

        $sql = "CREATE USER {$quotedUsername}@{$quotedHost} IDENTIFIED BY {$quotedPassword}";
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(string $username, ?string $host, PdoDb $db): bool
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
    public function userExists(string $username, ?string $host, PdoDb $db): bool
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
    public function listUsers(PdoDb $db): array
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
    public function getUserInfo(string $username, ?string $host, PdoDb $db): array
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
        PdoDb $db
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
        PdoDb $db
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
    public function changeUserPassword(string $username, string $newPassword, ?string $host, PdoDb $db): bool
    {
        $host = $host ?? '%';
        $quotedUsername = $this->quoteIdentifier($username);
        $quotedHost = $this->quoteIdentifier($host);
        $quotedPassword = $db->rawQueryValue('SELECT QUOTE(?)', [$newPassword]);
        if ($quotedPassword === null) {
            $quotedPassword = $this->quoteStringLiteral($newPassword);
        }

        $sql = "ALTER USER {$quotedUsername}@{$quotedHost} IDENTIFIED BY {$quotedPassword}";
        $db->rawQuery($sql);
        $db->rawQuery('FLUSH PRIVILEGES');
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

            // Get CREATE TABLE statement (includes indexes in MySQL/MariaDB)
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

            // Note: MySQL/MariaDB's SHOW CREATE TABLE already includes all indexes in the CREATE TABLE statement,
            // so we don't need to add separate CREATE INDEX statements
        }

        return implode("\n", $output);
    }

    /* ---------------- Monitoring ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getActiveQueries(PdoDb $db): array
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
    public function getActiveConnections(PdoDb $db): array
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
    public function getServerMetrics(PdoDb $db): array
    {
        $metrics = [];

        try {
            // Get version
            $version = $db->rawQueryValue('SELECT VERSION()');
            $metrics['version'] = is_string($version) ? $version : 'unknown';

            // Get uptime
            $uptimeResult = $db->rawQuery("SHOW STATUS WHERE Variable_name = 'Uptime'");
            $uptimeValue = $uptimeResult[0]['Value'] ?? 0;
            $uptimeValue = is_int($uptimeValue) ? $uptimeValue : (is_string($uptimeValue) ? (int)$uptimeValue : 0);
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
    public function configOptimizerStrategy(): ?ConfigOptimizerStrategy
    {
        return new MySQLConfigStrategy();
    }

    /**
     * {@inheritDoc}
     */
    public function getServerVariables(PdoDb $db): array
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
    public function getSlowQueries(PdoDb $db, float $thresholdSeconds, int $limit): array
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
    public function listTables(PdoDb $db, ?string $schema = null): array
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
            2006,
            2002,
            2013,
            1205,
            1213,
            1040,
            1317,
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
    public function killQuery(PdoDb $db, int|string $processId): bool
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
            // MySQL/MariaDB: CAST(column AS CHAR) LIKE pattern
            return "(CAST({$columnName} AS CHAR) LIKE {$paramKey})";
        }

        if ($isArray && $searchInJson) {
            // MySQL/MariaDB doesn't have native arrays, skip
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
                processed_at DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
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
                id CHAR(36) NOT NULL PRIMARY KEY,
                event_type VARCHAR(255) NOT NULL,
                payload LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                available_at DATETIME(6) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                locked_by VARCHAR(128) NULL,
                locked_until DATETIME(6) NULL,
                last_error TEXT NULL,
                published_at DATETIME(6) NULL,
                headers VARCHAR(4000) NULL,
                partition_key VARCHAR(190) NULL,
                KEY {$indexName} (status, available_at),
                KEY {$partitionIndex} (partition_key, status, id)
            )",
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function outboxNowSql(): string
    {
        return 'UTC_TIMESTAMP(6)';
    }

    /**
     * {@inheritDoc}
     */
    public function outboxNowPlusSecondsSql(int $seconds): string
    {
        return 'UTC_TIMESTAMP(6) + INTERVAL ' . $seconds . ' SECOND';
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
                  AND locked_until < {$this->outboxNowSql()}",
            [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function outboxMissingIndexSql(PdoDb $db, string $tableName): array
    {
        $indexName = $tableName . '_partition_idx';
        $present = (int)$db->rawQueryValue(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i',
            ['t' => $tableName, 'i' => $indexName]
        );
        if ($present > 0) {
            return [];
        }

        return ['CREATE INDEX ' . $this->quoteIdentifier($indexName) . ' ON ' . $this->quoteTable($tableName) . ' (partition_key, status, id)'];
    }

    /**
     * {@inheritDoc}
     */
    public function outboxClaimStrategy(): OutboxClaimStrategy
    {
        return new MySQLFamilyOutboxClaim($this);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    public function catalogQueries(): CatalogQueriesInterface
    {
        return $this;
    }

    public function connectionInitHandler(): ConnectionInitHandler
    {
        return ConnectionInitHandler::MysqlTcp;
    }

    public function initWizardDefaultCharset(): ?string
    {
        return 'utf8mb4';
    }

    public function requiredPdoExtension(): string
    {
        return 'pdo_mysql';
    }

    protected function catalogDialect(): DialectInterface
    {
        return $this;
    }

    protected FeatureSupportInterface $featureSupport;

    protected MySQLFamilySqlFormatter $sqlFormatter;

    protected MySQLFamilyDmlBuilder $dmlBuilder;

    protected MySQLFamilyDdlBuilder $ddlBuilder;

    public function __construct()
    {
        $this->featureSupport = $this->createFeatureSupport();
        $this->sqlFormatter = $this->createSqlFormatter();
        $this->dmlBuilder = $this->createDmlBuilder();
        $this->ddlBuilder = $this->createDdlBuilder();
    }

    abstract protected function createFeatureSupport(): FeatureSupportInterface;

    abstract protected function createSqlFormatter(): MySQLFamilySqlFormatter;

    abstract protected function createDmlBuilder(): MySQLFamilyDmlBuilder;

    abstract protected function createDdlBuilder(): MySQLFamilyDdlBuilder;

    /**
     * {@inheritDoc}
     */
    protected function listDumpTables(PdoDb $db): array
    {
        $tables = [];
        foreach ($db->rawQuery('SHOW FULL TABLES WHERE Table_Type = "BASE TABLE"') as $row) {
            $values = array_values($row);
            if (isset($values[0]) && is_string($values[0])) {
                $tables[] = $values[0];
            }
        }
        sort($tables);

        return $tables;
    }
}
