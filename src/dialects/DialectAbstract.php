<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use SplFileObject;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use XMLReader;

abstract class DialectAbstract implements DialectInterface
{
    /** @var PDO PDO instance */
    protected ?PDO $pdo = null;

    public function setPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
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
        if ($this->pdo === null) {
            throw new RuntimeException('PDO instance not set. Call setPdo() first.');
        }
        $pdo = $this->pdo;
        $defaults = [
            'rowTag' => '<row>',
            'linesToIgnore' => 0,
        ];
        $opts = $options + $defaults;

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("XML file is not readable: {$filePath}");
        }

        $rowTag = trim((string)$opts['rowTag'], " \t\n\r\0\x0B<>");
        $skipRows = max(0, (int)$opts['linesToIgnore']);

        if (!$reader = XMLReader::open($filePath)) {
            throw new RuntimeException("Unable to open XML file: {$filePath}");
        }

        $quoteValue = static function ($v) use ($pdo) {
            if ($v === null) {
                return 'NULL';
            }

            // keep empty string as quoted empty string
            return $pdo->quote((string)$v);
        };

        $quoteIdent = static function (string $ident) {
            $parts = explode('.', $ident);

            $parts = array_map(
                static function ($p) {
                    $p = trim($p);
                    return preg_match('/^[A-Za-z0-9_]+$/', $p) ? "\"{$p}\"" : $p;
                },
                $parts
            );

            return implode('.', $parts);
        };

        $tableQ = $quoteIdent($table);
        $columns = [];
        $batch = [];
        $batchesSql = [];
        $batchSize = 500;
        $rowsProcessed = 0;
        $skipped = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName !== $rowTag) {
                continue;
            }

            // Skip first N logical row elements if requested
            if ($skipped < $skipRows) {
                $skipped++;
                $reader->next();
                continue;
            }

            $xml = $reader->readOuterXml();
            if ($xml === '') {
                $reader->next();
                continue;
            }

            $elem = simplexml_load_string($xml);
            if ($elem === false) {
                $reader->next();
                continue;
            }

            // Determine columns from the first encountered row
            if ($columns === []) {
                foreach ($elem->children() as $child) {
                    $columns[] = (string)$child->getName();
                }

                // fallback to attributes if no child elements
                if ($columns === []) {
                    foreach ($elem->attributes() as $name => $val) {
                        $columns[] = (string)$name;
                    }
                }

                if ($columns === []) {
                    $reader->close();
                    return '';
                }
            }

            $values = [];
            foreach ($columns as $col) {
                $val = null;

                if (isset($elem->{$col}) && (string)$elem->{$col} !== '') {
                    $val = (string)$elem->{$col};
                } elseif ($elem->attributes()->{$col} !== null) {
                    $val = (string)$elem->attributes()->{$col};
                }

                $values[] = $quoteValue($val);
            }

            $batch[] = '(' . implode(', ', $values) . ')';
            $rowsProcessed++;

            if (count($batch) >= $batchSize) {
                $colsEscaped = array_map(
                    static function ($c) {
                        return preg_match('/^[A-Za-z0-9_]+$/', $c) ? "\"{$c}\"" : $c;
                    },
                    $columns
                );

                $batchesSql[] = 'INSERT INTO ' . $tableQ . ' (' . implode(', ', $colsEscaped) . ')'
                    . ' VALUES ' . implode(', ', $batch) . ';';
                $batch = [];
            }

            $reader->next();
        }

        $reader->close();

        if ($batch !== []) {
            $colsEscaped = array_map(
                static function ($c) {
                    return preg_match('/^[A-Za-z0-9_]+$/', $c) ? "\"{$c}\"" : $c;
                },
                $columns
            );

            $batchesSql[] = 'INSERT INTO ' . $tableQ . ' (' . implode(', ', $colsEscaped) . ')'
                . ' VALUES ' . implode(', ', $batch) . ';';
        }

        if ($rowsProcessed === 0) {
            return '';
        }

        return implode("\n", $batchesSql);
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
        if ($this->pdo === null) {
            throw new RuntimeException('PDO instance not set. Call setPdo() first.');
        }
        $pdo = $this->pdo;
        $defaults = [
            'fieldChar' => ',',
            'fieldEnclosure' => null,
            'fields' => [],
            'lineChar' => null,
            'linesToIgnore' => null,
            'lineStarting' => null,
        ];
        $opts = $options + $defaults;

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("CSV file is not readable: {$filePath}");
        }

        $delimiter = (string)$opts['fieldChar'];
        $enclosure = $opts['fieldEnclosure'] ?? '"';
        $escape = '\\';

        $file = new SplFileObject($filePath, 'r');
        $file->setFlags(SplFileObject::READ_AHEAD);

        // Skip physical lines before processing begins
        if (!empty($opts['linesToIgnore'])) {
            $skip = (int)$opts['linesToIgnore'];
            for ($i = 0; $i < $skip && !$file->eof(); $i++) {
                $file->fgets();
            }
        }

        // Determine columns: either from options['fields'] or from the first non-empty CSV row
        $columns = $opts['fields'];
        if (empty($columns)) {
            while (!$file->eof()) {
                $row = $file->fgetcsv($delimiter, $enclosure, $escape);
                if ($row === false || $row === null) {
                    continue;
                }
                $isBlank = count($row) === 1 && ($row[0] === null || $row[0] === '');
                if ($isBlank) {
                    continue;
                }
                $columns = array_map(fn ($v) => trim((string)($v ?? '')), $row);
                break;
            }
        }

        if (empty($columns)) {
            throw new RuntimeException('No CSV header detected and no fields provided.');
        }

        // If a specific logical line (1-based) is requested, seek to it
        if (!empty($opts['lineStarting']) && (int)$opts['lineStarting'] > 1) {
            $target = (int)$opts['lineStarting'];
            $file->rewind();
            for ($line = 1; $line < $target && !$file->eof(); $line++) {
                $file->fgets();
            }
        }

        $quote = static function ($v) use ($pdo) {
            if ($v === null) {
                return 'NULL';
            }
            return $pdo->quote((string)$v);
        };

        $quoteIdent = static function (string $ident) {
            $parts = explode('.', $ident);
            $parts = array_map(static function ($p) {
                $p = trim($p);
                return preg_match('/^[A-Za-z0-9_]+$/', $p) ? "\"{$p}\"" : $p;
            }, $parts);
            return implode('.', $parts);
        };

        $tableQ = $quoteIdent($table);
        $colsQ = array_map(static function ($c) {
            return preg_match('/^[A-Za-z0-9_]+$/', $c) ? "\"{$c}\"" : $c;
        }, $columns);

        $batchSize = 500;
        $batch = [];
        $sqlParts = [];
        $rows = 0;

        // Read and build batches
        while (!$file->eof()) {
            $row = $file->fgetcsv($delimiter, $enclosure, $escape);
            if ($row === false || $row === null) {
                continue;
            }
            $isBlank = count($row) === 1 && ($row[0] === null || $row[0] === '');
            if ($isBlank) {
                continue;
            }

            // Normalize row length to match column count
            $cells = array_map(static function ($c) {
                return $c === null ? null : trim((string)$c);
            }, $row);

            if (count($cells) < count($columns)) {
                $cells = array_merge($cells, array_fill(0, count($columns) - count($cells), null));
            } elseif (count($cells) > count($columns)) {
                $cells = array_slice($cells, 0, count($columns));
            }

            // Skip completely empty rows
            $allEmpty = true;
            foreach ($cells as $c) {
                if ($c !== null && $c !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }

            $vals = [];
            foreach ($cells as $v) {
                $vals[] = $quote($v);
            }

            $batch[] = '(' . implode(', ', $vals) . ')';
            $rows++;

            if (count($batch) >= $batchSize) {
                $sqlParts[] = 'INSERT INTO ' . $tableQ . ' (' . implode(', ', $colsQ) . ')'
                    . ' VALUES ' . implode(', ', $batch) . ';';
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $sqlParts[] = 'INSERT INTO ' . $tableQ . ' (' . implode(', ', $colsQ) . ')'
                . ' VALUES ' . implode(', ', $batch) . ';';
        }

        if ($rows === 0) {
            return '';
        }

        return implode("\n", $sqlParts);
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
