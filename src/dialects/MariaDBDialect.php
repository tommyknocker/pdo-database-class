<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\mariadb\MariaDBFeatureSupport;
use tommyknocker\pdodb\dialects\traits\JsonPathBuilderTrait;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class MariaDBDialect extends DialectAbstract
{
    use JsonPathBuilderTrait;
    use UpsertBuilderTrait;

    /** @var MariaDBFeatureSupport Feature support instance */
    private MariaDBFeatureSupport $featureSupport;

    public function __construct()
    {
        $this->featureSupport = new MariaDBFeatureSupport();
    }
    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'mariadb';
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
        $sql = "UPDATE {$options}{$table}";
        if (!empty($joins)) {
            $sql .= ' ' . implode(' ', $joins);
        }
        $sql .= " SET {$setClause}";
        $sql .= $whereClause;
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        return $sql;
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
        // MariaDB syntax: DELETE table FROM table JOIN ...
        $sql = "DELETE {$options}{$table} FROM {$table}";
        if (!empty($joins)) {
            $sql .= ' ' . implode(' ', $joins);
        }
        $sql .= $whereClause;
        return $sql;
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
     */
    public function buildInsertSelectSql(
        string $table,
        array $columns,
        string $selectSql,
        array $options = []
    ): string {
        $prefix = 'INSERT' . ($options ? ' ' . implode(' ', $options) : '') . ' INTO';
        $colsSql = '';
        if (!empty($columns)) {
            $colsSql = ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')';
        }
        return sprintf('%s %s%s %s', $prefix, $table, $colsSql, $selectSql);
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
        // MariaDB emulation using INSERT ... SELECT ... ON DUPLICATE KEY UPDATE
        // Extract key from ON clause for conflict target
        preg_match('/target\.(\w+)\s*=\s*source\.(\w+)/i', $onClause, $matches);
        $keyColumn = $matches[1] ?? 'id';

        $target = $this->quoteTable($targetTable);

        if (empty($whenClauses['whenNotMatched'])) {
            throw new RuntimeException('MariaDB MERGE requires WHEN NOT MATCHED clause');
        }

        // Parse INSERT clause
        preg_match('/\(([^)]+)\)\s+VALUES\s+\(([^)]+)\)/', $whenClauses['whenNotMatched'], $insertMatches);
        $columns = $insertMatches[1] ?? '';
        $values = $insertMatches[2] ?? '';

        // Build SELECT columns from VALUES - replace MERGE_SOURCE_COLUMN_ markers with source columns
        $selectColumns = [];
        $valueColumns = explode(',', $values);
        $colNames = explode(',', $columns);
        foreach ($valueColumns as $idx => $val) {
            $val = trim($val);
            $colName = trim($colNames[$idx] ?? '');
            // Remove quotes from column name if present
            $colName = trim($colName, '`"');
            // If it's a MERGE_SOURCE_COLUMN_ marker, use source column; otherwise it's raw SQL
            if (preg_match('/^MERGE_SOURCE_COLUMN_(\w+)$/', $val, $paramMatch)) {
                $selectColumns[] = 'source.' . $this->quoteIdentifier($colName);
            } else {
                $selectColumns[] = $val;
            }
        }

        $sql = "INSERT INTO {$target} ({$columns})\n";
        $sql .= 'SELECT ' . implode(', ', $selectColumns) . " FROM {$sourceSql} AS source\n";

        // Add ON DUPLICATE KEY UPDATE if whenMatched exists
        if (!empty($whenClauses['whenMatched'])) {
            // Replace source.column references with VALUES(column) for MariaDB
            $updateExpr = preg_replace('/source\.([a-zA-Z_][a-zA-Z0-9_]*)/', 'VALUES($1)', $whenClauses['whenMatched']);
            $sql .= "ON DUPLICATE KEY UPDATE {$updateExpr}\n";
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $expr
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        if (!isset($expr['__op']) || !isset($expr['val'])) {
            return "{$colSql} = VALUES({$colSql})";
        }

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
        // MariaDB 8.0+ supports EXPLAIN ANALYZE with JSON format
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
        // MariaDB uses native LOAD DATA INFILE which loads entire file at once
        yield $this->buildLoadCsvSql($table, $filePath, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadXMLGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        // MariaDB uses native LOAD XML LOCAL INFILE which loads entire file at once
        yield $this->buildLoadXML($table, $filePath, $options);
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

        // MariaDB's JSON_SET doesn't support CAST(:param AS JSON) directly in the third argument.
        // For nested paths, ensure parent exists as object before setting child (similar to MySQL approach).
        // But without CAST, we need to handle it differently.
        if (count($parts) > 1) {
            $lastSegment = $this->getLastSegment($parts);
            $isLastNumeric = $this->isNumericIndex($lastSegment);

            if ($isLastNumeric) {
                // For array indices, use direct path
                $sql = "JSON_SET({$colQuoted}, '{$jsonPath}', {$param})";
            } else {
                // For object keys, ensure parent exists
                $parentParts = $this->getParentPathParts($parts);
                $parentPath = $this->buildJsonPathString($parentParts);
                $lastKey = (string)$lastSegment;

                // Build expression: set the parent to JSON_SET(COALESCE(JSON_EXTRACT(col,parent), '{}') , '$.<last>', :param)
                // then write that parent back into the document
                // Note: Without CAST, MariaDB may store the value as a string, but JSON_SET should handle it
                $parentNew = "JSON_SET(COALESCE(JSON_EXTRACT({$colQuoted}, '{$parentPath}'), '{}'), '$.{$lastKey}', {$param})";
                $sql = "JSON_SET({$colQuoted}, '{$parentPath}', {$parentNew})";
            }
        } else {
            // simple single-segment path
            $sql = "JSON_SET({$colQuoted}, '{$jsonPath}', {$param})";
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
            // MariaDB doesn't support CAST('null' AS JSON) in JSON_SET
            // For array indices, we still use JSON_REMOVE to remove the element
            // This is a limitation - we can't preserve array length with null in MariaDB
            return "JSON_REMOVE({$colQuoted}, '{$jsonPath}')";
        }

        // otherwise remove object key
        return "JSON_REMOVE({$colQuoted}, '{$jsonPath}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonReplace(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        $colQuoted = $this->quoteIdentifier($col);
        $param = $this->generateParameterName('jsonreplace', $col . '|' . $jsonPath);

        $jsonText = $this->encodeToJson($value);

        // MariaDB's JSON_REPLACE doesn't support CAST(:param AS JSON) directly in the third argument.
        // Pass the JSON string directly - MariaDB's JSON_REPLACE will parse it correctly.
        // Note: This works correctly for objects and arrays, but strings will be stored as JSON strings (quoted).
        $sql = "JSON_REPLACE({$colQuoted}, '{$jsonPath}', {$param})";

        return [$sql, [$param => $jsonText]];
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
        // 4) JSON_TYPE(...) IS NOT NULL ensures the path exists and has a valid JSON type
        // Any one true -> path considered present
        return '('
            . "JSON_CONTAINS_PATH({$colQuoted}, 'one', '{$jsonPath}')"
            . " OR JSON_SEARCH({$colQuoted}, 'one', JSON_UNQUOTE(JSON_EXTRACT({$colQuoted}, '{$jsonPath}'))) IS NOT NULL"
            . " OR JSON_EXTRACT({$colQuoted}, '{$jsonPath}') IS NOT NULL"
            . " OR JSON_TYPE(JSON_EXTRACT({$colQuoted}, '{$jsonPath}')) IS NOT NULL"
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

    /**
     * {@inheritDoc}
     */
    public function formatDateOnly(string|RawValue $value): string
    {
        return 'DATE(' . $this->resolveValue($value) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatTimeOnly(string|RawValue $value): string
    {
        return 'TIME(' . $this->resolveValue($value) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        $e = $this->resolveValue($expr);
        $func = $isAdd ? 'DATE_ADD' : 'DATE_SUB';
        return "{$func}({$e}, INTERVAL {$value} {$unit})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = addslashes($separator);
        $dist = $distinct ? 'DISTINCT ' : '';
        return "GROUP_CONCAT($dist$col SEPARATOR '$sep')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        $val = $this->resolveValue($value);
        return "TRUNCATE($val, $precision)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : "'" . addslashes((string)$substring) . "'";
        $val = $this->resolveValue($value);
        return "POSITION($sub IN $val)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpMatch(string|RawValue $value, string $pattern): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        return "($val REGEXP '$pat')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        $rep = str_replace("'", "''", $replacement);
        return "REGEXP_REPLACE($val, '$pat', '$rep')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        // MariaDB REGEXP_SUBSTR syntax: REGEXP_SUBSTR(expr, pattern[, pos[, occurrence[, match_type[, subexpr]]]])
        // Note: subexpr parameter (6th parameter) is available in MariaDB 10.0.5+
        // For compatibility, we only support full match extraction
        // Capture group extraction requires MariaDB 10.0.5+ with subexpr parameter
        // For now, we return the full match regardless of groupIndex
        return "REGEXP_SUBSTR($val, '$pat')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $quotedCols = array_map([$this, 'quoteIdentifier'], $cols);
        $colList = implode(', ', $quotedCols);

        $modeClause = '';
        if ($mode !== null) {
            $validModes = ['natural', 'boolean', 'natural language', 'boolean'];
            if (!in_array(strtolower($mode), $validModes, true)) {
                $mode = 'natural';
            }
            $modeClause = ' IN ' . strtoupper($mode) . ' MODE';
        }

        $expansionClause = $withQueryExpansion ? ' WITH QUERY EXPANSION' : '';

        $ph = ':fulltext_search_term';
        $sql = "MATCH ($colList) AGAINST ($ph$modeClause$expansionClause)";

        return [$sql, [$ph => $searchTerm]];
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
        // MariaDB doesn't support default value parameter in LAG/LEAD functions
        // For LAG/LEAD with 3 arguments, use COALESCE wrapper instead
        $functionUpper = strtoupper($function);
        $defaultValue = null;

        if (($functionUpper === 'LAG' || $functionUpper === 'LEAD') && count($args) === 3) {
            // Extract default value and remove it from args
            $defaultValue = array_pop($args);
        }

        $sql = $functionUpper . '(';

        // Add function arguments
        if (!empty($args)) {
            $formattedArgs = array_map(function ($arg) {
                if (is_string($arg) && !is_numeric($arg)) {
                    return $this->quoteIdentifier($arg);
                }
                if (is_null($arg)) {
                    return 'NULL';
                }
                return (string)$arg;
            }, $args);
            $sql .= implode(', ', $formattedArgs);
        }

        $sql .= ') OVER (';

        // Add PARTITION BY
        if (!empty($partitionBy)) {
            $quotedPartitions = array_map(
                fn ($col) => $this->quoteIdentifier($col),
                $partitionBy
            );
            $sql .= 'PARTITION BY ' . implode(', ', $quotedPartitions);
        }

        // Add ORDER BY
        if (!empty($orderBy)) {
            if (!empty($partitionBy)) {
                $sql .= ' ';
            }
            $orderClauses = [];
            foreach ($orderBy as $order) {
                foreach ($order as $col => $dir) {
                    $orderClauses[] = $this->quoteIdentifier($col) . ' ' . strtoupper($dir);
                }
            }
            $sql .= 'ORDER BY ' . implode(', ', $orderClauses);
        }

        // Add frame clause
        if ($frameClause !== null) {
            $sql .= ' ' . $frameClause;
        }

        $sql .= ')';

        // Wrap in COALESCE if default value was provided for LAG/LEAD
        if ($defaultValue !== null) {
            $defaultFormatted = is_numeric($defaultValue) ? (string)$defaultValue : "'" . addslashes((string)$defaultValue) . "'";
            $sql = "COALESCE({$sql}, {$defaultFormatted})";
        }

        return $sql;
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
        $tableQuoted = $this->quoteTable($table);
        $columnDefs = [];
        $primaryKeyColumn = null;

        foreach ($columns as $name => $def) {
            if ($def instanceof ColumnSchema) {
                $columnDefs[] = $this->formatColumnDefinition($name, $def);
                // If column has AUTO_INCREMENT, it must be PRIMARY KEY in MariaDB
                if ($def->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            } elseif (is_array($def)) {
                // Short syntax: ['type' => 'VARCHAR(255)', 'null' => false]
                $schema = $this->parseColumnDefinition($def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
                // If column has AUTO_INCREMENT, it must be PRIMARY KEY in MariaDB
                if ($schema->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            } else {
                // String type: 'VARCHAR(255)'
                $schema = new ColumnSchema((string)$def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
                // If column has AUTO_INCREMENT, it must be PRIMARY KEY in MariaDB
                if ($schema->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            }
        }

        // Add PRIMARY KEY if AUTO_INCREMENT column exists
        if ($primaryKeyColumn !== null) {
            $columnDefs[] = 'PRIMARY KEY (' . $this->quoteIdentifier($primaryKeyColumn) . ')';
        }

        // Add PRIMARY KEY constraint from options if specified
        if (!empty($options['primaryKey'])) {
            $pkColumns = is_array($options['primaryKey']) ? $options['primaryKey'] : [$options['primaryKey']];
            $pkQuoted = array_map([$this, 'quoteIdentifier'], $pkColumns);
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
        }

        $sql = "CREATE TABLE {$tableQuoted} (\n    " . implode(",\n    ", $columnDefs) . "\n)";

        // Add table options
        if (!empty($options['engine'])) {
            $sql .= ' ENGINE=' . $options['engine'];
        }
        if (!empty($options['charset'])) {
            $sql .= ' DEFAULT CHARSET=' . $options['charset'];
        }
        if (!empty($options['collate'])) {
            $sql .= ' COLLATE=' . $options['collate'];
        }
        if (isset($options['comment'])) {
            $comment = addslashes((string)$options['comment']);
            $sql .= " COMMENT='{$comment}'";
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableSql(string $table): string
    {
        return 'DROP TABLE ' . $this->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableIfExistsSql(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnDef = $this->formatColumnDefinition($column, $schema);
        $sql = "ALTER TABLE {$tableQuoted} ADD COLUMN {$columnDef}";

        if ($schema->isFirst()) {
            $sql .= ' FIRST';
        } elseif ($schema->getAfter() !== null) {
            $sql .= ' AFTER ' . $this->quoteIdentifier($schema->getAfter());
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropColumnSql(string $table, string $column): string
    {
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);
        return "ALTER TABLE {$tableQuoted} DROP COLUMN {$columnQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAlterColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnDef = $this->formatColumnDefinition($column, $schema);
        return "ALTER TABLE {$tableQuoted} MODIFY COLUMN {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $oldQuoted = $this->quoteIdentifier($oldName);
        $newQuoted = $this->quoteIdentifier($newName);
        // MariaDB 10.5.2+ supports RENAME COLUMN
        // For older versions, this will fail and user should use alterColumn instead
        return "ALTER TABLE {$tableQuoted} RENAME COLUMN {$oldQuoted} TO {$newQuoted}";
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
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';

        // Process columns with sorting support and RawValue support (functional indexes)
        $colsQuoted = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                // Array format: ['column' => 'ASC'/'DESC'] - associative array with column name as key
                foreach ($col as $colName => $direction) {
                    // $colName is always string (array key), $direction is the sort direction
                    $dir = strtoupper((string)$direction) === 'DESC' ? 'DESC' : 'ASC';
                    $colsQuoted[] = $this->quoteIdentifier((string)$colName) . ' ' . $dir;
                }
            } elseif ($col instanceof \tommyknocker\pdodb\helpers\values\RawValue) {
                // RawValue expression (functional index)
                $colsQuoted[] = $col->getValue();
            } else {
                $colsQuoted[] = $this->quoteIdentifier((string)$col);
            }
        }
        $colsList = implode(', ', $colsQuoted);

        $sql = "CREATE {$type} {$nameQuoted} ON {$tableQuoted} ({$colsList})";

        // MariaDB doesn't support WHERE clause in CREATE INDEX
        // MariaDB doesn't support INCLUDE columns
        // Add options if provided
        if (!empty($options['using'])) {
            $sql .= ' USING ' . strtoupper($options['using']);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted} ON {$tableQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        $parserClause = $parser !== null ? " WITH PARSER {$parser}" : '';
        return "CREATE FULLTEXT INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList}){$parserClause}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE SPATIAL INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameIndexSql(string $oldName, string $table, string $newName): string
    {
        // MariaDB doesn't support RENAME INDEX directly, use ALTER TABLE ... RENAME INDEX
        $tableQuoted = $this->quoteTable($table);
        $oldQuoted = $this->quoteIdentifier($oldName);
        $newQuoted = $this->quoteIdentifier($newName);
        return "ALTER TABLE {$tableQuoted} RENAME INDEX {$oldQuoted} TO {$newQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameForeignKeySql(string $oldName, string $table, string $newName): string
    {
        // MariaDB doesn't support RENAME FOREIGN KEY directly
        throw new \RuntimeException(
            'MariaDB does not support renaming foreign keys directly. ' .
            'You must drop the foreign key and create a new one with the desired name.'
        );
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
        $tableQuoted = $this->quoteTable($table);
        $refTableQuoted = $this->quoteTable($refTable);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $refColsQuoted = array_map([$this, 'quoteIdentifier'], $refColumns);
        $colsList = implode(', ', $colsQuoted);
        $refColsList = implode(', ', $refColsQuoted);

        $sql = "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted}";
        $sql .= " FOREIGN KEY ({$colsList}) REFERENCES {$refTableQuoted} ({$refColsList})";

        if ($delete !== null) {
            $sql .= ' ON DELETE ' . strtoupper($delete);
        }
        if ($update !== null) {
            $sql .= ' ON UPDATE ' . strtoupper($update);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropForeignKeySql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP FOREIGN KEY {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddPrimaryKeySql(string $name, string $table, array $columns): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} PRIMARY KEY ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropPrimaryKeySql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        // MariaDB doesn't require constraint name for DROP PRIMARY KEY
        return "ALTER TABLE {$tableQuoted} DROP PRIMARY KEY";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddUniqueSql(string $name, string $table, array $columns): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} UNIQUE ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropUniqueSql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP INDEX {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddCheckSql(string $name, string $table, string $expression): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        // MariaDB 10.2.1+ supports CHECK constraints
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} CHECK ({$expression})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropCheckSql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CHECK {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameTableSql(string $table, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $newQuoted = $this->quoteTable($newName);
        return "RENAME TABLE {$tableQuoted} TO {$newQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string
    {
        $nameQuoted = $this->quoteIdentifier($name);
        $type = $schema->getType();

        // MariaDB/MySQL don't support DEFAULT for TEXT/BLOB columns
        // Convert TEXT to VARCHAR(255) if DEFAULT is specified (Yii2-style behavior)
        $length = $schema->getLength();
        if (($type === 'TEXT' || $type === 'BLOB' || $type === 'LONGTEXT' || $type === 'MEDIUMTEXT' || $type === 'TINYTEXT')
            && $schema->getDefaultValue() !== null) {
            $type = 'VARCHAR';
            // Use default length for VARCHAR if not specified
            if ($length === null) {
                $length = 255;
            }
        }

        // Build type with length/scale
        $typeDef = $type;
        if ($length !== null) {
            if ($schema->getScale() !== null) {
                $typeDef .= '(' . $length . ',' . $schema->getScale() . ')';
            } else {
                $typeDef .= '(' . $length . ')';
            }
        }

        // UNSIGNED (MySQL/MariaDB only)
        if ($schema->isUnsigned()) {
            $typeDef .= ' UNSIGNED';
        }

        $parts = [$nameQuoted, $typeDef];

        // NOT NULL / NULL
        if ($schema->isNotNull()) {
            $parts[] = 'NOT NULL';
        }

        // AUTO_INCREMENT
        if ($schema->isAutoIncrement()) {
            $parts[] = 'AUTO_INCREMENT';
        }

        // DEFAULT
        if ($schema->getDefaultValue() !== null) {
            if ($schema->isDefaultExpression()) {
                $parts[] = 'DEFAULT ' . $schema->getDefaultValue();
            } else {
                $default = $this->formatDefaultValue($schema->getDefaultValue());
                $parts[] = 'DEFAULT ' . $default;
            }
        }

        // COMMENT
        if ($schema->getComment() !== null) {
            $comment = addslashes($schema->getComment());
            $parts[] = "COMMENT '{$comment}'";
        }

        // UNIQUE is handled separately (not in column definition for MySQL/MariaDB)
        // It's created via CREATE INDEX or table constraint

        return implode(' ', $parts);
    }

    /**
     * Parse column definition from array.
     *
     * @param array<string, mixed> $def Definition array
     *
     * @return ColumnSchema
     */
    protected function parseColumnDefinition(array $def): ColumnSchema
    {
        $type = $def['type'] ?? 'VARCHAR';
        $length = $def['length'] ?? $def['size'] ?? null;
        $scale = $def['scale'] ?? null;

        $schema = new ColumnSchema((string)$type, $length, $scale);

        if (isset($def['null']) && $def['null'] === false) {
            $schema->notNull();
        }
        if (isset($def['default'])) {
            if (isset($def['defaultExpression']) && $def['defaultExpression']) {
                $schema->defaultExpression((string)$def['default']);
            } else {
                $schema->defaultValue($def['default']);
            }
        }
        if (isset($def['comment'])) {
            $schema->comment((string)$def['comment']);
        }
        if (isset($def['unsigned']) && $def['unsigned']) {
            $schema->unsigned();
        }
        if (isset($def['autoIncrement']) && $def['autoIncrement']) {
            $schema->autoIncrement();
        }
        if (isset($def['unique']) && $def['unique']) {
            $schema->unique();
        }
        if (isset($def['after'])) {
            $schema->after((string)$def['after']);
        }
        if (isset($def['first']) && $def['first']) {
            $schema->first();
        }

        return $schema;
    }

    /**
     * Format default value for SQL.
     *
     * @param mixed $value Default value
     *
     * @return string SQL formatted value
     */
    protected function formatDefaultValue(mixed $value): string
    {
        if ($value instanceof RawValue) {
            return $value->getValue();
        }
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        return "'" . addslashes((string)$value) . "'";
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeRawValue(string $sql): string
    {
        // MariaDB doesn't need special normalization
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
        if ($isMaterialized) {
            // MariaDB: Use optimizer hint in the query (similar to MySQL)
            // Add a comment hint to encourage materialization
            if (preg_match('/^\s*SELECT\s+/i', $cteSql)) {
                // Add optimizer hint after SELECT
                return preg_replace(
                    '/^\s*(SELECT\s+)/i',
                    '$1/*+ MATERIALIZE */ ',
                    $cteSql
                ) ?? $cteSql;
            }
        }
        return $cteSql;
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
}
