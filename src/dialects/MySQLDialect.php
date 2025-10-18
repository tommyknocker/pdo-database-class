<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\helpers\RawValue;

class MySQLDialect extends DialectAbstract implements DialectInterface
{
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
            PDO::MYSQL_ATTR_LOCAL_INFILE => true
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
        $quotedSegments = array_map(static fn($s) => '`' . str_replace('`', '``', $s) . '`', $segments);
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
            $sql = preg_replace('/^SELECT\s+/i', 'SELECT ' . implode(',', $middle) . ' ', $sql, 1);
        }
        if ($tail) {
            $sql .= ' ' . implode(' ', $tail);
        }
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id'): string
    {
        if (!$updateColumns) {
            return '';
        }

        $updates = [];

        foreach ($updateColumns as $key => $val) {
            if (is_int($key)) {
                $col = $val;
                $qid = $this->quoteIdentifier($col);
                $updates[] = "{$qid} = VALUES({$qid})";
            } else {
                $col = $key;
                $qid = $this->quoteIdentifier($col);

                if ($val instanceof RawValue) {
                    $updates[] = "{$qid} = {$val->getValue()}";
                } elseif ($val === true) {
                    $updates[] = "{$qid} = VALUES({$qid})";
                } elseif (is_string($val)) {
                    $updates[] = "{$qid} = {$val}";
                } else {
                    $updates[] = "{$qid} = VALUES({$qid})";
                }
            }
        }

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
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
        $tableSql = $this->quoteTable($table);
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));

        if ($isMultiple) {
            // placeholders already contain grouped row expressions like "(...),(...)" or ["(...)", "(...)"]
            $valsSql = implode(',', $placeholders);
            return sprintf('REPLACE INTO %s (%s) VALUES %s', $tableSql, $colsSql, $valsSql);
        }

        // Single row: placeholders are scalar fragments matching columns
        $valsSql = implode(',', $placeholders);
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
    public function buildExplainSql(string $query, bool $analyze = false): string
    {
        return "EXPLAIN " . $query;
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
    public function buildDescribeTableSql(string $table): string
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
        return "LOCK TABLES " . implode(', ', $parts);
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        return "UNLOCK TABLES";
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
            'linesToIgnore' => null
        ];
        $options = array_merge($defaults, $options);

        return "LOAD XML LOCAL INFILE " . $this->pdo->quote($filePath) .
            " INTO TABLE " . $this->quoteTableWithAlias($table) .
            " ROWS IDENTIFIED BY " . $this->pdo->quote($options['rowTag']) .
            ($options['linesToIgnore'] ? sprintf(' IGNORE %d LINES', $options['linesToIgnore']) : '');
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadCsvSql(string $table, string $filePath, array $options = []): string
    {
        $defaults = [
            "fieldChar" => ';',
            "fieldEnclosure" => null,
            "fields" => [],
            "lineChar" => null,
            "linesToIgnore" => null,
            "lineStarting" => null,
            "local" => false,
        ];
        $options = array_merge($defaults, $options);

        $localPrefix = $options['local'] ? 'LOCAL ' : '';
        $quotedPath = $this->pdo->quote($filePath);

        $sql = "LOAD DATA {$localPrefix}INFILE {$quotedPath} INTO TABLE {$table}";

        // FIELDS
        $sql .= sprintf(" FIELDS TERMINATED BY '%s'", $options["fieldChar"]);
        if ($options["fieldEnclosure"]) {
            $sql .= sprintf(" ENCLOSED BY '%s'", $options["fieldEnclosure"]);
        }

        // LINES
        if ($options['lineChar']) {
            $sql .= sprintf(" LINES TERMINATED BY '%s'", $options["lineChar"]);
        }
        if ($options["lineStarting"]) {
            $sql .= sprintf(" STARTING BY '%s'", $options["lineStarting"]);
        }

        // IGNORE LINES
        if ($options["linesToIgnore"]) {
            $sql .= sprintf(" IGNORE %d LINES", $options["linesToIgnore"]);
        }

        // FIELDS LIST (in the end!)
        if ($options['fields']) {
            $sql .= ' (' . implode(', ', $options['fields']) . ')';
        }

        return $sql;
    }

    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = '$' . (empty($parts) ? '' : ('.' . implode('.', array_map(function($p){
                    return preg_match('/^\d+$/', $p) ? "[$p]" : $p;
                }, $parts))));
        $expr = "JSON_EXTRACT(" . $this->quoteIdentifier($col) . ", '{$jsonPath}')";
        if ($asText) {
            return "JSON_UNQUOTE({$expr})";
        }
        return $expr;
    }

    public function formatJsonContains(string $col, mixed $value, array|string $path = null): array
    {
        $colQuoted = $this->quoteIdentifier($col);
        $param = ':jsonc';
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $pathSql = '';
        if ($path !== null) {
            $parts = $this->normalizeJsonPath($path);
            $jsonPath = '$' . (empty($parts) ? '' : ('.' . implode('.', array_map(function($p){
                        return preg_match('/^\d+$/', $p) ? "[$p]" : $p;
                    }, $parts))));
            $pathSql = ", '{$jsonPath}'";
        }
        $sql = "JSON_CONTAINS({$colQuoted}, {$param}{$pathSql})";
        return [$sql, [$param => $json]];
    }

    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = '$' . (empty($parts) ? '' : ('.' . implode('.', array_map(function($p){
                    return preg_match('/^\d+$/', $p) ? "[$p]" : $p;
                }, $parts))));
        $param = ':jsonset';
        $expr = "JSON_SET(" . $this->quoteIdentifier($col) . ", '{$jsonPath}', {$param})";
        return [$expr, [$param => json_encode($value, JSON_UNESCAPED_UNICODE)]];
    }

    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = '$' . (empty($parts) ? '' : ('.' . implode('.', array_map(function($p){
                    return preg_match('/^\d+$/', $p) ? "[$p]" : $p;
                }, $parts))));
        return "JSON_REMOVE(" . $this->quoteIdentifier($col) . ", '{$jsonPath}')";
    }

    public function formatJsonExists(string $col, array|string $path): string
    {
        $getExpr = $this->formatJsonGet($col, $path, false);
        // If JSON_EXTRACT returns NULL then path missing
        return "{$getExpr} IS NOT NULL";
    }

    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        // Return text extraction; consumer may cast as needed in SQL
        return $this->formatJsonGet($col, $path, true);
    }

}
