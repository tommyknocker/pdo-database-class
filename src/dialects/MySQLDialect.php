<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\traits\JsonPathBuilderTrait;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;

class MySQLDialect extends DialectAbstract
{
    use JsonPathBuilderTrait;
    use UpsertBuilderTrait;
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
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $vals = implode(', ', $placeholders);
        $prefix = 'INSERT' . ($options ? ' ' . implode(' ', $options) : '') . ' INTO';
        return sprintf('%s %s (%s) VALUES (%s)', $prefix, $table, $cols, $vals);
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
        if (!$updateColumns) {
            return '';
        }

        $isAssoc = $this->isAssociativeArray($updateColumns);
        
        if ($isAssoc) {
            $updates = $this->buildUpsertExpressions($updateColumns, $tableName);
        } else {
            $updates = [];
            foreach ($updateColumns as $col) {
                $qid = $this->quoteIdentifier($col);
                $updates[] = "{$qid} = VALUES({$qid})";
            }
        }

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    /**
     * {@inheritDoc}
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        $op = $expr['__op'];
        return match ($op) {
            'inc' => "{$colSql} = {$colSql} + " . (int)$expr['val'],
            'dec' => "{$colSql} = {$colSql} - " . (int)$expr['val'],
            default => "{$colSql} = VALUES({$colSql})",
        };
    }

    /**
     * {@inheritDoc}
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        return "{$colSql} = {$expr->getValue()}";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        if ($expr === true) {
            return "{$colSql} = VALUES({$colSql})";
        }
        if (is_string($expr)) {
            return "{$colSql} = {$expr}";
        }
        return "{$colSql} = VALUES({$colSql})";
    }

    /**
     * {@inheritDoc}
     */
    /**
     * @param array<int, string> $columns
     * @param array<int, string|array<int, string>> $placeholders
     */
    public function buildReplaceSql(
        string $table,
        array $columns,
        array $placeholders,
        bool $isMultiple = false
    ): string {
        $tableSql = $this->quoteTable($table);
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));

        if ($isMultiple) {
            // placeholders already contain grouped row expressions like "(...),(...)" or ["(...)", "(...)"]
            $valsSql = implode(',', array_map(function ($p) {
                return is_array($p) ? '(' . implode(',', $p) . ')' : $p;
            }, $placeholders));
            return sprintf('REPLACE INTO %s (%s) VALUES %s', $tableSql, $colsSql, $valsSql);
        }

        // Single row: placeholders are scalar fragments matching columns
        $stringPlaceholders = array_map(fn ($p) => is_array($p) ? implode(',', $p) : $p, $placeholders);
        $valsSql = implode(',', $stringPlaceholders);
        return sprintf('REPLACE INTO %s (%s) VALUES (%s)', $tableSql, $colsSql, $valsSql);
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
        if (preg_match('/^([+-]?)(\d+)\s+([A-Za-z]+)$/', $trimmedDif, $matches)) {
            $sign = $matches[1] === '-' ? '-' : '+';
            $value = $matches[2];
            $unit = strtoupper($matches[3]);

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
    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        $expr = 'JSON_EXTRACT(' . $this->quoteIdentifier($col) . ", '" . $jsonPath . "')";
        if ($asText) {
            return 'JSON_UNQUOTE(' . $expr . ')';
        }
        return $expr;
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string
    {
        $jsonPath = $path === null ? '$' : $this->buildJsonPathString($this->normalizeJsonPath($path));
        $colQuoted = $this->quoteIdentifier($col);
        $param = $this->generateParameterName('jsonc', $col . '|' . $jsonPath);
        $jsonText = $this->encodeToJson($value);

        $sql = "JSON_CONTAINS({$colQuoted}, {$param}, '{$jsonPath}')";
        return [$sql, [$param => $jsonText]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        $colQuoted = $this->quoteIdentifier($col);
        $param = $this->generateParameterName('jsonset', $col . '|' . $jsonPath);

        $jsonText = $this->encodeToJson($value);

        // If nested path, ensure parent exists as object before setting child
        if (count($parts) > 1) {
            $parentParts = $this->getParentPathParts($parts);
            $parentPath = $this->buildJsonPathString($parentParts);
            $lastKey = $this->getLastSegment($parts);

            // Build expression: set the parent to JSON_SET(COALESCE(JSON_EXTRACT(col,parent), {}) , '$.<last>', CAST(:param AS JSON))
            // then write that parent back into the document
            $parentNew = "JSON_SET(COALESCE(JSON_EXTRACT({$colQuoted}, '{$parentPath}'), CAST('{}' AS JSON)), '$.{$lastKey}', CAST({$param} AS JSON))";
            $sql = "JSON_SET({$colQuoted}, '{$parentPath}', {$parentNew})";
        } else {
            // simple single-segment path
            $sql = "JSON_SET({$colQuoted}, '{$jsonPath}', CAST({$param} AS JSON))";
        }

        return [$sql, [$param => $jsonText]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        // If last segment is numeric — replace that array slot with JSON null to preserve array length semantics
        $last = $this->getLastSegment($parts);
        if ($this->isNumericIndex($last)) {
            // JSON_SET(column, '$.path[index]', CAST('null' AS JSON))
            return "JSON_SET({$colQuoted}, '{$jsonPath}', CAST('null' AS JSON))";
        }

        // otherwise remove object key
        return "JSON_REMOVE({$colQuoted}, '{$jsonPath}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonExists(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        // Combined check to cover multiple edge cases:
        // 1) JSON_CONTAINS_PATH detects presence even when value is JSON null
        // 2) JSON_SEARCH finds a scalar/string value inside arrays/objects
        // 3) JSON_EXTRACT(...) IS NOT NULL picks up non-NULL extraction results
        // Any one true -> path considered present
        return '('
            . "JSON_CONTAINS_PATH({$colQuoted}, 'one', '{$jsonPath}')"
            . " OR JSON_SEARCH({$colQuoted}, 'one', JSON_UNQUOTE(JSON_EXTRACT({$colQuoted}, '{$jsonPath}'))) IS NOT NULL"
            . " OR JSON_EXTRACT({$colQuoted}, '{$jsonPath}') IS NOT NULL"
            . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        $colQuoted = $this->quoteIdentifier($col);
        $base = "JSON_EXTRACT({$colQuoted}, '{$jsonPath}')";
        $unquoted = "JSON_UNQUOTE({$base})";

        // Force numeric coercion for ordering: numeric-like strings and numeric JSON become numbers.
        // This returns an expression suitable for ORDER BY ... ASC/DESC.
        return "({$unquoted} + 0)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            return "JSON_LENGTH({$colQuoted})";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        return "JSON_LENGTH({$colQuoted}, '{$jsonPath}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            return "JSON_KEYS({$colQuoted})";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        return "JSON_KEYS({$colQuoted}, '{$jsonPath}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonType(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            return "JSON_TYPE({$colQuoted})";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        return "JSON_TYPE(JSON_EXTRACT({$colQuoted}, '{$jsonPath}'))";
    }

    /**
     * {@inheritDoc}
     */
    public function formatIfNull(string $expr, mixed $default): string
    {
        return "IFNULL($expr, {$this->formatDefaultValue($default)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string
    {
        $src = $this->resolveValue($source);
        if ($length === null) {
            return "SUBSTRING($src, $start)";
        }
        return "SUBSTRING($src, $start, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurDate(): string
    {
        return 'CURDATE()';
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurTime(): string
    {
        return 'CURTIME()';
    }

    /**
     * {@inheritDoc}
     */
    public function formatYear(string|RawValue $value): string
    {
        return "YEAR({$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMonth(string|RawValue $value): string
    {
        return "MONTH({$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDay(string|RawValue $value): string
    {
        return "DAY({$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatHour(string|RawValue $value): string
    {
        return "HOUR({$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMinute(string|RawValue $value): string
    {
        return "MINUTE({$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSecond(string|RawValue $value): string
    {
        return "SECOND({$this->resolveValue($value)})";
    }

}
