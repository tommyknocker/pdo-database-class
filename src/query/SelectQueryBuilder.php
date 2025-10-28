<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use PDO;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\interfaces\ConditionBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\JoinBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;
use tommyknocker\pdodb\query\interfaces\SelectQueryBuilderInterface;
use tommyknocker\pdodb\query\pagination\Cursor;
use tommyknocker\pdodb\query\pagination\CursorPaginationResult;
use tommyknocker\pdodb\query\pagination\PaginationResult;
use tommyknocker\pdodb\query\pagination\SimplePaginationResult;
use tommyknocker\pdodb\query\traits\CommonDependenciesTrait;
use tommyknocker\pdodb\query\traits\ExternalReferenceProcessingTrait;
use tommyknocker\pdodb\query\traits\IdentifierQuotingTrait;
use tommyknocker\pdodb\query\traits\RawValueResolutionTrait;
use tommyknocker\pdodb\query\traits\TableManagementTrait;

class SelectQueryBuilder implements SelectQueryBuilderInterface
{
    use CommonDependenciesTrait;
    use RawValueResolutionTrait;
    use TableManagementTrait;
    use IdentifierQuotingTrait;
    use ExternalReferenceProcessingTrait;

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

    /** @var int PDO fetch mode */
    protected int $fetchMode = PDO::FETCH_ASSOC;

    /** @var array<int|string, mixed> Query options (e.g., FOR UPDATE, IGNORE) */
    protected array $options = [];

    protected ConditionBuilderInterface $conditionBuilder;
    protected JoinBuilderInterface $joinBuilder;

    public function __construct(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager,
        ExecutionEngineInterface $executionEngine,
        ConditionBuilderInterface $conditionBuilder,
        JoinBuilderInterface $joinBuilder,
        RawValueResolver $rawValueResolver
    ) {
        $this->initializeCommonDependencies($connection, $parameterManager, $executionEngine, $rawValueResolver);
        $this->conditionBuilder = $conditionBuilder;
        $this->joinBuilder = $joinBuilder;
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
     * @param RawValue|callable(QueryBuilder): void|string|array<int|string, string|RawValue|callable(QueryBuilder): void> $cols The columns to add.
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
     * Add ORDER BY expression directly (for JSON expressions that already contain direction).
     *
     * @param string $expr The complete ORDER BY expression.
     *
     * @return self The current instance.
     */
    public function addOrderExpression(string $expr): self
    {
        $this->order[] = $expr;
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
        $params = $this->parameterManager->getParams();
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
     * Get indexes for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function indexes(): array
    {
        $tableName = $this->table;
        assert(is_string($tableName));
        $sql = $this->dialect->buildShowIndexesSql($tableName);
        return $this->executionEngine->fetchAll($sql);
    }

    /**
     * Get foreign keys for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function keys(): array
    {
        $tableName = $this->table;
        assert(is_string($tableName));
        $sql = $this->dialect->buildShowForeignKeysSql($tableName);
        return $this->executionEngine->fetchAll($sql);
    }

    /**
     * Get constraints for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function constraints(): array
    {
        $tableName = $this->table;
        assert(is_string($tableName));
        $sql = $this->dialect->buildShowConstraintsSql($tableName);
        return $this->executionEngine->fetchAll($sql);
    }

    /**
     * Get the current query SQL and parameters.
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    public function getQuery(): array
    {
        $sql = $this->buildSelectSql();
        $params = $this->parameterManager->getParams();
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

    /* ---------------- Pagination methods ---------------- */

    /**
     * Paginate the query results with full metadata.
     *
     * Performs two queries: COUNT(*) for total and SELECT for items.
     * Best for traditional page-number pagination.
     *
     * @param int $perPage Items per page
     * @param int|null $page Current page (null = auto-detect from $_GET['page'])
     * @param array<string, mixed> $options Additional options (path, query)
     *
     * @return PaginationResult
     * @throws PDOException
     */
    public function paginate(int $perPage = 15, ?int $page = null, array $options = []): PaginationResult
    {
        // Auto-detect page from query string if not provided
        if ($page === null) {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        }

        $page = max(1, $page);

        // Build count SQL
        $countSql = $this->buildSelectSql();
        $countSql = (string) preg_replace('/^SELECT\s+.*?\s+FROM/is', 'SELECT COUNT(*) as total FROM', $countSql);
        $countSql = (string) preg_replace('/\s+(ORDER BY|LIMIT|OFFSET)\s+.*/is', '', $countSql);

        // Get copy of params for count query
        $countParams = $this->parameterManager->getParams();

        // Build items SQL with pagination
        $savedLimit = $this->limit;
        $savedOffset = $this->offset;
        $offset = ($page - 1) * $perPage;
        $this->limit($perPage)->offset($offset);
        $itemsSql = $this->buildSelectSql();
        $itemsParams = $this->parameterManager->getParams();

        // Restore original state
        if ($savedLimit !== null) {
            $this->limit($savedLimit);
        } else {
            $this->limit = null;
        }
        if ($savedOffset !== null) {
            $this->offset($savedOffset);
        } else {
            $this->offset = null;
        }

        // Execute both queries
        $totalResult = $this->executionEngine->fetch($countSql, $countParams);
        $total = (int)($totalResult['total'] ?? 0);

        $items = $this->executionEngine->fetchAll($itemsSql, $itemsParams);

        return new PaginationResult($items, $total, $perPage, $page, $options);
    }

    /**
     * Simple pagination without total count.
     *
     * Performs only one query, making it faster than paginate().
     * Best for infinite scroll or when total count is not needed.
     *
     * @param int $perPage Items per page
     * @param int|null $page Current page (null = auto-detect)
     * @param array<string, mixed> $options Additional options
     *
     * @return SimplePaginationResult
     * @throws PDOException
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null, array $options = []): SimplePaginationResult
    {
        if ($page === null) {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        }

        $page = max(1, $page);

        // Fetch one extra item to check if there are more pages
        $offset = ($page - 1) * $perPage;
        $items = $this->limit($perPage + 1)->offset($offset)->get();

        $hasMore = count($items) > $perPage;
        if ($hasMore) {
            array_pop($items); // Remove the extra item
        }

        return new SimplePaginationResult($items, $perPage, $page, $hasMore, $options);
    }

    /**
     * Cursor-based pagination.
     *
     * Most efficient for large datasets and real-time data.
     * Requires ORDER BY clause to determine cursor columns.
     *
     * @param int $perPage Items per page
     * @param string|Cursor|null $cursor Current cursor (null = first page)
     * @param array<string, mixed> $options Additional options
     *
     * @return CursorPaginationResult
     * @throws PDOException
     */
    public function cursorPaginate(
        int $perPage = 15,
        string|Cursor|null $cursor = null,
        array $options = []
    ): CursorPaginationResult {
        // Decode cursor if string
        if (is_string($cursor)) {
            $cursor = Cursor::decode($cursor);
        }

        // Auto-detect cursor from query string
        if ($cursor === null && isset($_GET['cursor'])) {
            $cursor = Cursor::decode($_GET['cursor']);
        }

        // Determine cursor columns from ORDER BY
        $cursorColumns = $this->getCursorColumns();
        if (empty($cursorColumns)) {
            throw new RuntimeException('Cursor pagination requires ORDER BY clause');
        }

        // Apply cursor conditions if provided
        if ($cursor !== null) {
            $this->applyCursorConditions($cursor, $cursorColumns);
        }

        // Fetch items
        $items = $this->limit($perPage + 1)->get();
        $hasMore = count($items) > $perPage;

        if ($hasMore) {
            array_pop($items); // Remove extra item
        }

        // Create cursors
        $previousCursor = null;
        $nextCursor = $hasMore && count($items) > 0
            ? Cursor::fromItem($items[count($items) - 1], $cursorColumns)
            : null;

        return new CursorPaginationResult($items, $perPage, $previousCursor, $nextCursor, $options);
    }

    /**
     * Get cursor columns from ORDER BY clause.
     *
     * @return array<int, string>
     */
    protected function getCursorColumns(): array
    {
        $columns = [];
        foreach ($this->order as $orderExpr) {
            // Extract column name from "column ASC" or "column DESC"
            if (preg_match('/^([^\s]+)/', $orderExpr, $matches)) {
                $columns[] = trim($matches[1], '"`');
            }
        }
        return $columns;
    }

    /**
     * Apply cursor conditions to query.
     *
     * @param Cursor $cursor
     * @param array<int, string> $columns
     */
    protected function applyCursorConditions(Cursor $cursor, array $columns): void
    {
        $params = $cursor->parameters();

        // For simplicity, build individual column comparisons
        // More advanced: composite key comparison for better performance
        foreach ($columns as $col) {
            if (isset($params[$col])) {
                $paramName = 'cursor_' . $col;
                $this->conditionBuilder->where($col, $params[$col], '>');
            }
        }
    }
}
