<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use PDO;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\RawValue;

class SelectQueryBuilder implements SelectQueryBuilderInterface
{
    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;
    protected ParameterManagerInterface $parameterManager;
    protected ExecutionEngineInterface $executionEngine;
    protected ConditionBuilderInterface $conditionBuilder;
    protected JoinBuilderInterface $joinBuilder;
    protected RawValueResolver $rawValueResolver;

    /** @var string|null table name */
    protected ?string $table = null {
        get {
            if (!$this->table) {
                throw new RuntimeException('You must define table first. Use table() or from() methods');
            }
            return $this->table;
        }
    }

    /** @var array<int, string> */
    protected array $select = [];

    /** @var array<int, string> ORDER BY expressions */
    protected array $order = [];

    /** @var string|null GROUP BY expression */
    protected ?string $group = null;

    /** @var int|null LIMIT value */
    protected ?int $limit = null;

    /** @var int|null OFFSET value */
    protected ?int $offset = null;

    /** @var string|null Table prefix */
    protected ?string $prefix = null;

    /** @var int PDO fetch mode */
    protected int $fetchMode = PDO::FETCH_ASSOC;

    /** @var array<int|string, mixed> Query options (e.g., FOR UPDATE, IGNORE) */
    protected array $options = [];

    public function __construct(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager,
        ExecutionEngineInterface $executionEngine,
        ConditionBuilderInterface $conditionBuilder,
        JoinBuilderInterface $joinBuilder,
        RawValueResolver $rawValueResolver
    ) {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->parameterManager = $parameterManager;
        $this->executionEngine = $executionEngine;
        $this->conditionBuilder = $conditionBuilder;
        $this->joinBuilder = $joinBuilder;
        $this->rawValueResolver = $rawValueResolver;
    }

    /**
     * Set table name.
     *
     * @param string $table
     *
     * @return self
     */
    public function setTable(string $table): self
    {
        $this->table = $table;
        $this->conditionBuilder->setTable($table);
        return $this;
    }

    /**
     * Set table prefix.
     *
     * @param string|null $prefix
     *
     * @return self
     */
    public function setPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;
        $this->conditionBuilder->setPrefix($prefix);
        $this->joinBuilder->setPrefix($prefix);
        return $this;
    }

    /**
     * Add columns to the SELECT clause.
     *
     * @param RawValue|callable|string|array $cols The columns to add.
     *
     * @return self The current instance.
     */
    public function select(RawValue|callable|string|array $cols): self
    {
        if (!is_array($cols)) {
            $cols = [$cols];
        }
        foreach ($cols as $index => $col) {
            if ($col instanceof RawValue && is_string($index)) {
                $this->select[] = $this->resolveRawValue($col) . ' AS ' . $index;
            } elseif ($col instanceof RawValue) {
                $this->select[] = $this->resolveRawValue($col);
            } elseif (is_callable($col)) {
                // Handle callback for subqueries
                $subQuery = new QueryBuilder($this->connection, $this->prefix ?? '');
                $col($subQuery);
                $sub = $subQuery->toSQL();
                $map = $this->parameterManager->mergeSubParams($sub['params'], 'sq');
                $subSql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
                $this->select[] = is_string($index) ? "({$subSql}) AS {$index}" : "({$subSql})";
            } elseif (is_string($index)) { // ['total' => 'SUM(amount)] Treat it as SUM(amount) AS total
                // Process external references in column expressions
                $processedCol = $this->processExternalReferences($col);
                if ($processedCol instanceof RawValue) {
                    $this->select[] = $this->resolveRawValue($processedCol) . ' AS ' . $index;
                } else {
                    $this->select[] = $col . ' AS ' . $index;
                }
            } else {
                // Process external references in column names
                $processedCol = $this->processExternalReferences($col);
                if ($processedCol instanceof RawValue) {
                    $this->select[] = $this->resolveRawValue($processedCol);
                } else {
                    $this->select[] = $col;
                }
            }
        }
        return $this;
    }

    /**
     * Execute SELECT statement and return all rows.
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function get(): array
    {
        $sql = $this->buildSelectSql();
        return $this->executionEngine->fetchAll($sql, $this->parameterManager->getParams());
    }

    /**
     * Execute SELECT statement and return first row.
     *
     * @return mixed
     * @throws PDOException
     */
    public function getOne(): mixed
    {
        $sql = $this->buildSelectSql();
        return $this->executionEngine->fetch($sql, $this->parameterManager->getParams());
    }

    /**
     * Execute SELECT statement and return column values.
     *
     * @return array<int, mixed>
     * @throws PDOException
     */
    public function getColumn(): array
    {
        if (count($this->select) !== 1) {
            return [];
        }
        $key = $this->resolveSelectedKey();
        $rows = $this->get();
        return array_column($rows, $key);
    }

    /**
     * Execute SELECT statement and return single value.
     *
     * @return mixed
     * @throws PDOException
     */
    public function getValue(): mixed
    {
        if (count($this->select) !== 1) {
            return false;
        }
        $row = $this->getOne();
        $key = $this->resolveSelectedKey();
        if (count($row) === 1 && !isset($row[$key])) {
            return array_shift($row);
        }
        return $row[$key] ?? null;
    }

    /**
     * Add ORDER BY clause.
     *
     * @param string|RawValue $expr The expression to order by.
     * @param string $direction The direction of the ordering (ASC or DESC).
     *
     * @return self The current instance.
     */
    public function orderBy(string|RawValue $expr, string $direction = 'ASC'): self
    {
        $dir = strtoupper(trim($direction));
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            $dir = 'ASC';
        }
        if ($expr instanceof RawValue) {
            $this->order[] = $this->resolveRawValue($expr) . ' ' . $dir;
        } elseif (preg_match('/^[a-z0-9]+\s+(ASC|DESC)/iu', $expr)) {
            $this->order[] = $expr;
        } else {
            // Process external references
            $processedExpr = $this->processExternalReferences($expr);
            if ($processedExpr instanceof RawValue) {
                $this->order[] = $this->resolveRawValue($processedExpr) . ' ' . $dir;
            } else {
                $this->order[] = $this->quoteQualifiedIdentifier($expr) . ' ' . $dir;
            }
        }

        return $this;
    }

    /**
     * Add GROUP BY clause.
     *
     * @param string|array<int, string|RawValue>|RawValue $cols The columns to group by.
     *
     * @return self The current instance.
     */
    public function groupBy(string|array|RawValue $cols): self
    {
        if (!is_array($cols)) {
            $cols = [$cols];
        }
        $groups = [];
        foreach ($cols as $col) {
            if ($col instanceof RawValue) {
                $groups[] = $this->resolveRawValue($col);
            } else {
                // Process external references
                $processedCol = $this->processExternalReferences($col);
                if ($processedCol instanceof RawValue) {
                    $groups[] = $this->resolveRawValue($processedCol);
                } else {
                    $groups[] = $this->quoteQualifiedIdentifier((string)$col);
                }
            }
        }
        $this->group = implode(', ', $groups);
        return $this;
    }

    /**
     * Add LIMIT clause.
     *
     * @param int $number The number of rows to limit.
     *
     * @return self The current instance.
     */
    public function limit(int $number): self
    {
        $this->limit = $number;
        $this->conditionBuilder->setLimit($number);
        return $this;
    }

    /**
     * Add OFFSET clause.
     *
     * @param int $number The number of rows to offset.
     *
     * @return self The current instance.
     */
    public function offset(int $number): self
    {
        $this->offset = $number;
        return $this;
    }

    /**
     * Sets the query options.
     *
     * @param string|array<int|string, mixed> $options The query options.
     *
     * @return self The current object.
     */
    public function option(string|array $options): self
    {
        if (is_array($options)) {
            foreach ($options as $key => $value) {
                if (is_string($key)) {
                    $this->options[$key] = $value;
                } else {
                    $this->options[] = $value;
                }
            }
        } else {
            $this->options[] = $options;
        }
        return $this;
    }

    /**
     * Set fetch mode to return objects.
     *
     * @return self
     */
    public function asObject(): self
    {
        $this->fetchMode = PDO::FETCH_OBJ;
        $this->executionEngine->setFetchMode(PDO::FETCH_OBJ);
        return $this;
    }

    /**
     * Convert query to SQL string and parameters.
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(): array
    {
        $sql = $this->buildSelectSql();
        $params = $this->parameterManager->getParams() ?? [];
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Execute EXPLAIN query to analyze query execution plan.
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function explain(): array
    {
        $sql = $this->buildSelectSql();
        $explainSql = $this->dialect->buildExplainSql($sql);
        return $this->executionEngine->fetchAll($explainSql, $this->parameterManager->getParams());
    }

    /**
     * Execute EXPLAIN ANALYZE query (PostgreSQL) or EXPLAIN FORMAT=JSON (MySQL).
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function explainAnalyze(): array
    {
        $sql = $this->buildSelectSql();
        $explainSql = $this->dialect->buildExplainAnalyzeSql($sql);
        return $this->executionEngine->fetchAll($explainSql, $this->parameterManager->getParams());
    }

    /**
     * Execute DESCRIBE query to get table structure.
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function describe(): array
    {
        $tableName = $this->table; // Use getter to ensure not null
        assert(is_string($tableName)); // PHPStan assertion
        $describeSql = $this->dialect->buildDescribeSql($tableName);
        return $this->executionEngine->fetchAll($describeSql);
    }

    /**
     * Get the current query SQL and parameters.
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    public function getQuery(): array
    {
        $sql = $this->buildSelectSql();
        $params = $this->parameterManager->getParams() ?? [];
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Build SELECT sql.
     *
     * @return string
     */
    public function buildSelectSql(): string
    {
        // build base select (no DB-specific option handling)
        if (empty($this->select)) {
            $select = '*';
        } else {
            $select = implode(', ', array_map(function ($value) {
                // Check if it's already a compiled subquery (starts with '(')
                if (str_starts_with($value, '(')) {
                    return $value;
                }
                return $this->quoteQualifiedIdentifier($value);
            }, $this->select));
        }

        $from = $this->normalizeTable();
        $sql = "SELECT {$select} FROM {$from}";

        if (!empty($this->joinBuilder->getJoins())) {
            $sql .= ' ' . implode(' ', $this->joinBuilder->getJoins());
        }

        $sql .= $this->conditionBuilder->buildConditionsClause($this->conditionBuilder->getWhere(), 'WHERE');

        if (!empty($this->group)) {
            $sql .= ' GROUP BY ' . $this->group;
        }

        $sql .= $this->conditionBuilder->buildConditionsClause($this->conditionBuilder->getHaving(), 'HAVING');

        if (!empty($this->order)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->order);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . (int)$this->offset;
        }

        $sql = $this->dialect->formatSelectOptions($sql, $this->options);

        return trim($sql);
    }

    /**
     * Resolve selected key.
     *
     * @return ?string
     */
    protected function resolveSelectedKey(): ?string
    {
        if (count($this->select) !== 1) {
            return null;
        }

        $expr = $this->select[0];

        // 1) Try to capture explicit alias at the end: " ... AS alias" or " ... alias"
        //    Allow optional quoting with backticks, double quotes or square brackets.
        if (preg_match('/\s+(?:AS\s+)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?\s*$/i', $expr, $matches)) {
            return $matches[1];
        }

        // 2) If expression is a simple identifier (table.col or col), return last segment
        if (preg_match('/^[A-Za-z0-9_\.]+$/', $expr)) {
            $parts = explode('.', $expr);
            return end($parts);
        }

        // 3) Complex expression without alias — cannot determine key
        return $expr;
    }

    /**
     * Quote qualified identifier.
     *
     * @param string $name
     *
     * @return string
     */
    protected function quoteQualifiedIdentifier(string $name): string
    {
        // If looks like an expression (contains spaces, parentheses, commas or quotes)
        // treat as raw expression but DO NOT accept suspicious unquoted parts silently.
        if (preg_match('/[`\["\'\s\(\),]/', $name)) {
            // allow already-quoted or complex expressions to pass through,
            // but still protect obvious injection attempts by checking for dangerous tokens
            if (preg_match('/;|--|\bDROP\b|\bDELETE\b|\bINSERT\b|\bUPDATE\b|\bSELECT\b|\bUNION\b/i', $name)) {
                throw new \InvalidArgumentException('Unsafe SQL expression provided as identifier/expression.');
            }
            return $name;
        }

        $parts = explode('.', $name);
        foreach ($parts as $p) {
            // require valid simple identifier parts
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $p)) {
                throw new \InvalidArgumentException("Invalid identifier part: {$p}");
            }
        }
        $quoted = array_map(fn ($p) => $this->dialect->quoteIdentifier($p), $parts);
        return implode('.', $quoted);
    }

    /**
     * Resolve RawValue instances.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    protected function resolveRawValue(string|RawValue $value): string
    {
        return $this->rawValueResolver->resolveRawValue($value);
    }

    /**
     * Normalizes a table name by prefixing it with the database prefix if it is set.
     *
     * @param string|null $table
     *
     * @return string The normalized table name.
     */
    protected function normalizeTable(?string $table = null): string
    {
        $table = $table ?: $this->table;
        return $this->dialect->quoteTable($this->prefix . $table);
    }

    /**
     * Check if a string represents an external table reference.
     *
     * @param string $reference The reference to check (e.g., 'users.id')
     *
     * @return bool True if it's an external reference
     */
    protected function isExternalReference(string $reference): bool
    {
        // Check if it matches table.column pattern
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $reference)) {
            return false;
        }

        $table = explode('.', $reference)[0];
        return !$this->isTableInCurrentQuery($table);
    }

    /**
     * Check if a table is referenced in the current query.
     *
     * @param string $tableName The table name to check
     *
     * @return bool True if table is in current query
     */
    protected function isTableInCurrentQuery(string $tableName): bool
    {
        $currentTables = $this->getCurrentTables();

        foreach ($currentTables as $table) {
            // Handle aliases (e.g., 'users AS u' -> 'u')
            $alias = $this->extractTableAlias($table);
            if ($alias === $tableName || $table === $tableName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all tables referenced in the current query.
     *
     * @return array<string> Array of table names/aliases
     */
    protected function getCurrentTables(): array
    {
        $tables = [];

        // Main table
        if ($this->table) {
            $tables[] = $this->table;
        }

        // JOIN tables
        foreach ($this->joinBuilder->getJoins() as $join) {
            // Extract table name from JOIN string (e.g., "LEFT JOIN users ON ..." -> "users")
            if (preg_match('/JOIN\s+([a-zA-Z_][a-zA-Z0-9_]*(?:\s+AS\s+[a-zA-Z_][a-zA-Z0-9_]*)?)/i', $join, $matches)) {
                $tables[] = trim($matches[1]);
            }
        }

        return $tables;
    }

    /**
     * Extract table alias from table reference.
     *
     * @param string $tableReference The table reference (e.g., 'users AS u', 'users')
     *
     * @return string The alias or table name
     */
    protected function extractTableAlias(string $tableReference): string
    {
        // Handle 'table AS alias' pattern
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', trim($tableReference), $matches)) {
            return trim($matches[2]);
        }

        return trim($tableReference);
    }

    /**
     * Automatically convert external references to RawValue.
     *
     * @param mixed $value The value to process
     *
     * @return mixed Processed value
     */
    protected function processExternalReferences(mixed $value): mixed
    {
        if (is_string($value) && $this->isExternalReference($value)) {
            return new RawValue($value);
        }

        return $value;
    }
}
