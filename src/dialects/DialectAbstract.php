<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\loaders\FileLoader;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;

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
            $this->fileLoader = new FileLoader($this->pdo);
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

        // choose concatenation style
        if ($dialect === 'sqlite') {
            $sql = implode(' || ', $mapped);
        } else {
            // default to CONCAT(); PostgreSQL and MySQL support CONCAT()
            // if only two parts and dialect explicitly sqlite was requested, we already handled above
            $sql = 'CONCAT(' . implode(', ', $mapped) . ')';
        }

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
}
