<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\sqlite;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class SqliteDialect extends DialectAbstract
{
    /** @var SqliteFeatureSupport Feature support instance */
    private SqliteFeatureSupport $featureSupport;

    /** @var SQLiteSqlFormatter SQL formatter instance */
    private SQLiteSqlFormatter $sqlFormatter;

    /** @var SQLiteDmlBuilder DML builder instance */
    private SQLiteDmlBuilder $dmlBuilder;

    /** @var SQLiteDdlBuilder DDL builder instance */
    private SQLiteDdlBuilder $ddlBuilder;

    public function __construct()
    {
        $this->featureSupport = new SqliteFeatureSupport();
        $this->sqlFormatter = new SQLiteSqlFormatter($this);
        $this->dmlBuilder = new SQLiteDmlBuilder($this);
        $this->ddlBuilder = new SQLiteDdlBuilder($this);
    }
    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'sqlite';
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
        if (!isset($params['path'])) {
            throw new InvalidArgumentException("Missing 'path' parameter");
        }
        // SQLite cache parameter is a string (shared/private), not an array
        // Filter out cache config arrays used for query result caching
        $sqliteCache = null;
        if (isset($params['cache']) && is_string($params['cache'])) {
            $validCacheModes = ['shared', 'private'];
            if (!in_array(strtolower($params['cache']), $validCacheModes, true)) {
                throw new InvalidArgumentException(
                    'Invalid SQLite cache parameter. Must be one of: ' . implode(', ', $validCacheModes)
                );
            }
            $sqliteCache = $params['cache'];
        }

        $sqliteMode = null;
        if (!empty($params['mode']) && is_string($params['mode'])) {
            $validModes = ['ro', 'rw', 'rwc', 'memory'];
            if (!in_array(strtolower($params['mode']), $validModes, true)) {
                throw new InvalidArgumentException(
                    'Invalid SQLite mode parameter. Must be one of: ' . implode(', ', $validModes)
                );
            }
            $sqliteMode = $params['mode'];
        }

        return "sqlite:{$params['path']}"
            . ($sqliteMode !== null ? ";mode={$sqliteMode}" : '')
            . ($sqliteCache !== null ? ";cache={$sqliteCache}" : '');
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
     *
     * @param array<int|string, mixed> $flags
     */
    public function insertKeywords(array $flags): string
    {
        return $this->dmlBuilder->insertKeywords($flags);
    }

    /**
     * {@inheritDoc}
     */
    public function buildInsertSql(string $fullTable, array $columns, array $placeholders, array $options): string
    {
        return $this->dmlBuilder->buildInsertSql($fullTable, $columns, $placeholders, $options);
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
            return "{$colSql} = excluded.{$colSql}";
        }

        $op = $expr['__op'];
        // SQLite uses column (unqualified) for old values
        return match ($op) {
            'inc' => "{$colSql} = {$colSql} + " . (int)$expr['val'],
            'dec' => "{$colSql} = {$colSql} - " . (int)$expr['val'],
            default => "{$colSql} = excluded.{$colSql}",
        };
    }

    /**
     * {@inheritDoc}
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        // SQLite doesn't support DEFAULT keyword in UPDATE statements
        // Replace DEFAULT with NULL (closest equivalent behavior)
        $value = $expr->getValue();
        if (trim($value) === 'DEFAULT') {
            return "{$colSql} = NULL";
        }
        return "{$colSql} = {$value}";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        $exprStr = trim((string)$expr);

        // Simple name or EXCLUDED.name
        if (preg_match('/^(?:excluded\.)?[A-Za-z_][A-Za-z0-9_]*$/i', $exprStr)) {
            if (stripos($exprStr, 'excluded.') === 0) {
                return "{$colSql} = {$exprStr}";
            }
            return "{$colSql} = excluded.{$this->quoteIdentifier($exprStr)}";
        }

        // Auto-qualify for typical expressions: replace only "bare" occurrences of column name with excluded."col"
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
        if ($asTimestamp) {
            return $diff ? "STRFTIME('%s','now','{$diff}')" : "STRFTIME('%s','now')";
        }
        return $diff ? "DATETIME('now','{$diff}')" : "DATETIME('now')";
    }

    /**
     * {@inheritDoc}
     */
    public function config(ConfigValue $value): RawValue
    {
        $sql = 'PRAGMA ' . strtoupper($value->getValue())
            . ($value->getUseEqualSign() ? ' = ' : ' ')
            . ($value->getQuoteValue() ? '\'' . $value->getParams()[0] . '\'' : $value->getParams()[0]);
        return new RawValue($sql);
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
        return 'EXPLAIN QUERY PLAN ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildTableExistsSql(string $table): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return "PRAGMA table_info({$table})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        throw new QueryException('LOCK TABLES not supported');
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        throw new QueryException('UNLOCK TABLES not supported');
    }

    /**
     * {@inheritDoc}
     */
    public function buildTruncateSql(string $table): string
    {
        $table = $this->quoteTable($table);
        $identifier = $this->quoteIdentifier($table);
        return "DELETE FROM {$table}; DELETE FROM sqlite_sequence WHERE name={$identifier}";
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
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        return $this->sqlFormatter->formatGroupConcat($column, $separator, $distinct);
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
     * Register REGEXP functions for SQLite using PHP's preg_* functions.
     * This method registers REGEXP, regexp_replace, and regexp_extract functions
     * if they are not already available.
     *
     * @param PDO $pdo The PDO instance
     * @param bool $force Force re-registration even if functions exist
     */
    public function registerRegexpFunctions(PDO $pdo, bool $force = false): void
    {
        $this->sqlFormatter->registerRegexpFunctions($pdo, $force);
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
        return "SELECT name, tbl_name as table_name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        return "PRAGMA foreign_key_list('{$table}')";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        return "SELECT sql, type FROM sqlite_master WHERE type IN ('table', 'index') AND tbl_name = '{$table}'";
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
    public function getStringType(): string
    {
        // SQLite uses TEXT for strings
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        // SQLite uses TEXT for all text types
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getCharType(): string
    {
        // SQLite doesn't distinguish CHAR/VARCHAR/TEXT - all are TEXT
        return 'TEXT';
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
    public function normalizeDefaultValue(string $value): string
    {
        // SQLite doesn't support DEFAULT keyword in UPDATE statements
        // Replace DEFAULT with NULL (closest equivalent behavior)
        if (trim($value) === 'DEFAULT') {
            return 'NULL';
        }
        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        $tableQuoted = $this->quoteTable($tableName);
        return "CREATE TABLE {$tableQuoted} (
            version TEXT PRIMARY KEY,
            apply_time TEXT DEFAULT CURRENT_TIMESTAMP,
            batch INTEGER NOT NULL
        )";
    }

    /**
     * {@inheritDoc}
     */
    public function getExplainParser(): ExplainParserInterface
    {
        return new \tommyknocker\pdodb\query\analysis\parsers\SqliteExplainParser();
    }

    /**
     * {@inheritDoc}
     */
    public function createDatabase(string $databaseName, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // For SQLite, creating a database means creating a file
        // If databaseName doesn't have extension, add .sqlite
        $filePath = $databaseName;
        if (!str_ends_with($databaseName, '.sqlite') && !str_ends_with($databaseName, '.db')) {
            $filePath = $databaseName . '.sqlite';
        }

        // Check if file already exists
        if (file_exists($filePath)) {
            // Database already exists, return true
            return true;
        }

        // Create the database file by connecting to it
        try {
            $newDb = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => $filePath]);
            // Test connection by running a simple query
            $newDb->rawQueryValue('SELECT 1');
            return true;
        } catch (QueryException $e) {
            throw new ResourceException("Failed to create SQLite database file '{$filePath}': {$e->getMessage()}", 0, $e, 'sqlite');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function dropDatabase(string $databaseName, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // For SQLite, delete the database file
        // If databaseName doesn't have extension, try both .sqlite and .db
        $filePath = $databaseName;
        if (!str_ends_with($databaseName, '.sqlite') && !str_ends_with($databaseName, '.db')) {
            // Try .sqlite first, then .db
            if (file_exists($databaseName . '.sqlite')) {
                $filePath = $databaseName . '.sqlite';
            } elseif (file_exists($databaseName . '.db')) {
                $filePath = $databaseName . '.db';
            } else {
                $filePath = $databaseName . '.sqlite';
            }
        }

        if (!file_exists($filePath)) {
            // File doesn't exist, consider it already dropped
            return true;
        }

        // Also delete journal and wal files if they exist
        $journalFile = $filePath . '-journal';
        $walFile = $filePath . '-wal';
        $shmFile = $filePath . '-shm';

        if (file_exists($journalFile)) {
            @unlink($journalFile);
        }
        if (file_exists($walFile)) {
            @unlink($walFile);
        }
        if (file_exists($shmFile)) {
            @unlink($shmFile);
        }

        if (!unlink($filePath)) {
            throw new ResourceException("Failed to delete SQLite database file '{$filePath}'", 0, null, 'sqlite');
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function databaseExists(string $databaseName, \tommyknocker\pdodb\PdoDb $db): bool
    {
        // For SQLite, check if file exists
        // If databaseName looks like a file path, check it directly
        if (str_contains($databaseName, '/') || str_contains($databaseName, '\\') || str_ends_with($databaseName, '.sqlite') || str_ends_with($databaseName, '.db')) {
            return file_exists($databaseName);
        }

        // For SQLite, without explicit file path, we can't determine existence
        // Return false as SQLite doesn't support multiple databases
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function listDatabases(\tommyknocker\pdodb\PdoDb $db): array
    {
        throw new ResourceException('SQLite does not support multiple databases. Use file paths instead.', 0, null, 'sqlite');
    }

    /**
     * {@inheritDoc}
     */
    protected function buildCreateDatabaseSql(string $databaseName): string
    {
        // Not used for SQLite, but required by abstract method
        throw new ResourceException('SQLite does not support CREATE DATABASE SQL. Use createDatabase() method instead.', 0, null, 'sqlite');
    }

    /**
     * {@inheritDoc}
     */
    protected function buildDropDatabaseSql(string $databaseName): string
    {
        // Not used for SQLite, but required by abstract method
        throw new ResourceException('SQLite does not support DROP DATABASE SQL. Use dropDatabase() method instead.', 0, null, 'sqlite');
    }

    /**
     * {@inheritDoc}
     */
    protected function buildListDatabasesSql(): string
    {
        // Not used for SQLite, but required by abstract method
        throw new ResourceException('SQLite does not support listing databases. Use file paths instead.', 0, null, 'sqlite');
    }

    /**
     * {@inheritDoc}
     */
    protected function extractDatabaseNames(array $result): array
    {
        // Not used for SQLite, but required by abstract method
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function dumpSchema(\tommyknocker\pdodb\PdoDb $db, ?string $table = null): string
    {
        $tableOutput = [];
        $indexOutput = [];
        $tables = [];

        if ($table !== null) {
            $tables = [$table];
        } else {
            $rows = $db->rawQuery("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            foreach ($rows as $row) {
                $tables[] = (string)$row['name'];
            }
        }

        // First, collect all CREATE TABLE statements
        foreach ($tables as $tableName) {
            $createRows = $db->rawQuery("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$tableName]);
            if (!empty($createRows)) {
                $createSql = (string)$createRows[0]['sql'];
                $tableOutput[] = $createSql . ';';
            }
        }

        // Then, collect all indexes
        foreach ($tables as $tableName) {
            $indexRows = $db->rawQuery("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name = ? AND sql IS NOT NULL", [$tableName]);
            foreach ($indexRows as $idxRow) {
                $idxSql = (string)$idxRow['sql'];
                if ($idxSql !== '') {
                    $indexOutput[] = $idxSql . ';';
                }
            }
        }

        return implode("\n", array_merge($tableOutput, $indexOutput));
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
            $rows = $db->rawQuery("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            foreach ($rows as $row) {
                $tables[] = (string)$row['name'];
            }
        }

        foreach ($tables as $tableName) {
            $quotedTable = $this->quoteTable($tableName);
            $rows = $db->rawQuery("SELECT * FROM {$quotedTable}");

            if (empty($rows)) {
                continue;
            }

            // Get column names
            $columns = array_keys($rows[0]);
            $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
            $columnsList = implode(', ', $quotedColumns);

            // Generate INSERT statements in batches
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
                        $values[] = "'" . str_replace("'", "''", (string)$val) . "'";
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
        // Split SQL into statements (semicolon-separated, ignoring semicolons in strings and comments)
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $inComment = false;

        $lines = explode("\n", $sql);
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            // Skip comment lines
            if (preg_match('/^--/', $line)) {
                continue;
            }

            // Check for inline comments (-- at end of line)
            $commentPos = strpos($line, '--');
            if ($commentPos !== false) {
                // Check if -- is inside a string
                $beforeComment = substr($line, 0, $commentPos);
                $quoteCount = substr_count($beforeComment, "'") + substr_count($beforeComment, '"') + substr_count($beforeComment, '`');
                if ($quoteCount % 2 === 0) {
                    // Not in string, remove comment
                    $line = substr($line, 0, $commentPos);
                    $line = rtrim($line);
                }
            }

            $current .= $line . "\n";

            // Check if line ends with semicolon (statement complete)
            if (substr(rtrim($line), -1) === ';') {
                $stmt = trim($current);
                if ($stmt !== '' && !preg_match('/^--/', $stmt)) {
                    // Remove trailing semicolon
                    $stmt = rtrim($stmt, ';');
                    $stmt = trim($stmt);
                    if ($stmt !== '') {
                        $statements[] = $stmt;
                    }
                }
                $current = '';
            }
        }

        // Handle last statement
        $stmt = trim($current);
        if ($stmt !== '' && !preg_match('/^--/', $stmt)) {
            $stmt = rtrim($stmt, ';');
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
        }

        // Execute statements
        $errors = [];
        foreach ($statements as $stmt) {
            try {
                $db->rawQuery($stmt);
            } catch (\Throwable $e) {
                if (!$continueOnError) {
                    throw new ResourceException('Failed to execute SQL statement: ' . $e->getMessage() . "\nStatement: " . substr($stmt, 0, 200));
                }
                $errors[] = $e->getMessage();
            }
        }

        if (!empty($errors) && $continueOnError) {
            throw new ResourceException('Restore completed with ' . count($errors) . ' errors. First error: ' . $errors[0]);
        }
    }
}
