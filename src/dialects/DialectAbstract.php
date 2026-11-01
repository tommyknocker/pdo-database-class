<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\loaders\FileLoader;
use tommyknocker\pdodb\dialects\traits\AdminDefaults;
use tommyknocker\pdodb\dialects\traits\CatalogDefaults;
use tommyknocker\pdodb\dialects\traits\ConnectionDefaults;
use tommyknocker\pdodb\dialects\traits\DdlDefaults;
use tommyknocker\pdodb\dialects\traits\DmlDefaults;
use tommyknocker\pdodb\dialects\traits\DumpDefaults;
use tommyknocker\pdodb\dialects\traits\JsonPathParser;
use tommyknocker\pdodb\dialects\traits\LoaderDefaults;
use tommyknocker\pdodb\dialects\traits\McpDefaults;
use tommyknocker\pdodb\dialects\traits\MessagingDefaults;
use tommyknocker\pdodb\dialects\traits\ModelDefaults;
use tommyknocker\pdodb\dialects\traits\MonitoringDefaults;
use tommyknocker\pdodb\dialects\traits\PlanningDefaults;
use tommyknocker\pdodb\dialects\traits\QuotingDefaults;
use tommyknocker\pdodb\dialects\traits\SqlFunctionsDefaults;
use tommyknocker\pdodb\dialects\traits\TransactionDefaults;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\helpers\values\NowValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;

abstract class DialectAbstract implements DialectInterface
{
    use AdminDefaults;
    use CatalogDefaults;
    use ConnectionDefaults;
    use DdlDefaults;
    use DmlDefaults;
    use DumpDefaults;
    use LoaderDefaults;
    use McpDefaults;
    use MessagingDefaults;
    use ModelDefaults;
    use MonitoringDefaults;
    use PlanningDefaults;
    use QuotingDefaults;
    use SqlFunctionsDefaults;
    use TransactionDefaults;

    /** @var PDO PDO instance */
    protected ?PDO $pdo = null;

    protected ?FileLoader $fileLoader = null;

    /**
     * Build the loader that renders INSERT statements for this dialect's file imports.
     */
    protected function createFileLoader(PDO $pdo): FileLoader
    {
        return new FileLoader($pdo, $this);
    }

    /**
     * Get file loader instance.
     */
    protected function getFileLoader(): FileLoader
    {
        if ($this->fileLoader === null) {
            if ($this->pdo === null) {
                throw new RuntimeException('PDO instance not set. Call setPdo() first.');
            }
            $this->fileLoader = $this->createFileLoader($this->pdo);
        }
        return $this->fileLoader;
    }

    /**
     * Get the driver name.
     *
     * @return string
     */
    abstract public function getDriverName(): string;

    /**
     * Fold an unquoted identifier the way this server does.
     *
     * A quoted identifier keeps the case supplied by the caller. The default
     * leaves the text unchanged.
     */
    public function foldUnquotedIdentifier(string $identifier): string
    {
        return $identifier;
    }

    /**
     * Whether "..." is a string literal on this connection for privacy redaction.
     *
     * When true, profiler and AI context replace double-quoted tokens with ?.
     * When false, they stay visible because they may be identifiers. SQLite
     * overrides this: unknown double-quoted names can still be string values.
     */
    public function doubleQuotedTextIsLiteral(): bool
    {
        return false;
    }

    /**
     * Quote identifier (column/table name).
     *
     * @param string $name
     *
     * @return string
     */
    abstract public function quoteIdentifier(string $name): string;

    /**
     * Reject a date-interval expression that could carry anything but an interval (it is written into SQL text).
     *
     * @throws InvalidArgumentException
     */
    protected function assertSafeNowDiff(string $diff): string
    {
        if (preg_match('/^[A-Za-z0-9 +\-:.,_]+$/', $diff) !== 1) {
            throw new InvalidArgumentException('Unsupported date interval expression.');
        }

        return $diff;
    }

    /**
     * Quote table name with optional alias.
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
        //  - "schema.table AS alias" (AS)
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
            return $this->quoteIdentifier($name) . ' AS ' . $this->quoteIdentifier($alias);
        }

        $parts = preg_split('/\s+/', $table, 2);
        if ($parts === false || count($parts) === 1) {
            return $this->quoteIdentifier($table);
        }

        [$name, $alias] = $parts;
        return $this->quoteIdentifier($name) . ' ' . $this->quoteIdentifier($alias);
    }

    /**
     * SQL for one string operand of concat().
     *
     * A complete, well-formed SQL string literal ('it''s') is kept as written; it cannot end early. A bare word
     * (optionally schema.table.column) names a column. Anything else is text and becomes an escaped literal: SQL
     * fragments must be passed as Db::raw(), because guessing from the characters let user text through as SQL.
     */
    protected function concatStringOperand(string $s): string
    {
        if (preg_match("/^'(?:[^']|'')*'$/s", $s) === 1 && !str_contains($s, '\\')) {
            return $s;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]*(\.[A-Za-z_][A-Za-z0-9_$]*)*$/', $s) === 1) {
            return implode('.', array_map(fn (string $piece): string => $this->quoteIdentifier($piece), explode('.', $s)));
        }

        return $this->quoteStringLiteral($s);
    }

    /**
     * Normalize JSON path input.
     *
     * @param array<int, string|int>|string $path
     *
     * @return array<int, string|int>
     */
    protected function normalizeJsonPath(array|string $path): array
    {
        if (is_string($path)) {
            // allow dot notation like "a.b[0].c" or "$.a.b"
            return JsonPathParser::parse($path);
        }
        return array_values($path);
    }

    /* ---------------- Helper methods for value resolution ---------------- */

    /**
     * Resolve RawValue or return value as-is.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    protected function resolveValue(string|RawValue $value): string
    {
        return $value instanceof RawValue ? $value->getValue() : $value;
    }

    /**
     * Resolve array of values (handle RawValue instances).
     *
     * @param array<int, string|int|float|RawValue> $values
     *
     * @return array<int, string|int|float>
     */
    protected function resolveValues(array $values): array
    {
        return array_map(fn ($v) => $v instanceof RawValue ? $v->getValue() : $v, $values);
    }

    /**
     * Format default value for IFNULL/COALESCE.
     *
     * @param mixed $default
     *
     * @return string
     */
    protected function formatDefaultValue(mixed $default): string
    {
        if ($default instanceof RawValue) {
            return $default->getValue();
        }
        if (is_string($default)) {
            return $this->quoteStringLiteral($default);
        }
        return (string)$default;
    }

    /* ---------------- Default implementations of dialect-specific methods ---------------- */

    /**
     * Format GREATEST expression (default implementation)
     * Override in specific dialects if needed (e.g., SQLite uses MAX).
     *
     * @param array<int, string|int|float|RawValue> $values
     *
     * @return string
     */
    public function formatGreatest(array $values): string
    {
        $args = $this->resolveValues($values);
        return 'GREATEST(' . implode(', ', $args) . ')';
    }

    /**
     * A DSN has no escaping: a ';' ends the value and starts another parameter, so a database name such as
     * "app;host=elsewhere" would redirect the connection. Reject such values before they are interpolated.
     *
     * @param array<string, mixed> $params DSN parameters
     * @param list<string> $keys Parameters that are written into the DSN
     *
     * @throws InvalidArgumentException If a value contains ';', a NUL or a line break
     */
    protected function assertDsnSafe(array $params, array $keys): void
    {
        foreach ($keys as $key) {
            $value = $params[$key] ?? null;
            if (is_string($value) && preg_match('/[;\x00\r\n]/', $value) === 1) {
                throw new InvalidArgumentException("The '{$key}' connection parameter must not contain ';' or control characters.");
            }
        }
    }

    /**
     * @return array{estimated_rows: ?int, filtered_percent: ?float}
     */
    protected function buildAiExplainPlanFacts(ExplainAnalysis $analysis): array
    {
        $plan = $analysis->plan;
        $hasRows = false;
        $hasFiltered = false;
        foreach ($analysis->rawExplain as $row) {
            $hasRows = $hasRows || (isset($row['rows']) && is_numeric($row['rows']))
                || (isset($row['QUERY PLAN']) && preg_match('/rows=\d+/', (string)$row['QUERY PLAN']) === 1);
            $hasFiltered = $hasFiltered || (isset($row['filtered']) && is_numeric($row['filtered']));
        }

        return [
            'estimated_rows' => $this->includesEstimatedRowCountInAiPlanFacts() && ($hasRows || $plan->estimatedRows > 0)
                ? $plan->estimatedRows
                : null,
            'filtered_percent' => $hasFiltered ? $plan->filtered : null,
        ];
    }

    protected function includesEstimatedRowCountInAiPlanFacts(): bool
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
        return array_intersect_key($row, array_flip([
            'id', 'select_type', 'table', 'type', 'possible_keys', 'key', 'key_len', 'ref', 'rows', 'filtered', 'Extra',
        ]));
    }

    /**
     * MySQL and MariaDB insert-or-ignore / insert-or-assign statement.
     *
     * @param array<string, string|int|float|RawValue|null> $row
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns
     *
     * @return array{0: string, 1: array<string, string|int|float|null>}
     */
    protected function buildDuplicateKeyUpsertSql(
        string $table,
        array $row,
        array $conflictColumns,
        array $updateColumns
    ): array {
        [$columns, $params] = $this->prepareUpsert($row, $conflictColumns, $updateColumns);
        $quotedColumns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $placeholders = array_map(fn (string $column): string => $this->upsertValueSql($column, $row), $columns);
        $assignments = [];
        $assigned = $updateColumns === [] ? [$conflictColumns[0]] : $updateColumns;
        foreach ($assigned as $column) {
            $quoted = $this->quoteIdentifier($column);
            $assignments[] = $quoted . ' = VALUES(' . $quoted . ')';
        }
        $sql = 'INSERT INTO ' . $this->quoteTable($table)
            . ' (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $placeholders) . ') '
            . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        return [$sql, $params];
    }

    /**
     * @param array<string, string|int|float|RawValue|null> $row
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns
     *
     * @return array{0: list<string>, 1: array<string, string|int|float|null>}
     */
    /**
     * SQL for one upsert value: a bound placeholder, or the inline expression for a `RawValue`.
     *
     * @param array<string, mixed> $row
     */
    protected function upsertValueSql(string $column, array $row): string
    {
        $value = $row[$column] ?? null;
        if (!$value instanceof RawValue) {
            return ':upsert_' . $column;
        }
        if ($value->getParams() !== []) {
            throw new InvalidArgumentException('Upsert expressions cannot carry bound parameters.');
        }
        if ($value instanceof NowValue) {
            return $this->now($value->getValue(), $value->getAsTimestamp());
        }

        return $value->getValue();
    }

    /**
     * @param array<string, string|int|float|RawValue|null> $row
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns
     *
     * @return array{0: list<string>, 1: array<string, string|int|float|null>}
     */
    protected function prepareUpsert(array $row, array $conflictColumns, array $updateColumns): array
    {
        if ($row === [] || $conflictColumns === []) {
            throw new InvalidArgumentException('Upsert requires values and at least one conflict column.');
        }

        $columns = [];
        $params = [];
        foreach ($row as $column => $value) {
            $this->assertUpsertIdentifier($column);
            if ($value instanceof RawValue) {
                $columns[] = $column;
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && $value !== null) {
                throw new InvalidArgumentException('Upsert values must be scalar.');
            }
            $columns[] = $column;
            $params['upsert_' . $column] = $value;
        }
        foreach ($conflictColumns as $column) {
            $this->assertUpsertIdentifier($column);
            if (!array_key_exists($column, $row)) {
                throw new InvalidArgumentException("Conflict column '{$column}' is missing from the row.");
            }
        }
        foreach ($updateColumns as $column) {
            $this->assertUpsertIdentifier($column);
            if (!array_key_exists($column, $row)) {
                throw new InvalidArgumentException("Update column '{$column}' is missing from the row.");
            }
            if (in_array($column, $conflictColumns, true)) {
                throw new InvalidArgumentException("Update column '{$column}' is also a conflict column.");
            }
        }

        return [$columns, $params];
    }

    protected function assertUpsertIdentifier(string $column): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new InvalidArgumentException("Invalid upsert column '{$column}'.");
        }
    }

    /**
     * Build CREATE DATABASE SQL statement.
     *
     * @param string $databaseName Database name
     *
     * @return string SQL statement
     */
    abstract protected function buildCreateDatabaseSql(string $databaseName): string;

    /**
     * Build DROP DATABASE SQL statement.
     *
     * @param string $databaseName Database name
     *
     * @return string SQL statement
     */
    abstract protected function buildDropDatabaseSql(string $databaseName): string;

    /**
     * Build list databases SQL statement.
     *
     * @return string SQL statement
     */
    abstract protected function buildListDatabasesSql(): string;

    /**
     * Extract database names from query result.
     *
     * @param array<int, array<string, mixed>> $result Query result
     *
     * @return array<int, string> List of database names
     */
    abstract protected function extractDatabaseNames(array $result): array;

    /* ---------------- User Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function createUser(string $username, string $password, ?string $host, PdoDb $db): bool
    {
        throw new ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /* ---------------- Monitoring ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getActiveQueries(PdoDb $db): array
    {
        // Default implementation returns empty array
        // Dialects should override this method
        return [];
    }

    /* ---------------- Table Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function listTables(PdoDb $db, ?string $schema = null): array
    {
        // Default implementation - dialects should override
        throw new ResourceException(
            'Table listing is not implemented for ' . $this->getDriverName()
        );
    }

    /* ---------------- Error Handling ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getRetryableErrorCodes(): array
    {
        // Default implementation returns empty array
        // Dialects should override this method
        return [];
    }

    /* ---------------- DML Operations ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getInsertSelectColumns(string $tableName, ?array $columns, ExecutionEngineInterface $executionEngine): array
    {
        // Default implementation returns columns as-is
        // Dialects that need special handling (e.g., MSSQL IDENTITY) should override
        return $columns ?? [];
    }

    /* ---------------- Configuration ---------------- */

    /**
     * {@inheritDoc}
     */
    public function buildConfigFromEnv(array $envVars): array
    {
        // Default implementation - dialects should override
        $config = ['driver' => $this->getDriverName()];

        if (isset($envVars['PDODB_HOST'])) {
            $config['host'] = $envVars['PDODB_HOST'];
        }
        if (isset($envVars['PDODB_PORT'])) {
            $config['port'] = (int)$envVars['PDODB_PORT'];
        }
        if (isset($envVars['PDODB_DATABASE'])) {
            $config['database'] = $envVars['PDODB_DATABASE'];
            $config['dbname'] = $envVars['PDODB_DATABASE'];
        }
        if (isset($envVars['PDODB_USERNAME'])) {
            $config['username'] = $envVars['PDODB_USERNAME'];
        }
        if (isset($envVars['PDODB_PASSWORD'])) {
            $config['password'] = $envVars['PDODB_PASSWORD'];
        }
        if (isset($envVars['PDODB_PREFIX'])) {
            $config['prefix'] = $envVars['PDODB_PREFIX'];
        }

        return $config;
    }

    /* ---------------- Table Search ---------------- */

    /**
     * Check if column is JSON type based on column metadata.
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return bool
     */
    public function isJsonColumn(array $columnMetadata): bool
    {
        $type = $this->getColumnType($columnMetadata);
        return str_contains($type, 'json') || str_contains($type, 'jsonb');
    }

    /**
     * Get column type from column metadata.
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return string Column type in lowercase
     */
    protected function getColumnType(array $columnMetadata): string
    {
        return strtolower((string)($columnMetadata['Type'] ?? $columnMetadata['type'] ?? $columnMetadata['data_type'] ?? ''));
    }

    /**
     * Build search condition for a column.
     * Must be implemented by each dialect.
     *
     * @param string $columnName Column name (already quoted)
     * @param string $searchTerm Search term
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     * @param bool $searchInJson Whether to search in JSON columns
     * @param array<string, mixed> $params Query parameters (by reference, will be modified)
     *
     * @return string|null SQL condition or null if column should be skipped
     */
    abstract public function buildColumnSearchCondition(
        string $columnName,
        string $searchTerm,
        array $columnMetadata,
        bool $searchInJson,
        array &$params
    ): ?string;
}
