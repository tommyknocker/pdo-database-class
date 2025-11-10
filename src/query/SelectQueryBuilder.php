<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use PDO;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\helpers\values\AsValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalyzer;
use tommyknocker\pdodb\query\cache\QueryCompilationCache;
use tommyknocker\pdodb\query\cte\CteManager;
use tommyknocker\pdodb\query\formatter\SqlFormatter;
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

    /** @var CacheManager|null Cache manager instance */
    protected ?CacheManager $cacheManager = null;

    /** @var QueryCompilationCache|null Query compilation cache instance */
    protected ?QueryCompilationCache $compilationCache = null;

    /** @var bool Whether caching is enabled for this query */
    protected bool $cacheEnabled = false;

    /** @var int|null Cache TTL in seconds */
    protected ?int $cacheTtl = null;

    /** @var string|null Custom cache key */
    protected ?string $cacheKey = null;

    /** @var CteManager|null CTE manager for WITH clauses */
    protected ?CteManager $cteManager = null;

    /** @var array<UnionQuery> Array of UNION/INTERSECT/EXCEPT operations */
    protected array $unions = [];

    /** @var bool Whether to use DISTINCT */
    protected bool $distinct = false;

    /** @var array<string> Columns for DISTINCT ON (PostgreSQL) */
    protected array $distinctOn = [];

    /** @var string|null Column name to index results by (null = no indexing) */
    protected ?string $indexColumn = null;

    /** @var array{sql: string, params: array<string, mixed>}|null Cached SQL data to avoid double compilation */
    protected ?array $cachedSqlData = null;

    /** @var string|null Cached cache key to avoid regenerating it */
    protected ?string $cachedCacheKey = null;

    protected ConditionBuilderInterface $conditionBuilder;
    protected JoinBuilderInterface $joinBuilder;

    public function __construct(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager,
        ExecutionEngineInterface $executionEngine,
        ConditionBuilderInterface $conditionBuilder,
        JoinBuilderInterface $joinBuilder,
        RawValueResolver $rawValueResolver,
        ?CacheManager $cacheManager = null,
        ?QueryCompilationCache $compilationCache = null
    ) {
        $this->initializeCommonDependencies($connection, $parameterManager, $executionEngine, $rawValueResolver);
        $this->conditionBuilder = $conditionBuilder;
        $this->joinBuilder = $joinBuilder;
        $this->cacheManager = $cacheManager;
        $this->compilationCache = $compilationCache;
    }

    /**
     * Set table name.
     *
     * @param string $table
     *
     * @return static
     */
    public function setTable(string $table): static
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
     * @return static
     */
    public function setPrefix(?string $prefix): static
    {
        $this->prefix = $prefix;
        $this->conditionBuilder->setPrefix($prefix);
        $this->joinBuilder->setPrefix($prefix);
        return $this;
    }

    /**
     * Set CTE manager.
     *
     * @param CteManager|null $cteManager
     *
     * @return static
     */
    public function setCteManager(?CteManager $cteManager): static
    {
        $this->cteManager = $cteManager;
        return $this;
    }

    /**
     * Set UNION operations.
     *
     * @param array<UnionQuery> $unions Array of union operations.
     *
     * @return static
     */
    public function setUnions(array $unions): static
    {
        $this->unions = $unions;
        return $this;
    }

    /**
     * Set DISTINCT flag.
     *
     * @param bool $distinct Whether to use DISTINCT.
     *
     * @return static
     */
    public function setDistinct(bool $distinct): static
    {
        $this->distinct = $distinct;
        return $this;
    }

    /**
     * Set DISTINCT ON columns.
     *
     * @param array<string> $columns Columns for DISTINCT ON.
     *
     * @return static
     */
    public function setDistinctOn(array $columns): static
    {
        $this->distinctOn = $columns;
        return $this;
    }

    /**
     * Set column name to index results by.
     *
     * @param string|null $columnName Column name to use as array keys (null = no indexing)
     *
     * @return static
     */
    public function setIndexColumn(?string $columnName): static
    {
        $this->indexColumn = $columnName;
        return $this;
    }

    /**
     * Index result array by specified column.
     *
     * @param array<int, array<string, mixed>> $result Query result
     * @param string $columnName Column name to use as array keys
     *
     * @return array<int|string, array<string, mixed>> Indexed result
     */
    protected function indexResult(array $result, string $columnName): array
    {
        $indexed = [];
        foreach ($result as $row) {
            if (!isset($row[$columnName])) {
                // If column doesn't exist, skip indexing for this row
                continue;
            }
            $indexed[$row[$columnName]] = $row;
        }
        return $indexed;
    }

    /**
     * Add columns to the SELECT clause.
     *
     * @param RawValue|callable(QueryBuilder): void|string|array<int|string, string|RawValue|callable(QueryBuilder): void> $cols The columns to add.
     *
     * @return static The current instance.
     */
    public function select(RawValue|callable|string|array $cols): static
    {
        if (!is_array($cols)) {
            $cols = [$cols];
        }
        foreach ($cols as $index => $col) {
            if ($col instanceof AsValue) {
                // Handle AsValue - use its alias
                $value = $col->getValue();
                $alias = $this->dialect->quoteIdentifier($col->getAlias());
                if ($value instanceof RawValue) {
                    $this->select[] = $this->resolveRawValue($value) . ' AS ' . $alias;
                } elseif (is_numeric($value)) {
                    $this->select[] = (string)$value . ' AS ' . $alias;
                } else {
                    // Process string value (may contain column references)
                    $processedValue = $this->processExternalReferences((string)$value);
                    if ($processedValue instanceof RawValue) {
                        $this->select[] = $this->resolveRawValue($processedValue) . ' AS ' . $alias;
                    } else {
                        $this->select[] = $processedValue . ' AS ' . $alias;
                    }
                }
            } elseif ($col instanceof RawValue && is_string($index)) {
                $alias = $this->dialect->quoteIdentifier($index);
                $this->select[] = $this->resolveRawValue($col) . ' AS ' . $alias;
            } elseif ($col instanceof RawValue) {
                $this->select[] = $this->resolveRawValue($col);
            } elseif (is_callable($col)) {
                // Handle callback for subqueries
                $subQuery = new QueryBuilder($this->connection, $this->prefix ?? '');
                $col($subQuery);
                $sub = $subQuery->toSQL();
                $map = $this->parameterManager->mergeSubParams($sub['params'], 'sq');
                $subSql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
                $alias = is_string($index) ? $this->dialect->quoteIdentifier($index) : '';
                $this->select[] = is_string($index) ? "({$subSql}) AS {$alias}" : "({$subSql})";
            } elseif (is_string($index)) { // ['total' => 'SUM(amount)] Treat it as SUM(amount) AS total
                // Process external references in column expressions
                $processedCol = $this->processExternalReferences($col);
                $alias = $this->dialect->quoteIdentifier($index);
                if ($processedCol instanceof RawValue) {
                    $this->select[] = $this->resolveRawValue($processedCol) . ' AS ' . $alias;
                } else {
                    $colStr = is_string($col) ? $col : (string)$col;
                    $this->select[] = $colStr . ' AS ' . $alias;
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
     * @return array<int|string, array<string, mixed>>
     * @throws PDOException
     */
    public function get(): array
    {
        // Fast path: if cache is disabled, skip all cache operations
        if (!$this->shouldUseCache()) {
            $sqlData = $this->toSQL();

            try {
                $result = $this->executionEngine->fetchAll($sqlData['sql'], $sqlData['params']);
            } catch (PDOException $e) {
                $this->enhanceExceptionWithContext($e, $sqlData['sql']);

                throw $e;
            }
            // Index result by column if specified
            if ($this->indexColumn !== null) {
                $result = $this->indexResult($result, $this->indexColumn);
            }
            return $result;
        }

        // Cache enabled: try to get from cache first
        $cached = $this->getFromCache();
        if (is_array($cached)) {
            // Cache hit: return immediately, no SQL compilation needed
            $this->cachedSqlData = null;
            $this->cachedCacheKey = null;
            // Index cached result if needed
            if ($this->indexColumn !== null) {
                $cached = $this->indexResult($cached, $this->indexColumn);
            }
            return $cached;
        }

        // Cache miss: use cached SQL data if available (from getFromCache call)
        $sqlData = $this->cachedSqlData ?? $this->toSQL();

        try {
            $result = $this->executionEngine->fetchAll($sqlData['sql'], $sqlData['params']);
        } catch (PDOException $e) {
            $this->enhanceExceptionWithContext($e, $sqlData['sql']);

            throw $e;
        }

        // Index result by column if specified
        if ($this->indexColumn !== null) {
            $result = $this->indexResult($result, $this->indexColumn);
        }

        // Save to cache (uses cached key if available)
        $this->saveToCache($result);

        // Clear cache after use
        $this->cachedSqlData = null;
        $this->cachedCacheKey = null;

        return $result;
    }

    /**
     * Execute SELECT statement and return first row.
     *
     * @return mixed
     * @throws PDOException
     */
    public function getOne(): mixed
    {
        // Fast path: if cache is disabled, skip all cache operations
        if (!$this->shouldUseCache()) {
            $sqlData = $this->toSQL();

            try {
                return $this->executionEngine->fetch($sqlData['sql'], $sqlData['params']);
            } catch (PDOException $e) {
                $this->enhanceExceptionWithContext($e, $sqlData['sql']);

                throw $e;
            }
        }

        // Cache enabled: try to get from cache first
        $cached = $this->getFromCache();
        if ($cached !== null) {
            // Cache hit: return immediately, no SQL compilation needed
            $this->cachedSqlData = null;
            $this->cachedCacheKey = null;
            return $cached;
        }

        // Cache miss: use cached SQL data if available (from getFromCache call)
        $sqlData = $this->cachedSqlData ?? $this->toSQL();

        try {
            $result = $this->executionEngine->fetch($sqlData['sql'], $sqlData['params']);
        } catch (PDOException $e) {
            $this->enhanceExceptionWithContext($e, $sqlData['sql']);

            throw $e;
        }

        // Save to cache (uses cached key if available)
        $this->saveToCache($result);

        // Clear cache after use
        $this->cachedSqlData = null;
        $this->cachedCacheKey = null;

        return $result;
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

        if ($this->shouldUseCache()) {
            $cached = $this->getFromCache();
            if ($cached !== null) {
                // Clear cached SQL data since we didn't execute
                $this->cachedSqlData = null;
                return $cached;
            }
        }

        $key = $this->resolveSelectedKey();
        $rows = $this->get();
        $result = array_column($rows, $key);

        if ($this->shouldUseCache()) {
            $this->saveToCache($result);
        }

        return $result;
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

        if ($this->shouldUseCache()) {
            $cached = $this->getFromCache();
            if ($cached !== null) {
                // Clear cached SQL data since we didn't execute
                $this->cachedSqlData = null;
                $this->cachedCacheKey = null;
                return $cached;
            }
        }

        // Temporarily disable cache for getOne() call to avoid double caching
        // We'll cache the final value ourselves
        $wasCacheEnabled = $this->cacheEnabled;
        $this->cacheEnabled = false;

        $row = $this->getOne();

        // Restore cache setting
        $this->cacheEnabled = $wasCacheEnabled;

        // Check if row is valid array
        if (!is_array($row) || empty($row)) {
            return null;
        }

        $key = $this->resolveSelectedKey();
        if (count($row) === 1 && !isset($row[$key])) {
            $result = array_shift($row);
        } else {
            $result = $row[$key] ?? null;
        }

        if ($this->shouldUseCache()) {
            $this->saveToCache($result);
        }

        return $result;
    }

    /**
     * Add ORDER BY clause.
     *
     * @param string|array<int|string, string>|RawValue $expr The expression(s) to order by.
     *                                                        - string: 'column' or 'column ASC' or 'column1 ASC, column2 DESC'
     *                                                        - array: ['column1', 'column2'] or ['column1' => 'ASC', 'column2' => 'DESC']
     *                                                        - RawValue: raw SQL expression
     * @param string $direction The direction of the ordering (ASC or DESC). Ignored when expr is array.
     *
     * @return static The current instance.
     */
    public function orderBy(string|array|RawValue $expr, string $direction = 'ASC'): static
    {
        // Handle array of columns
        if (is_array($expr)) {
            foreach ($expr as $col => $dir) {
                if (is_int($col)) {
                    // Numeric key: ['column1', 'column2'] - use default direction
                    $this->orderBy($dir, $direction);
                } else {
                    // Associative: ['column1' => 'ASC', 'column2' => 'DESC']
                    $this->orderBy($col, $dir);
                }
            }
            return $this;
        }

        $dir = strtoupper(trim($direction));
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            $dir = 'ASC';
        }

        if ($expr instanceof RawValue) {
            $this->order[] = $this->resolveRawValue($expr) . ' ' . $dir;
        } elseif (str_contains($expr, ',')) {
            // Handle comma-separated: 'column1 ASC, column2 DESC'
            $parts = array_map('trim', explode(',', $expr));
            foreach ($parts as $part) {
                if (preg_match('/^(.+?)\s+(ASC|DESC)$/i', $part, $matches)) {
                    $col = trim($matches[1]);
                    $partDir = strtoupper($matches[2]);
                    $processedExpr = $this->processExternalReferences($col);
                    if ($processedExpr instanceof RawValue) {
                        $this->order[] = $this->resolveRawValue($processedExpr) . ' ' . $partDir;
                    } else {
                        $this->order[] = $this->quoteQualifiedIdentifier($col) . ' ' . $partDir;
                    }
                } else {
                    // No direction specified, use default
                    $processedExpr = $this->processExternalReferences($part);
                    if ($processedExpr instanceof RawValue) {
                        $this->order[] = $this->resolveRawValue($processedExpr) . ' ' . $dir;
                    } else {
                        $this->order[] = $this->quoteQualifiedIdentifier($part) . ' ' . $dir;
                    }
                }
            }
        } elseif (preg_match('/^[a-z0-9._`"]+\s+(ASC|DESC)$/iu', $expr)) {
            // Single column with direction: 'column ASC'
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
     * @return static The current instance.
     */
    public function addOrderExpression(string $expr): static
    {
        $this->order[] = $expr;
        return $this;
    }

    /**
     * Enable caching for this query.
     *
     * @param int $ttl Time-to-live in seconds (0 = disable cache for this query)
     * @param string|null $key Custom cache key (null = auto-generate)
     *
     * @return static The current instance.
     */
    public function cache(int $ttl = 3600, ?string $key = null): static
    {
        if ($ttl <= 0) {
            // TTL of 0 or negative means disable cache for this query
            $this->cacheEnabled = false;
            $this->cacheTtl = null;
            $this->cacheKey = null;
        } else {
            $this->cacheEnabled = true;
            $this->cacheTtl = $ttl;
            $this->cacheKey = $key;
        }
        return $this;
    }

    /**
     * Disable caching for this query.
     *
     * @return static The current instance.
     */
    public function noCache(): static
    {
        $this->cacheEnabled = false;
        $this->cacheTtl = null;
        $this->cacheKey = null;
        return $this;
    }

    /**
     * Add GROUP BY clause.
     *
     * @param string|array<int, string|RawValue>|RawValue $cols The columns to group by.
     *
     * @return static The current instance.
     */
    public function groupBy(string|array|RawValue $cols): static
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
                    // If it's a qualified identifier (table.column), quote it properly
                    // RawValue from processExternalReferences means it's an external reference,
                    // but for GROUP BY we should quote qualified identifiers
                    $rawValue = $processedCol->getValue();
                    // Check if it's a simple qualified identifier (table.column pattern)
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $rawValue)) {
                        $groups[] = $this->quoteQualifiedIdentifier($rawValue);
                    } else {
                        $groups[] = $this->resolveRawValue($processedCol);
                    }
                } else {
                    $groups[] = $this->quoteQualifiedIdentifier((string)$processedCol);
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
     * @return static The current instance.
     */
    public function limit(int $number): static
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
     * @return static The current instance.
     */
    public function offset(int $number): static
    {
        $this->offset = $number;
        return $this;
    }

    /**
     * Sets the query options.
     *
     * @param string|array<int|string, mixed> $options The query options.
     *
     * @return static The current object.
     */
    public function option(string|array $options): static
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
     * @return static
     */
    public function asObject(): static
    {
        $this->fetchMode = PDO::FETCH_OBJ;
        $this->executionEngine->setFetchMode(PDO::FETCH_OBJ);
        return $this;
    }

    /**
     * Convert query to SQL string and parameters.
     *
     * @param bool $formatted Whether to format SQL for readability
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(bool $formatted = false): array
    {
        $sql = $this->buildSelectSql();
        // Get params after building SQL (includes params added during UNION)
        $params = $this->parameterManager->getParams();

        // Merge CTE parameters if CTE manager exists
        if ($this->cteManager && !$this->cteManager->isEmpty()) {
            $cteParams = $this->cteManager->getParams();
            $params = array_merge($cteParams, $params);
        }

        // Format SQL if requested
        if ($formatted) {
            $formatter = new SqlFormatter(
                highlightKeywords: false,
                indentSize: 4,
                indentChar: ' '
            );
            $sql = $formatter->format($sql);
        }

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
        $sqlData = $this->toSQL();
        // executeExplain() will call buildExplainSql() internally
        return $this->dialect->executeExplain($this->connection->getPdo(), $sqlData['sql'], $sqlData['params']);
    }

    /**
     * Execute EXPLAIN ANALYZE query (PostgreSQL) or EXPLAIN FORMAT=JSON (MySQL).
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function explainAnalyze(): array
    {
        $sqlData = $this->toSQL();
        $explainSql = $this->dialect->buildExplainAnalyzeSql($sqlData['sql']);
        return $this->executionEngine->fetchAll($explainSql, $sqlData['params']);
    }

    /**
     * Analyze EXPLAIN output with optimization recommendations.
     *
     * @param string|null $tableName Optional table name for index suggestions
     *
     * @return ExplainAnalysis Analysis result with recommendations
     */
    public function explainAdvice(?string $tableName = null): ExplainAnalysis
    {
        $sqlData = $this->toSQL();
        $explainSql = $this->dialect->buildExplainSql($sqlData['sql']);
        $explainResults = $this->executionEngine->fetchAll($explainSql, $sqlData['params']);

        $analyzer = new ExplainAnalyzer($this->dialect, $this->executionEngine);
        $targetTable = $tableName ?? $this->table;

        return $analyzer->analyze($explainResults, $targetTable);
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
        return $this->toSQL();
    }

    /**
     * Build SELECT sql.
     *
     * @return string
     */
    public function buildSelectSql(): string
    {
        // Try to get from compilation cache if enabled
        if ($this->compilationCache !== null && $this->compilationCache->isEnabled()) {
            $structure = $this->getQueryStructure();
            $driver = $this->connection->getDriverName();

            return $this->compilationCache->getOrCompile(
                fn (): string => $this->compileSelectSql(),
                $structure,
                $driver
            );
        }

        return $this->compileSelectSql();
    }

    /**
     * Get query structure for caching purposes.
     *
     * @return array<string, mixed>
     */
    protected function getQueryStructure(): array
    {
        $where = $this->conditionBuilder->getWhere();
        $having = $this->conditionBuilder->getHaving();

        // Extract CTE info
        $hasCte = $this->cteManager !== null && !$this->cteManager->isEmpty();

        return [
            'table' => $this->table,
            'select' => $this->select,
            'distinct' => $this->distinct,
            'distinct_on' => $this->distinctOn,
            'joins' => $this->joinBuilder->getJoins(),
            'where' => $where,
            'group_by' => $this->group,
            'having' => $having,
            'order_by' => $this->order,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'options' => $this->options,
            'unions' => $this->unions,
            'cte' => $hasCte ? true : null,
        ];
    }

    /**
     * Compile SELECT SQL (internal method, called directly or via cache).
     *
     * @return string
     */
    protected function compileSelectSql(): string
    {
        $sql = '';

        // Add WITH clause if CTEs exist
        if ($this->cteManager && !$this->cteManager->isEmpty()) {
            $sql = $this->cteManager->buildSql() . ' ';
        }

        // build base select (no DB-specific option handling)
        if (empty($this->select)) {
            $select = '*';
        } else {
            $select = implode(', ', array_map(function ($value) {
                // Check if it's already a compiled subquery (starts with '(')
                if (str_starts_with($value, '(')) {
                    return $value;
                }
                // Check if it contains a subquery in parentheses (e.g., "alias AS (SELECT ...)")
                // This handles cases like recursive CTEs and other subqueries
                if (preg_match('/\s+AS\s+\(/i', $value) || preg_match('/\(SELECT\s+/i', $value)) {
                    return $value;
                }
                // Allow wildcards and preformatted lists/expressions:
                // - "*"
                // - "alias.*"
                // - lists like "a.*, b.*"
                // - any expression containing commas or '*' should pass through
                if ($value === '*') {
                    return $value;
                }
                // alias.*
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\\.\*$/', $value) === 1) {
                    return $value;
                }
                // multiple alias.* segments separated by comma: a.*, b.*
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\\.\*(\s*,\s*[A-Za-z_][A-Za-z0-9_]*\\.\*)+$/', $value) === 1) {
                    return $value;
                }

                return $this->quoteQualifiedIdentifier($value);
            }, $this->select));
        }

        // Add DISTINCT or DISTINCT ON
        $distinctClause = '';
        if (!empty($this->distinctOn)) {
            // DISTINCT ON - verify dialect support
            if (!$this->dialect->supportsDistinctOn()) {
                throw new RuntimeException(
                    'DISTINCT ON is not supported by ' . get_class($this->dialect)
                );
            }
            $columns = array_map(
                fn ($col) => $this->dialect->quoteIdentifier($col),
                $this->distinctOn
            );
            $distinctClause = 'DISTINCT ON (' . implode(', ', $columns) . ') ';
        } elseif ($this->distinct) {
            $distinctClause = 'DISTINCT ';
        }

        $from = $this->normalizeTable();
        $sql .= "SELECT {$distinctClause}{$select} FROM {$from}";

        if (!empty($this->joinBuilder->getJoins())) {
            $sql .= ' ' . implode(' ', $this->joinBuilder->getJoins());
        }

        $sql .= $this->conditionBuilder->buildConditionsClause($this->conditionBuilder->getWhere(), 'WHERE');

        if (!empty($this->group)) {
            $sql .= ' GROUP BY ' . $this->group;
        }

        $sql .= $this->conditionBuilder->buildConditionsClause($this->conditionBuilder->getHaving(), 'HAVING');

        // If there are UNION operations, add ORDER BY/LIMIT/OFFSET after UNION
        if (empty($this->unions)) {
            if (!empty($this->order)) {
                $sql .= ' ORDER BY ' . implode(', ', $this->order);
            }

            // Format LIMIT/OFFSET using dialect-specific method
            $sql = $this->dialect->formatLimitOffset($sql, $this->limit, $this->offset);

            $sql = $this->dialect->formatSelectOptions($sql, $this->options);
        } else {
            // For UNION, format options first, then add UNION, then ORDER BY/LIMIT/OFFSET
            // Store original limit/offset for UNION (some dialects need to remove LIMIT from base query)
            $originalLimit = $this->limit;
            $originalOffset = $this->offset;

            // Format base query for UNION (remove LIMIT if needed by dialect)
            $sql = $this->dialect->formatUnionSelect($sql, true);

            // Temporarily remove limit/offset from base query for UNION
            $this->limit = null;
            $this->offset = null;

            $sql = $this->dialect->formatSelectOptions($sql, $this->options);

            // Build UNION SQL
            $sql = $this->buildUnionSql($sql);

            // Restore limit/offset for UNION
            $this->limit = $originalLimit;
            $this->offset = $originalOffset;

            // Add ORDER BY/LIMIT/OFFSET after UNION operations
            if (!empty($this->order)) {
                $sql .= ' ORDER BY ' . implode(', ', $this->order);
            }

            // Format LIMIT/OFFSET using dialect-specific method
            $sql = $this->dialect->formatLimitOffset($sql, $this->limit, $this->offset);
        }

        return trim($sql);
    }

    /**
     * Set query compilation cache.
     *
     * @param QueryCompilationCache|null $cache Compilation cache instance
     *
     * @return static
     */
    public function setCompilationCache(?QueryCompilationCache $cache): static
    {
        $this->compilationCache = $cache;
        return $this;
    }

    /**
     * Build SQL for UNION operations.
     *
     * @param string $baseSql Base SELECT SQL.
     *
     * @return string Complete SQL with UNION operations.
     */
    protected function buildUnionSql(string $baseSql): string
    {
        $needsParentheses = $this->dialect->needsUnionParentheses();
        $sql = $needsParentheses ? "({$baseSql})" : $baseSql;

        // Store params before adding union params to restore them if needed
        $paramsBeforeUnion = $this->parameterManager->getParams();
        $paramsCountBefore = count($paramsBeforeUnion);

        foreach ($this->unions as $index => $union) {
            $query = $union->getQuery();
            $type = $union->getType();

            if ($query instanceof \Closure) {
                // Create QueryBuilder with same connection
                // QueryBuilder will auto-detect dialect from connection
                $qb = new QueryBuilder($this->connection);
                $query($qb);
                $unionSqlData = $qb->toSQL();
                $unionSql = $unionSqlData['sql'];
                // Rename parameters to make them unique
                $paramMap = $this->parameterManager->mergeSubParams($unionSqlData['params'], 'union' . $index);
                $unionSql = $this->parameterManager->replacePlaceholdersInSql($unionSql, $paramMap);
            } else {
                // QueryBuilder instance
                $unionSqlData = $query->toSQL();
                $unionSql = $unionSqlData['sql'];
                // Rename parameters to make them unique
                $paramMap = $this->parameterManager->mergeSubParams($unionSqlData['params'], 'union' . $index);
                $unionSql = $this->parameterManager->replacePlaceholdersInSql($unionSql, $paramMap);
            }

            // Format union query using dialect-specific method
            $unionSql = $this->dialect->formatUnionSelect($unionSql, false);

            // Wrap union query in parentheses if needed
            $unionSqlWrapped = $needsParentheses ? "({$unionSql})" : $unionSql;
            $sql .= " {$type} {$unionSqlWrapped}";
        }

        return $sql;
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

        // Get CTE parameters (same for both queries)
        $cteParams = [];
        if ($this->cteManager && !$this->cteManager->isEmpty()) {
            $cteParams = $this->cteManager->getParams();
        }

        // Build count SQL
        $countSql = $this->buildSelectSql();
        $countSql = (string) preg_replace('/^SELECT\s+.*?\s+FROM/is', 'SELECT COUNT(*) as total FROM', $countSql);
        $countSql = (string) preg_replace('/\s+(ORDER BY|LIMIT|OFFSET)\s+.*/is', '', $countSql);

        // Get copy of params for count query (merge with CTE params)
        $countParams = array_merge($cteParams, $this->parameterManager->getParams());

        // Build items SQL with pagination
        $savedLimit = $this->limit;
        $savedOffset = $this->offset;
        $offset = ($page - 1) * $perPage;
        $this->limit($perPage)->offset($offset);
        $itemsSql = $this->buildSelectSql();
        $itemsParams = array_merge($cteParams, $this->parameterManager->getParams());

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

        // For pagination, we need numeric indices, so convert indexed array back if needed
        $numericItems = $this->indexColumn !== null ? array_values($items) : $items;
        /* @var array<int, array<string, mixed>> $numericItems */

        return new PaginationResult($numericItems, $total, $perPage, $page, $options);
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
        $rawItems = $this->limit($perPage + 1)->offset($offset)->get();

        $hasMore = count($rawItems) > $perPage;
        if ($hasMore) {
            array_pop($rawItems); // Remove the extra item
        }

        // For pagination, we need numeric indices, so convert indexed array back if needed
        $numericItems = array_values($rawItems);
        /* @var array<int, array<string, mixed>> $numericItems */

        return new SimplePaginationResult($numericItems, $perPage, $page, $hasMore, $options);
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
        $rawItems = $this->limit($perPage + 1)->get();
        $hasMore = count($rawItems) > $perPage;

        if ($hasMore) {
            array_pop($rawItems); // Remove extra item
        }

        // Create cursors
        $previousCursor = null;
        // For cursor pagination, we need numeric indices, so convert indexed array back if needed
        $numericItems = $this->indexColumn !== null ? array_values($rawItems) : $rawItems;
        /** @var array<int, array<string, mixed>> $numericItems */
        $nextCursor = $hasMore && count($numericItems) > 0
            ? Cursor::fromItem($numericItems[count($numericItems) - 1], $cursorColumns)
            : null;

        return new CursorPaginationResult($numericItems, $perPage, $previousCursor, $nextCursor, $options);
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
                // Remove quotes and brackets (MySQL/MariaDB use backticks, PostgreSQL/SQLite use double quotes, MSSQL uses square brackets)
                $column = trim($matches[1], '"`[]');
                $columns[] = $column;
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

    /* ---------------- Cache helpers ---------------- */

    /**
     * Check if caching should be used for this query.
     */
    protected function shouldUseCache(): bool
    {
        return $this->cacheEnabled && $this->cacheManager !== null;
    }

    /**
     * Get cached result if available.
     *
     * @return mixed|null Cached result or null if not found
     */
    protected function getFromCache(): mixed
    {
        if ($this->cacheManager === null) {
            return null;
        }

        // If custom cache key provided, use it directly (no SQL compilation needed)
        if ($this->cacheKey !== null) {
            $this->cachedCacheKey = $this->cacheKey;
            $cached = $this->cacheManager->get($this->cacheKey);
            // If cache hit, we don't need to compile SQL
            if ($cached !== null) {
                return $cached;
            }
            // Cache miss, but we'll need SQL later anyway, so continue
        }

        // For auto-generated keys, we need SQL to generate the key
        // Generate SQL once and cache it for potential reuse
        if ($this->cachedSqlData === null) {
            $this->cachedSqlData = $this->toSQL();
        }

        // Generate cache key once and reuse it
        if ($this->cachedCacheKey === null) {
            $this->cachedCacheKey = $this->generateCacheKeyFromSqlData($this->cachedSqlData);
        }

        return $this->cacheManager->get($this->cachedCacheKey);
    }

    /**
     * Save result to cache.
     *
     * @param mixed $result The result to cache
     */
    protected function saveToCache(mixed $result): void
    {
        if ($this->cacheManager === null) {
            return;
        }

        // Use cached cache key if available (generated in getFromCache)
        if ($this->cachedCacheKey === null) {
            // If custom key provided, use it
            if ($this->cacheKey !== null) {
                $this->cachedCacheKey = $this->cacheKey;
            } else {
                // Generate key from SQL
                $sqlData = $this->cachedSqlData ?? $this->toSQL();
                $this->cachedCacheKey = $this->generateCacheKeyFromSqlData($sqlData);
            }
        }

        $ttl = $this->cacheTtl ?? $this->cacheManager->getConfig()->getDefaultTtl();
        $this->cacheManager->set($this->cachedCacheKey, $result, $ttl);
    }

    /**
     * Generate cache key for current query.
     */
    protected function generateCacheKey(): string
    {
        if ($this->cacheKey !== null) {
            return $this->cacheKey;
        }

        if ($this->cacheManager === null) {
            return '';
        }

        // Use cached SQL data if available
        $sqlData = $this->cachedSqlData ?? $this->toSQL();
        return $this->generateCacheKeyFromSqlData($sqlData);
    }

    /**
     * Generate cache key from SQL data.
     *
     * @param array{sql: string, params: array<string, mixed>} $sqlData
     *
     * @return string
     */
    protected function generateCacheKeyFromSqlData(array $sqlData): string
    {
        if ($this->cacheManager === null) {
            return '';
        }

        $driver = $this->connection->getDialect()->getDriverName();
        return $this->cacheManager->generateKey($sqlData['sql'], $sqlData['params'], $driver);
    }

    /**
     * Get debug information about the select query.
     *
     * @return array<string, mixed> Debug information about SELECT query state
     */
    public function getDebugInfo(): array
    {
        $info = [];

        if (!empty($this->select)) {
            $info['select'] = $this->select;
            $info['select_count'] = count($this->select);
        }

        if (!empty($this->order)) {
            $info['order'] = $this->order;
            $info['order_count'] = count($this->order);
        }

        if ($this->group !== null) {
            $info['group'] = $this->group;
        }

        if ($this->limit !== null) {
            $info['limit'] = $this->limit;
        }

        if ($this->offset !== null) {
            $info['offset'] = $this->offset;
        }

        if ($this->distinct) {
            $info['distinct'] = true;
        }

        if (!empty($this->distinctOn)) {
            $info['distinct_on'] = $this->distinctOn;
        }

        if (!empty($this->options)) {
            $info['options'] = $this->options;
        }

        if ($this->cacheEnabled) {
            $info['cache_enabled'] = true;
            if ($this->cacheTtl !== null) {
                $info['cache_ttl'] = $this->cacheTtl;
            }
        }

        if (!empty($this->unions)) {
            $info['union_count'] = count($this->unions);
        }

        if ($this->cteManager !== null) {
            $info['has_cte'] = true;
        }

        return $info;
    }

    /**
     * Enhance PDOException with query context if available.
     *
     * Stores query context in Connection's temporary storage so it can be
     * included in exception when Connection::handlePdoException is called.
     */
    protected function enhanceExceptionWithContext(PDOException $e, string $sql): void
    {
        $queryContext = $this->executionEngine->getQueryContext();
        if ($queryContext !== null) {
            $this->connection->setTempQueryContext($queryContext);
        }
    }
}
