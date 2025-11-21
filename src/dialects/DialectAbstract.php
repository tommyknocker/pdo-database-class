<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\loaders\FileLoader;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;

abstract class DialectAbstract implements DialectInterface
{
    /** @var PDO PDO instance */
    protected ?PDO $pdo = null;

    protected ?FileLoader $fileLoader = null;

    public function setPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
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
            $this->fileLoader = new FileLoader($this->pdo, $this);
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
     * Quote identifier (column/table name).
     *
     * @param string $name
     *
     * @return string
     */
    abstract public function quoteIdentifier(string $name): string;

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
     * Case-insensitive LIKE expression.
     *
     * @param string $column
     * @param string $pattern
     *
     * @return RawValue
     */
    public function ilike(string $column, string $pattern): RawValue
    {
        return new RawValue("LOWER($column) LIKE LOWER(:pattern)", ['pattern' => $pattern]);
    }

    /**
     * SET/PRAGMA statement syntax.
     * e.g. SET FOREIGN_KEY_CHECKS = 1 OR SET NAMES 'utf8mb4' in MySQL and PostgreSQL or PRAGMA statements in SQLite.
     *
     * @param ConfigValue $value
     *
     * @return RawValue
     */
    public function config(ConfigValue $value): RawValue
    {
        $sql = 'SET ' . strtoupper($value->getValue())
            . ($value->getUseEqualSign() ? ' = ' : ' ')
            . ($value->getQuoteValue() ? '\'' . $value->getParams()[0] . '\'' : $value->getParams()[0]);
        return new RawValue($sql);
    }

    /**
     * Concatenate values.
     *
     * @param ConcatValue $value
     *
     * @return RawValue
     */
    public function concat(ConcatValue $value): RawValue
    {
        $parts = $value->getValues();
        $dialect = $this->getDriverName();

        $mapped = [];
        foreach ($parts as $part) {
            if ($part instanceof RawValue) {
                $mapped[] = $part->getValue();
                continue;
            }

            if (is_numeric($part)) {
                $mapped[] = (string)$part;
                continue;
            }

            $s = (string)$part;

            // already quoted literal?
            if (preg_match("/^'.*'\$/s", $s) || preg_match('/^".*"\$/s', $s)) {
                $mapped[] = $s;
                continue;
            }

            // Check if it's a simple string literal:
            // - Contains spaces OR special chars like : ; ! ? etc. (but not SQL operators)
            // - Does NOT contain SQL operators or parentheses
            // - Does NOT look like SQL keywords
            if (
                preg_match('/[\s:;!?@#\$&]/', $s) &&
                !preg_match('/[()%<>=+*\/]|SELECT|FROM|WHERE|AND|OR|JOIN|NULL|TRUE|FALSE/i', $s)
            ) {
                // Quote as string literal
                $mapped[] = "'" . str_replace("'", "''", $s) . "'";
                continue;
            }

            // contains parentheses, math/comparison operators — treat as raw SQL
            if (preg_match('/[()%<>=]/', $s)) {
                $mapped[] = $s;
                continue;
            }

            // simple identifier (maybe schema.table or table.column) — quote each part
            $pieces = explode('.', $s);
            foreach ($pieces as &$p) {
                match ($dialect) {
                    'pgsql', 'sqlite' => $p = '"' . str_replace('"', '""', $p) . '"',
                    default => $p = '`' . str_replace('`', '``', $p) . '`'
                };
            }
            $mapped[] = implode('.', $pieces);
        }

        // Use dialect-specific concatenation formatting
        $sql = $this->formatConcatExpression($mapped);

        return new RawValue($sql);
    }

    /**
     * Build SQL for loading data from XML file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadXML(string $table, string $filePath, array $options = []): string
    {
        return $this->getFileLoader()->loadFromXml($table, $filePath, $options);
    }

    /**
     * Build SQL generator for loading data from XML file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadXMLGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        return $this->getFileLoader()->loadFromXmlGenerator($table, $filePath, $options);
    }

    /**
     * Build SQL for loading data from CSV file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadCsvSql(string $table, string $filePath, array $options = []): string
    {
        return $this->getFileLoader()->loadFromCsv($table, $filePath, $options);
    }

    /**
     * Build SQL generator for loading data from CSV file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        return $this->getFileLoader()->loadFromCsvGenerator($table, $filePath, $options);
    }

    /**
     * Build SQL for loading data from JSON file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadJson(string $table, string $filePath, array $options = []): string
    {
        return $this->getFileLoader()->loadFromJson($table, $filePath, $options);
    }

    /**
     * Build SQL generator for loading data from JSON file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadJsonGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        return $this->getFileLoader()->loadFromJsonGenerator($table, $filePath, $options);
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
            $path = trim($path);
            if ($path === '' || $path === '$') {
                return [];
            }
            // allow dot notation like "a.b[0].c" or simple keys separated by dots
            return explode('.', $path);
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
            return "'{$default}'";
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
     * Format LEAST expression (default implementation)
     * Override in specific dialects if needed (e.g., SQLite uses MIN).
     *
     * @param array<int, string|int|float|RawValue> $values
     *
     * @return string
     */
    public function formatLeast(array $values): string
    {
        $args = $this->resolveValues($values);
        return 'LEAST(' . implode(', ', $args) . ')';
    }

    /**
     * Format MOD expression (default implementation)
     * Override in specific dialects if needed (e.g., SQLite uses %).
     *
     * @param string|RawValue $dividend
     * @param string|RawValue $divisor
     *
     * @return string
     */
    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string
    {
        $d1 = $this->resolveValue($dividend);
        $d2 = $this->resolveValue($divisor);
        return "MOD($d1, $d2)";
    }

    /**
     * Format interval expression (default implementation).
     * Should be overridden in specific dialects.
     *
     * @param string|RawValue $expr
     * @param string $value
     * @param string $unit
     * @param bool $isAdd
     *
     * @return string
     */
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        // Default implementation - should be overridden
        $e = $this->resolveValue($expr);
        $sign = $isAdd ? '+' : '-';
        return "$e $sign INTERVAL $value $unit";
    }

    /**
     * Format GROUP_CONCAT expression (default implementation).
     * Should be overridden in specific dialects.
     *
     * @param string|RawValue $column
     * @param string $separator
     * @param bool $distinct
     *
     * @return string
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = addslashes($separator);
        $dist = $distinct ? 'DISTINCT ' : '';
        return "GROUP_CONCAT($dist$col SEPARATOR '$sep')";
    }

    /**
     * Format TRUNCATE expression (default implementation).
     * Should be overridden in specific dialects.
     *
     * @param string|RawValue $value
     * @param int $precision
     *
     * @return string
     */
    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        $val = $this->resolveValue($value);
        return "TRUNCATE($val, $precision)";
    }

    /**
     * Format POSITION expression (default implementation).
     * Should be overridden in specific dialects.
     *
     * @param string|RawValue $substring
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : "'" . addslashes((string)$substring) . "'";
        $val = $this->resolveValue($value);
        return "POSITION($sub IN $val)";
    }

    /**
     * Format LEFT expression (default implementation).
     *
     * @param string|RawValue $value
     * @param int $length
     *
     * @return string
     */
    public function formatLeft(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "LEFT($val, $length)";
    }

    /**
     * Format RIGHT expression (default implementation).
     *
     * @param string|RawValue $value
     * @param int $length
     *
     * @return string
     */
    public function formatRight(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "RIGHT($val, $length)";
    }

    /**
     * Format REPEAT expression (default implementation).
     *
     * @param string|RawValue $value
     * @param int $count
     *
     * @return string
     */
    public function formatRepeat(string|RawValue $value, int $count): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : "'" . addslashes((string)$value) . "'";
        return "REPEAT($val, $count)";
    }

    /**
     * Format REVERSE expression (default implementation).
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatReverse(string|RawValue $value): string
    {
        $val = $this->resolveValue($value);
        return "REVERSE($val)";
    }

    /**
     * Format LPAD/RPAD expression (default implementation).
     *
     * @param string|RawValue $value
     * @param int $length
     * @param string $padString
     * @param bool $isLeft
     *
     * @return string
     */
    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string
    {
        $val = $this->resolveValue($value);
        $pad = addslashes($padString);
        $func = $isLeft ? 'LPAD' : 'RPAD';
        return "{$func}($val, $length, '$pad')";
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
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
        // Default implementation: append LIMIT and OFFSET
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        if ($offset !== null) {
            $sql .= ' OFFSET ' . (int)$offset;
        }
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplain(\PDO $pdo, string $sql, array $params = []): array
    {
        // Default implementation: build explain SQL and execute it
        $explainSql = $this->buildExplainSql($sql);
        $stmt = $pdo->prepare($explainSql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string
    {
        // Default implementation: return as-is
        return $selectSql;
    }

    /**
     * {@inheritDoc}
     */
    public function needsUnionParentheses(): bool
    {
        // Default implementation: no parentheses needed
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function formatLateralJoin(string $tableSql, string $type, string $aliasQuoted, string|RawValue|null $condition = null): string
    {
        // Standard LATERAL JOIN syntax for PostgreSQL, MySQL, etc.
        $typeUpper = strtoupper(trim($type));
        $isCross = ($typeUpper === 'CROSS' || $typeUpper === 'CROSS JOIN');

        if ($condition !== null) {
            $onSql = $condition instanceof RawValue ? $condition->getValue() : (string)$condition;
            if ($isCross) {
                return "CROSS JOIN {$tableSql} ON {$onSql}";
            }
            return "{$typeUpper} JOIN {$tableSql} ON {$onSql}";
        }

        // For CROSS JOIN LATERAL, no ON clause needed
        if ($isCross) {
            return "CROSS JOIN {$tableSql}";
        }

        // For LEFT/INNER JOIN LATERAL, MySQL may require explicit ON condition
        // PostgreSQL supports LATERAL without ON, but for compatibility add ON true
        return "{$typeUpper} JOIN {$tableSql} ON true";
    }

    /**
     * {@inheritDoc}
     */
    public function needsColumnQualificationInUpdateSet(): bool
    {
        // Default: most dialects need column qualification when JOIN is used
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplainAnalyze(\PDO $pdo, string $sql, array $params = []): array
    {
        // Default implementation: execute the explain analyze SQL directly
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanType(): array
    {
        // Default: TINYINT(1) for MySQL/MariaDB/SQLite
        return ['type' => 'TINYINT', 'length' => 1];
    }

    /**
     * {@inheritDoc}
     */
    public function getTimestampType(): string
    {
        // Default: DATETIME for MySQL/MariaDB/SQLite
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function getDatetimeType(): string
    {
        // Default: DATETIME for MySQL/MariaDB/SQLite
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function isNoFieldsError(\PDOException $e): bool
    {
        // Default: no special handling for "no fields" errors
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function appendLimitOffset(string $sql, int $limit, int $offset): string
    {
        // Standard LIMIT/OFFSET syntax for MySQL, PostgreSQL, SQLite
        return $sql . " LIMIT {$limit} OFFSET {$offset}";
    }

    /**
     * {@inheritDoc}
     */
    public function getPrimaryKeyType(): string
    {
        // Default: INT for MySQL/MariaDB/MSSQL/SQLite
        return 'INT';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigPrimaryKeyType(): string
    {
        // Default: BIGINT for MySQL/MariaDB/MSSQL/SQLite
        return 'BIGINT';
    }

    /**
     * {@inheritDoc}
     */
    public function getStringType(): string
    {
        // Default: VARCHAR for MySQL/MariaDB/MSSQL/PostgreSQL
        return 'VARCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        // Default: TEXT for MySQL/MariaDB/PostgreSQL/SQLite
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getCharType(): string
    {
        // Default: CHAR for MySQL/MariaDB/PostgreSQL
        return 'CHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        // Default: no materialization support
        return $cteSql;
    }

    /**
     * {@inheritDoc}
     */
    public function registerRegexpFunctions(\PDO $pdo, bool $force = false): void
    {
        // Default: no REGEXP function registration needed
        // Dialects that need REGEXP support (SQLite, MSSQL) override this method
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeDefaultValue(string $value): string
    {
        // Default: return DEFAULT as-is (most dialects support DEFAULT keyword)
        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        // Default implementation for MySQL/MariaDB
        $tableQuoted = $this->quoteTable($tableName);
        return "CREATE TABLE {$tableQuoted} (
            version VARCHAR(255) PRIMARY KEY,
            apply_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            batch INTEGER NOT NULL
        ) ENGINE=InnoDB";
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationInsertSql(string $tableName, string $version, int $batch): array
    {
        // Default: use positional parameters (?, ?)
        $tableQuoted = $this->quoteTable($tableName);
        return [
            "INSERT INTO {$tableQuoted} (version, batch) VALUES (?, ?)",
            [$version, $batch],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function extractErrorCode(\PDOException $e): string
    {
        // Default: use exception code
        return (string) $e->getCode();
    }

    /**
     * {@inheritDoc}
     */
    public function getExplainParser(): ExplainParserInterface
    {
        throw new \RuntimeException(
            sprintf('EXPLAIN parser not implemented for %s dialect', $this->getDriverName())
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getRecursiveCteKeyword(): string
    {
        return 'WITH RECURSIVE';
    }

    /**
     * {@inheritDoc}
     */
    public function createDatabase(string $databaseName, \tommyknocker\pdodb\PdoDb $db): bool
    {
        $sql = $this->buildCreateDatabaseSql($databaseName);
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropDatabase(string $databaseName, \tommyknocker\pdodb\PdoDb $db): bool
    {
        $sql = $this->buildDropDatabaseSql($databaseName);
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function databaseExists(string $databaseName, \tommyknocker\pdodb\PdoDb $db): bool
    {
        $databases = $this->listDatabases($db);
        return in_array($databaseName, $databases, true);
    }

    /**
     * {@inheritDoc}
     */
    public function listDatabases(\tommyknocker\pdodb\PdoDb $db): array
    {
        $sql = $this->buildListDatabasesSql();
        $result = $db->rawQuery($sql);
        return $this->extractDatabaseNames($result);
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

    /**
     * {@inheritDoc}
     */
    public function getDatabaseInfo(\tommyknocker\pdodb\PdoDb $db): array
    {
        return [];
    }

    /* ---------------- User Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function createUser(string $username, string $password, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function userExists(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listUsers(\tommyknocker\pdodb\PdoDb $db): array
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(string $username, ?string $host, \tommyknocker\pdodb\PdoDb $db): array
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
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
        \tommyknocker\pdodb\PdoDb $db
    ): bool {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
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
        \tommyknocker\pdodb\PdoDb $db
    ): bool {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function changeUserPassword(string $username, string $newPassword, ?string $host, \tommyknocker\pdodb\PdoDb $db): bool
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function dumpSchema(\tommyknocker\pdodb\PdoDb $db, ?string $table = null, bool $dropTables = true): string
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'Database dump is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function dumpData(\tommyknocker\pdodb\PdoDb $db, ?string $table = null): string
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'Database dump is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function restoreFromSql(\tommyknocker\pdodb\PdoDb $db, string $sql, bool $continueOnError = false): void
    {
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
            'Database restore is not supported for ' . $this->getDriverName()
        );
    }

    /* ---------------- Monitoring ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getActiveQueries(\tommyknocker\pdodb\PdoDb $db): array
    {
        // Default implementation returns empty array
        // Dialects should override this method
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveConnections(\tommyknocker\pdodb\PdoDb $db): array
    {
        // Default implementation returns empty array
        // Dialects should override this method
        return [
            'connections' => [],
            'summary' => [
                'current' => 0,
                'max' => 0,
                'usage_percent' => 0,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getSlowQueries(\tommyknocker\pdodb\PdoDb $db, float $thresholdSeconds, int $limit): array
    {
        // Default implementation returns empty array
        // Dialects should override this method
        return [];
    }

    /* ---------------- Table Management ---------------- */

    /**
     * {@inheritDoc}
     */
    public function listTables(\tommyknocker\pdodb\PdoDb $db, ?string $schema = null): array
    {
        // Default implementation - dialects should override
        throw new \tommyknocker\pdodb\exceptions\ResourceException(
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

    /**
     * {@inheritDoc}
     */
    public function getErrorDescription(int|string $errorCode): string
    {
        // Default implementation
        // Dialects should override this method
        return 'Unknown error';
    }

    /* ---------------- DML Operations ---------------- */

    /**
     * {@inheritDoc}
     */
    public function getInsertSelectColumns(string $tableName, ?array $columns, \tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface $executionEngine): array
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

        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeConfigParams(array $config): array
    {
        // Default implementation - normalize 'database' to 'dbname' if needed
        if (isset($config['database']) && !isset($config['dbname'])) {
            $config['dbname'] = $config['database'];
        }
        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function formatConcatExpression(array $parts): string
    {
        // Default implementation uses CONCAT() function
        // SQLite dialect will override to use || operator
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }
}
