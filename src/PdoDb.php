<?php
declare(strict_types=1);

namespace tommyknocker\pdodb;

use RuntimeException;
use InvalidArgumentException;
use PDO;
use Throwable;

class PdoDb
{
    protected ?PDO $pdo = null {
        get {
            if (!$this->pdo instanceof PDO) {
                throw new RuntimeException('PDO object not initialized');
            }
            return $this->pdo;
        }
        set (?PDO $pdo) {
            $this->pdo = $pdo;
        }
    }

    protected string $prefix = '';
    protected array $queryOptions = [];
    protected bool $isSubQuery = false;
    protected array $where = [];
    protected array $join = [];
    protected array $orderBy = [];
    protected array $groupBy = [];
    protected array $having = [];
    protected array $params = [];
    protected string $lastQuery = '';
    protected string $lastError = '';
    protected int $lastErrno = 0;
    protected bool $transactionInProgress = false;
    protected bool $traceEnabled = false;
    protected array $traceLog = [];
    protected int $totalCount = 0;
    protected string $lockMethod = 'WRITE';
    protected array $connections = [];
    protected ?string $lastTableUsed = null;
    protected array $onDuplicateColumns = [];
    protected array $queryOptionsFlags = [];

    public function __construct(string $driver = 'mysql', array $config = [])
    {
        $this->prefix = $config['prefix'] ?? '';

        $this->addConnection('default', [
            'driver' => $driver,
            ...$config
        ]);

        // use default connection
        $this->connection('default');
    }


    /* ---------------- CRUD ---------------- */
    /**
     * Build multi-row insert/replace SQL parts.
     *
     * @param array $multiData
     * @return array [columns string, rows string, params array]
     */
    protected function buildMultiParts(array $multiData): array
    {
        $columns = implode(', ', array_keys($multiData[0]));
        $rows = [];
        $params = [];

        foreach ($multiData as $i => $row) {
            $placeholders = [];
            foreach ($row as $k => $v) {
                $param = ":{$k}_$i";
                $placeholders[] = $param;
                $params[$param] = $v;
            }
            $rows[] = '(' . implode(', ', $placeholders) . ')';
        }

        return [$columns, implode(', ', $rows), $params];
    }


    /**
     * Insert query
     * @param string $table
     * @param array|PdoDb $data
     * @return int|false
     */
    public function insert(string $table, array|self $data): int|false
    {
        $this->lastTableUsed = $table;
        $this->queryOptions['table'] = $table;

        if ($data instanceof self && !empty($data->isSubQuery)) {
            $modifiers = $data->getQueryOptions() ? implode(' ', $this->getQueryOptions()) . ' ' : '';
            $sql = "INSERT {$modifiers}INTO {$this->prefix}$table " . $data->getLastQuery();
            $params = $data->getParams() ?? [];

            if (!empty($this->onDuplicateColumns)) {
                $updates = implode(', ', array_map(static fn($k) => "$k = VALUES($k)", $this->onDuplicateColumns));
                $sql .= " ON DUPLICATE KEY UPDATE $updates";
            }
        } elseif (is_array($data) && count($data) >= 1 && current($data) instanceof self && $this->allValuesAreSameSubQuery($data)) {
            /** @var self $sub */
            $sub = current($data);
            $modifiers = $this->queryOptionsFlags ? implode(' ', $this->queryOptionsFlags) . ' ' : '';
            $columns = implode(', ', array_keys($data));
            $sql = "INSERT {$modifiers}INTO {$this->prefix}$table ($columns) " . $sub->getLastQuery();
            $params = $sub->getParams() ?? [];

            if (!empty($this->onDuplicateColumns)) {
                $updates = implode(', ', array_map(static fn($k) => "$k = VALUES($k)", $this->onDuplicateColumns));
                $sql .= " ON DUPLICATE KEY UPDATE $updates";
            }
        } else {
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_map(static fn($k) => ":$k", array_keys($data)));
            $modifiers = $this->queryOptionsFlags ? implode(' ', $this->queryOptionsFlags) . ' ' : '';

            $sql = "INSERT {$modifiers}INTO {$this->prefix}$table ($columns) VALUES ($placeholders)";

            if (!empty($this->onDuplicateColumns)) {
                $updates = implode(', ', array_map(static fn($k) => "$k = VALUES($k)", $this->onDuplicateColumns));
                $sql .= " ON DUPLICATE KEY UPDATE $updates";
            }

            $params = [];
            foreach ($data as $k => $v) {
                $params[":$k"] = $v;
            }
        }

        $this->lastQuery = $sql;
        $this->logTrace($sql, $params);

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $pk => $pv) {
            $stmt->bindValue(str_starts_with($pk, ':') ? $pk : ":$pk", $pv);
        }

        $ok = $stmt->execute();

        $this->resetQueryState();

        if ($ok) {
            return (int)$this->pdo->lastInsertId();
        }
        return false;
    }

    protected function allValuesAreSameSubQuery(array $data): bool
    {
        $first = current($data);
        if (!($first instanceof self) || empty($first->isSubQuery)) {
            return false;
        }
        return array_all($data, static fn($v) => $v === $first);
    }


    public function insertMulti(string $table, array $multiData): bool
    {
        if (empty($multiData)) {
            return false;
        }

        $this->lastTableUsed = $table;
        $this->queryOptions['table'] = $table;

        [$columns, $rows, $params] = $this->buildMultiParts($multiData);

        $sql = "INSERT INTO {$this->prefix}$table ($columns) VALUES $rows";

        if (!empty($this->onDuplicateColumns)) {
            $updates = implode(', ', array_map(static fn($k) => "$k = VALUES($k)", $this->onDuplicateColumns));
            $sql .= " ON DUPLICATE KEY UPDATE $updates";
        }

        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute($params);

        $this->onDuplicateColumns = [];
        $this->resetQueryState();

        return $ok;
    }

    public function onDuplicate(array $updateColumns): self
    {
        $this->onDuplicateColumns = $updateColumns;
        return $this;
    }

    public function replace(string $table, array $data): int
    {
        $this->lastTableUsed = $table;
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn($k) => ":$k", array_keys($data)));

        $modifiers = $this->queryOptionsFlags ? implode(' ', $this->queryOptionsFlags) . ' ' : '';

        $sql = "REPLACE {$modifiers}INTO {$this->prefix}$table ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);

        foreach ($data as $k => $v) {
            $stmt->bindValue(":$k", $v);
        }

        $stmt->execute();
        $result = $stmt->rowCount();
        $this->resetQueryState();
        return $result;
    }

    public function replaceMulti(string $table, array $multiData): bool
    {
        if (empty($multiData)) {
            return false;
        }

        $this->lastTableUsed = $table;
        $this->queryOptions['table'] = $table;

        [$columns, $rows, $params] = $this->buildMultiParts($multiData);

        $sql = "REPLACE INTO {$this->prefix}$table ($columns) VALUES $rows";

        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute($params);

        $this->resetQueryState();
        return $ok;
    }


    public function update(string $table, array $data): int
    {
        $this->lastTableUsed = $table;

        $setParts = [];
        $bindData = [];

        foreach ($data as $col => $val) {
            if (is_array($val) && isset($val['__op'])) {
                switch ($val['__op']) {
                    case 'inc':
                        $num = (float)$val['val'];
                        $setParts[] = "$col = $col + $num";
                        break;
                    case 'dec':
                        $num = (float)$val['val'];
                        $setParts[] = "$col = $col - $num";
                        break;
                    case 'not':
                        $setParts[] = "$col = NOT $col";
                        break;
                    default:
                        // unknown marker — fallback to bind
                        $setParts[] = "$col = :upd_$col";
                        $bindData[":upd_$col"] = $val;
                        break;
                }
            } elseif (is_string($val) && preg_match('/\bNOW\(\)\b/', $val)) {
                // Raw expression NOW() or NOW() + INTERVAL ...
                $setParts[] = "$col = $val";
            } else {
                // Common expression
                $setParts[] = "$col = :upd_$col";
                $bindData[":upd_$col"] = $val;
            }
        }

        $modifiers = $this->queryOptionsFlags ? implode(' ', $this->queryOptionsFlags) . ' ' : '';

        $sql = "UPDATE {$modifiers}{$this->prefix}$table SET " . implode(', ', $setParts);
        $sql .= $this->buildWhere();

        $this->lastQuery = $sql;
        $this->logTrace($sql, $bindData);

        $stmt = $this->pdo->prepare($sql);

        // Only regular values are bound; raw expressions are not bound.
        foreach ($bindData as $p => $v) {
            $stmt->bindValue($p, $v);
        }
        foreach ($this->params as $p => $v) {
            $stmt->bindValue($p, $v);
        }

        $stmt->execute();
        $result = $stmt->rowCount();
        $this->resetQueryState();
        return $result;
    }


    public function delete(string $table): int
    {
        $this->lastTableUsed = $table;
        $modifiers = $this->queryOptionsFlags ? implode(' ', $this->queryOptionsFlags) . ' ' : '';
        $sql = "DELETE {$modifiers}FROM {$this->prefix}$table";
        $sql .= $this->buildWhere();

        $this->lastQuery = $sql;
        $this->logTrace($sql);

        $stmt = $this->pdo->prepare($sql);
        foreach ($this->params as $p => $v) {
            $stmt->bindValue($p, $v);
        }

        $stmt->execute();
        $result = $stmt->rowCount();
        $this->resetQueryState();
        return $result;
    }

    /* ---------------- RAW ---------------- */

    public function rawQuery(string $query, array $params = []): array
    {
        $this->lastQuery = $query;
        $this->logTrace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchAll();
        $this->resetQueryState();
        return $result;
    }

    public function rawQueryOne(string $query, array $params = []): array
    {
        $this->lastQuery = $query;
        $this->logTrace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch() ?: [];
        $this->resetQueryState();
        return $result;
    }

    public function rawQueryValue(string $query, array $params = []): mixed
    {
        $this->lastQuery = $query;
        $this->logTrace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();
        $this->resetQueryState();
        return $result;
    }

    /* ---------------- SELECT ---------------- */
    protected function buildSelect(string $table, array $columns, ?int $limit = null, ?int $offset = null): string
    {
        $tailQueryOptions = [];
        $queryOptions = $this->queryOptionsFlags;

        foreach ($queryOptions as $index => $queryOption) {
            if (in_array($queryOption, ['LOCK IN SHARE MODE', 'FOR UPDATE'])) {
                $tailQueryOptions[] = $queryOption;
                unset($queryOptions[$index]);
            }
        }

        if (in_array('FOR UPDATE', $tailQueryOptions) && in_array('LOCK IN SHARE MODE', $tailQueryOptions)) {
            throw new InvalidArgumentException('Cannot use FOR UPDATE and LOCK IN SHARE MODE together');
        }

        $modifiers = $queryOptions ? implode(' ', $queryOptions) . ' ' : '';
        $endingModifiers = $tailQueryOptions ? ' ' . implode(' ', $tailQueryOptions) : '';

        $sql = "SELECT {$modifiers}" . implode(', ', $columns) . " FROM {$this->prefix}$table";

        if ($this->join) {
            $sql .= ' ' . implode(' ', $this->join);
        }
        $sql .= $this->buildWhere();

        if ($this->groupBy) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($this->having) {
            $sql .= $this->buildHaving();
        }
        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        if ($limit !== null) {
            $sql .= $offset !== null ? " LIMIT $offset, $limit" : " LIMIT $limit";
        }

        $sql .= $endingModifiers;

        return $sql;
    }


    /**
     * Execute a SELECT query.
     *
     * @param string $table
     * @param array $columns
     * @param ?int $limit
     * @param ?int $offset
     * @return array
     */
    protected function runSelect(string $table, array $columns, ?int $limit = null, ?int $offset = null): array
    {
        $sql = $this->buildSelect($table, $columns, $limit, $offset);

        $this->lastQuery = $sql;
        $this->logTrace($sql);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);

        $mode = $this->queryOptions['fetchMode'] ?? PDO::FETCH_ASSOC;
        return $stmt->fetchAll($mode);
    }


    public function get(string $table, ?int $limit = null, array $columns = ['*']): array|string
    {
        $this->lastTableUsed = $table;
        $rows = $this->runSelect($table, $columns, $limit);

        $json = $this->queryOptions['json'] ?? false;

        if (!$this->isSubQuery) {
            $this->resetQueryState();
        }

        return $json ? json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $rows;
    }

    public function getOne(string $table, array $columns = ['*']): array
    {
        $result = $this->get($table, 1, $columns);
        return $result[0] ?? [];
    }

    public function getValue(string $table, string $column, ?int $limit = null): mixed
    {
        $result = $this->get($table, $limit ?? 1, [$column]);
        if ($limit === 1 || $limit === null) {
            return $result[0][$column] ?? null;
        }
        return array_column($result, $column);
    }

    public function has(string $table): bool
    {
        $sql = "SELECT EXISTS(SELECT 1 FROM {$this->prefix}$table";
        $sql .= $this->buildWhere();
        $sql .= ') as exist';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        return (bool)$stmt->fetchColumn();
    }

    /* ---------------- CONDITIONS ---------------- */
    protected function addCondition(
        string $type,       // 'where' or 'having'
        ?string $expr,
        mixed $value,
        string $operator,
        string $cond
    ): void {
        $operator = strtoupper($operator);
        $prop = ($type === 'having') ? 'having' : 'where';

        if ($value instanceof self) {
            $subSql = (string)$value;
            $subParams = $value->getParams();
            foreach ($subParams as $k => $v) {
                if (array_key_exists($k, $this->params)) {
                    $newKey = $k . '_sq' . count($this->params);
                    $subSql = str_replace($k, $newKey, $subSql);
                    $this->params[$newKey] = $v;
                } else {
                    $this->params[$k] = $v;
                }
            }

            $this->{$prop}[] = [
                'cond' => $cond,
                'sql' => "$expr $operator ($subSql)"
            ];
            return;
        }

        // Validate expression type to prevent "Array" in SQL
        $isExistsOp = ($operator === 'EXISTS' || $operator === 'NOT EXISTS');
        if (!$isExistsOp) {
            if (!is_string($expr) || $expr === '') {
                throw new InvalidArgumentException("Expression for $type must be a non-empty string");
            }
        }

        switch ($operator) {
            case 'EXISTS':
            case 'NOT EXISTS':
                // value is raw subquery string
                $subSql = (string)$value;
                $this->{$prop}[] = ['cond' => $cond, 'sql' => "$operator ($subSql)"];
                break;

            case 'IN':
            case 'NOT IN':
                if (is_array($value)) {
                    if (empty($value)) {
                        // Deterministic behavior for empty IN list
                        $sql = ($operator === 'IN') ? '1=0' : '1=1';
                        $this->{$prop}[] = ['cond' => $cond, 'sql' => $sql];
                        break;
                    }
                    $ph = [];
                    foreach ($value as $i => $v) {
                        $p = $this->makeParam($expr, "_$i");
                        $this->params[$p] = $v;
                        $ph[] = $p;
                    }
                    $this->{$prop}[] = ['cond' => $cond, 'sql' => "$expr $operator (" . implode(', ', $ph) . ")"];
                } else {
                    // treat string as subquery: expr IN (SELECT ...)
                    $this->{$prop}[] = ['cond' => $cond, 'sql' => "$expr $operator ($value)"];
                }
                break;

            case 'BETWEEN':
            case 'NOT BETWEEN':
                if (!is_array($value) || count($value) !== 2) {
                    throw new InvalidArgumentException("$operator expects an array of two bounds");
                }
                [$from, $to] = $value;
                $p1 = $this->makeParam($expr, '_from');
                $p2 = $this->makeParam($expr, '_to');
                $this->params[$p1] = $from;
                $this->params[$p2] = $to;
                $this->{$prop}[] = ['cond' => $cond, 'sql' => "$expr $operator $p1 AND $p2"];
                break;

            default:
                if ($expr !== null) {
                    if (is_string($value) && str_starts_with(trim($value), 'SELECT')) {
                        $this->{$prop}[] = [
                            'cond' => $cond,
                            'sql' => "$expr $operator ($value)"
                        ];
                    } else {
                        $p = $this->makeParam($expr);

                        $this->params[$p] = $value;

                        $this->{$prop}[] = [
                            'cond' => $cond,
                            'sql' => "$expr $operator $p"
                        ];
                    }
                }
                break;

        }
    }


    protected function makeParam(string $expr, string $suffix = ''): string
    {
        // sanitize expression to safe identifier
        $base = preg_replace('/\W/', '_', $expr);
        return ':' . strtolower($base) . $suffix . count($this->params);
    }


    protected function buildConditions(array $items, string $keyword): string
    {
        if (empty($items)) {
            return '';
        }

        $clauses = [];
        foreach ($items as $i => $w) {
            if (is_string($w)) {
                $clauses[] = ($i === 0 ? '' : 'AND ') . $w;
                continue;
            }
            $sql = $w['sql'] ?? '';
            $cond = $w['cond'] ?? ($i === 0 ? '' : 'AND');
            if ($sql === '') {
                continue;
            }
            $clauses[] = ($i === 0 || $cond === '') ? $sql : strtoupper($cond) . ' ' . $sql;
        }

        return ' ' . $keyword . ' ' . implode(' ', $clauses);
    }

    protected function buildWhere(): string
    {
        return $this->buildConditions($this->where, 'WHERE');
    }

    protected function buildHaving(): string
    {
        return $this->buildConditions($this->having, 'HAVING');
    }


    public function where(?string $expr, mixed $value, string $operator = '='): self
    {
        $this->addCondition('where', $expr, $value, $operator, 'AND');
        return $this;
    }

    public function orWhere(?string $expr, mixed $value, string $operator = '='): self
    {
        $this->addCondition('where', $expr, $value, $operator, 'OR');
        return $this;
    }

    public function having(string $expr, mixed $value = null, string $operator = '='): self
    {
        $this->addCondition('having', $expr, $value, $operator, 'AND');
        return $this;
    }

    public function orHaving(string $expr, mixed $value = null, string $operator = '='): self
    {
        $this->addCondition('having', $expr, $value, $operator, 'OR');
        return $this;
    }


    // join(): keep given table string as-is (with alias if user passes "users u")
    public function join(string $table, string $condition, string $type = 'INNER'): self
    {
        $this->join[] = strtoupper($type) . " JOIN {$this->prefix}$table ON $condition";
        return $this;
    }

    public function joinWhere(string $table, string $column, mixed $value, string $operator = '='): self
    {
        $this->buildJoinCondition('AND', $table, $column, $value, $operator);
        return $this;
    }

    public function joinOrWhere(string $table, string $column, mixed $value, string $operator = '='): self
    {
        $this->buildJoinCondition('OR', $table, $column, $value, $operator);
        return $this;
    }

    protected function buildJoinCondition(
        string $cond,
        string $table,
        string $column,
        mixed $value,
        string $operator
    ): void {
        // alias
        if (str_contains($table, ' ')) {
            $parts = preg_split('/\s+/', trim($table));
            $table = end($parts);
        }

        if (is_array($value) && strtoupper($operator) === 'IN') {
            $placeholders = [];
            foreach ($value as $i => $v) {
                $ph = ':' . $table . '_' . str_replace('.', '_', $column) . "_{$i}" . count($this->params);
                $placeholders[] = $ph;
                $this->params[$ph] = $v;
            }
            $sql = "$table.$column IN (" . implode(', ', $placeholders) . ")";
        } else {
            $param = ':' . $table . '_' . str_replace('.', '_', $column) . count($this->params);
            $this->params[$param] = $value;
            $sql = "$table.$column $operator $param";
        }

        $this->where[] = ['cond' => $cond, 'sql' => $sql];
    }


    public function groupBy(string $column): self
    {
        $this->groupBy[] = $column;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = "$column " . strtoupper($direction);
        return $this;
    }

    /* ---------------- SUBQUERY ---------------- */

    public function subQuery(string $alias = ''): self
    {
        $sub = clone $this;
        $sub->isSubQuery = true;
        $sub->resetQueryState();
        if ($alias) {
            $sub->queryOptions['alias'] = $alias;
        }
        return $sub;
    }

    protected function resetQueryState(): void
    {
        $this->queryOptions = [];
        $this->where = [];
        $this->join = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = [];
        $this->params = [];
        $this->onDuplicateColumns = [];
        $this->queryOptionsFlags = [];
    }


    /* ---------------- PAGINATION ---------------- */

    public function withTotalCount(): self
    {
        $this->queryOptions['calcFoundRows'] = true;
        return $this;
    }

    public function paginate(string $table, int $page, array $columns = ['*']): array|string
    {
        $this->lastTableUsed = $table;

        $limit = $this->queryOptions['limit'] ?? 20;
        $this->queryOptions['limit'] = $limit;
        $offset = max(0, ($page - 1)) * $limit;

        // run main query
        $rows = $this->runSelect($table, $columns, $limit, $offset);

        // count total
        if (!empty($this->queryOptions['calcFoundRows'])) {
            $countStmt = $this->pdo->query("SELECT FOUND_ROWS()");
            $this->totalCount = (int)$countStmt->fetchColumn();
        } else {
            $countSql = "SELECT COUNT(*) FROM {$this->prefix}$table" . $this->buildWhere();
            $countStmt = $this->pdo->prepare($countSql);
            $countStmt->execute($this->params);
            $this->totalCount = (int)$countStmt->fetchColumn();
        }

        $json = $this->queryOptions['json'] ?? false;
        $this->resetQueryState();

        return $json ? json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $rows;
    }


    public function totalCount(): int
    {
        return $this->totalCount;
    }

    /* ---------------- TRANSACTIONS ---------------- */

    public function startTransaction(): void
    {
        if (!$this->transactionInProgress) {
            $this->pdo->beginTransaction();
            $this->transactionInProgress = true;
        }
    }

    public function commit(): void
    {
        if ($this->transactionInProgress) {
            $this->pdo->commit();
            $this->transactionInProgress = false;
        }
    }

    public function rollback(): void
    {
        if ($this->transactionInProgress) {
            $this->pdo->rollBack();
            $this->transactionInProgress = false;
        }
    }


    /* ---------------- LOCKING ---------------- */

    public function lock(string|array $tables): bool
    {
        if (!is_array($tables)) {
            $tables = [$tables];
        }

        $parts = [];
        foreach ($tables as $table) {
            $parts[] = "{$this->prefix}{$table} {$this->lockMethod}";
        }

        $sql = "LOCK TABLES " . implode(', ', $parts);

        $this->logTrace($sql);
        $this->lastQuery = $sql;

        return $this->pdo->exec($sql) === 0;
    }


    public function unlock(): bool
    {
        $sql = "UNLOCK TABLES";
        $this->logTrace($sql);
        $this->lastQuery = $sql;
        return $this->pdo->exec($sql) === 0;
    }


    public function setLockMethod(string $method): self
    {
        $this->lockMethod = strtoupper($method);
        return $this;
    }

    /* ---------------- HELPERS ---------------- */
    public function getParams(): array
    {
        return $this->params;
    }

    public function escape(string $str): string
    {
        return substr($this->pdo->quote($str), 1, -1);
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function tableExists(string $table): bool
    {
        $like = $this->pdo->quote($this->prefix . $table);
        $stmt = $this->pdo->query("SHOW TABLES LIKE $like");
        return (bool)$stmt->fetchColumn();
    }

    /* ---------------- TRACE ---------------- */


    public function setTrace(bool $enabled = true): void
    {
        $this->traceEnabled = $enabled;
    }

    protected function logTrace(string $sql, array $params = []): void
    {
        if ($this->traceEnabled) {
            $this->traceLog[] = [
                'query' => $sql,
                'params' => $params,
                'time' => microtime(true),
            ];
        }
    }

    public function trace(): array
    {
        return $this->traceLog;
    }


    public function getLastQuery(): string
    {
        return $this->lastQuery;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getLastErrno(): int
    {
        return $this->lastErrno;
    }

    public function getLogTrace(): array
    {
        return $this->traceLog;
    }

    /* ---------------- UTILS ---------------- */

    public function setQueryOption(string|array $flags): self
    {
        $this->queryOptionsFlags = is_array($flags) ? $flags : [$flags];
        return $this;
    }

    public function getQueryOptions(): array
    {
        return $this->queryOptionsFlags;
    }

    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function func(string $expr, array $bindParams = []): string
    {
        foreach ($bindParams as $k => $v) {
            $param = ":func_" . $k . count($this->params);
            $this->params[$param] = $v;
            $expr = str_replace('?', $param, $expr, $count);
            if ($count === 0) {
                break;
            }
        }
        return $expr;
    }

    public function now(string $diff = '', string $func = 'NOW()'): string
    {
        return $diff ? "$func + INTERVAL $diff" : $func;
    }

    public function inc(int|float $num = 1): array
    {
        return ['__op' => 'inc', 'val' => $num];
    }

    public function dec(int|float $num = 1): array
    {
        return ['__op' => 'dec', 'val' => $num];
    }

    public function not(mixed $val): array
    {
        return ['__op' => 'not', 'val' => $val];
    }

    public function map(string $idField): self
    {
        $this->queryOptions['mapKey'] = $idField;
        return $this;
    }

    public function copy(): self
    {
        return clone $this;
    }

    /* ---------------- CONNECTIONS ---------------- */
    protected function buildDsn(array $params): string
    {
        $driver = $params['driver'] ?? 'mysql';
        $charset = $params['charset'] ?? 'utf8mb4';

        return match ($driver) {
            'mysql' => "mysql:host={$params['host']};dbname={$params['db']}" . ($charset ? ";charset=" . $charset : ''),
            'pgsql' => "pgsql:host={$params['host']};dbname={$params['db']}",
            'sqlite' => "sqlite:" . ($params['path'] ?? ''),
            default => throw new InvalidArgumentException("Unsupported driver: $driver"),
        };
    }


    public function addConnection(string $name, array $params): void
    {
        $dsn = $this->buildDsn($params);

        $pdo = $params['pdo'] ?? null;

        if (!$pdo) {
            $pdo = new PDO($dsn, $params['username'], $params['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_LOCAL_INFILE => true
            ]);
        }
        $this->connections[$name] = $pdo;

        if ($name === 'default') {
            $this->pdo = $pdo;
        }
    }

    public function connection(string $name): self
    {
        if (!isset($this->connections[$name])) {
            throw new RuntimeException("Connection $name not found");
        }
        $clone = clone $this;
        $clone->pdo = $this->connections[$name];
        return $clone;
    }

    /* ---------------- BUILDERS ---------------- */

    // builders
    public function arrayBuilder(): self
    {
        $this->queryOptions['fetchMode'] = PDO::FETCH_ASSOC;
        return $this;
    }

    public function objectBuilder(): self
    {
        $this->queryOptions['fetchMode'] = PDO::FETCH_OBJ;
        return $this;
    }

    public function jsonBuilder(): self
    {
        // mark json output flag instead of passing a string mode to fetchAll
        $this->queryOptions['json'] = true;
        $this->queryOptions['fetchMode'] = PDO::FETCH_ASSOC; // base mode for json
        return $this;
    }

    /* ---------------- LOAD DATA/XML ---------------- */

    public function loadData(string $table, string $filePath, array $options = []): bool
    {

        $defaults = [
            "fieldChar" => ';',
            'fieldEnclosure' => null,
            'fields' => [],
            "lineChar" => null,
            "linesToIgnore" => null,
            'lineStarting' => null,
            'local' => false,
        ];

        $options = $options ? array_merge($defaults, $options) : $defaults;

        $localPrefix = $options['local'] ? 'LOCAL ' : '';

        $quotedPath = $this->pdo->quote($filePath);
        $sql = "LOAD DATA {$localPrefix}INFILE {$quotedPath} INTO TABLE {$this->prefix}$table";

        // FIELDS
        $sql .= sprintf(' FIELDS TERMINATED BY \'%s\'', $options["fieldChar"]);
        if ($options['fields']) {
            $sql .= ' (' . implode(', ', $options['fields']) . ')';
        }
        if ($options["fieldEnclosure"]) {
            $sql .= sprintf(' ENCLOSED BY \'%s\'', $options["fieldEnclosure"]);
        }

        // LINES
        if ($options['lineChar']) {
            $sql .= sprintf(' LINES TERMINATED BY \'%s\'', $options["lineChar"]);
        }
        if ($options["lineStarting"]) {
            $sql .= sprintf(' STARTING BY \'%s\'', $options["lineStarting"]);
        }

        // IGNORE LINES
        if ($options["linesToIgnore"]) {
            $sql .= sprintf(' IGNORE %d LINES', $options["linesToIgnore"]);
        }

        $this->lastQuery = $sql;
        $this->logTrace($sql);

        return $this->pdo->exec($sql) !== false;
    }


    public function loadXml(string $table, string $filePath, string $rowTag = '<row>'): bool
    {
        $sql = "LOAD XML LOCAL INFILE " . $this->pdo->quote($filePath) .
            " INTO TABLE {$this->prefix}$table " .
            "ROWS IDENTIFIED BY " . $this->pdo->quote($rowTag);

        return $this->pdo->exec($sql) !== false;
    }

    public function __toString(): string
    {
        return $this->lastQuery;
    }

}
