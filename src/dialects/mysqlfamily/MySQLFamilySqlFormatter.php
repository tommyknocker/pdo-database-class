<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysqlfamily;

use tommyknocker\pdodb\dialects\formatters\SqlFormatterAbstract;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * SQL formatting shared by MySQL and MariaDB.
 */
abstract class MySQLFamilySqlFormatter extends SqlFormatterAbstract
{
    /**
     * {@inheritDoc}
     */
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
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
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = $this->dialect->quoteStringLiteral($separator);
        $dist = $distinct ? 'DISTINCT ' : '';
        return "GROUP_CONCAT($dist$col SEPARATOR $sep)";
    }

    /* ---------------- JSON methods ---------------- */

    /**
     * The condition "<number at the path> <operator> <placeholder>"; NULL for a value that is not a JSON number.
     *
     * @param array<int, string|int>|string $path
     */
    public function formatJsonNumberComparison(string $col, array|string $path, string $operator, string $placeholder): string
    {
        $parts = $this->normalizeJsonPath($path);
        $extract = 'JSON_EXTRACT(' . $this->quoteIdentifier($col) . ", '" . $this->buildJsonPathString($parts) . "')";
        $text = "JSON_UNQUOTE({$extract})";

        // DECIMAL(65,30) keeps a 64-bit integer exact and a fraction down to 1e-30. A number outside 1e-30 .. 1e35 (and 0) would
        // be cut to 0 or to the largest DECIMAL, which matches the wrong rows or fails a strict-mode UPDATE: it is NULL here.
        return "CASE WHEN JSON_TYPE({$extract}) IN ('INTEGER', 'UNSIGNED INTEGER', 'DOUBLE', 'DECIMAL') "
            . "AND (CAST({$text} AS DOUBLE) = 0 OR ABS(CAST({$text} AS DOUBLE)) BETWEEN 1e-30 AND 1e35) "
            . "THEN CAST({$text} AS DECIMAL(65,30)) END {$operator} {$placeholder}";
    }

    /**
     * The condition "<boolean at the path> <operator> <placeholder>"; NULL for a value that is not a JSON boolean.
     *
     * @param array<int, string|int>|string $path
     */
    public function formatJsonBooleanComparison(string $col, array|string $path, string $operator, string $placeholder): string
    {
        $parts = $this->normalizeJsonPath($path);
        $extract = 'JSON_EXTRACT(' . $this->quoteIdentifier($col) . ", '" . $this->buildJsonPathString($parts) . "')";

        return "CASE WHEN JSON_TYPE({$extract}) = 'BOOLEAN' THEN JSON_UNQUOTE({$extract}) = 'true' END {$operator} {$placeholder}";
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
    public function formatJsonExists(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

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

    /* ---------------- SQL helpers ---------------- */

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
    public function formatGreatest(array $values): string
    {
        $args = $this->resolveValues($values);
        return 'GREATEST(' . implode(', ', $args) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeast(array $values): string
    {
        $args = $this->resolveValues($values);
        return 'LEAST(' . implode(', ', $args) . ')';
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
        $sub = $substring instanceof RawValue ? $substring->getValue() : $this->dialect->quoteStringLiteral((string)$substring);
        $val = $this->resolveValue($value);
        return "POSITION($sub IN $val)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeft(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "LEFT($val, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRight(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "RIGHT($val, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRepeat(string|RawValue $value, int $count): string
    {
        $val = $this->resolveValue($value);
        return "REPEAT($val, $count)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatReverse(string|RawValue $value): string
    {
        $val = $this->resolveValue($value);
        return "REVERSE($val)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string
    {
        $val = $this->resolveValue($value);
        $pad = $this->dialect->quoteStringLiteral($padString);
        $func = $isLeft ? 'LPAD' : 'RPAD';
        return "{$func}($val, $length, $pad)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpMatch(string|RawValue $value, string $pattern): string
    {
        $val = $this->resolveValue($value);
        // A backslash is an escape character in MySQL string literals, so it has to be quoted like any other value
        $pat = $this->dialect->quoteStringLiteral($pattern);
        return "($val REGEXP $pat)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpLike(string|RawValue $value, string $pattern): string
    {
        $val = $this->resolveValue($value);
        // A backslash is an escape character in MySQL string literals, so it has to be quoted like any other value
        $pat = $this->dialect->quoteStringLiteral($pattern);
        return "($val REGEXP $pat)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        $val = $this->resolveValue($value);
        $pat = $this->dialect->quoteStringLiteral($pattern);
        $rep = $this->dialect->quoteStringLiteral($replacement);
        return "REGEXP_REPLACE($val, $pat, $rep)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        $val = $this->resolveValue($value);
        $pat = $this->dialect->quoteStringLiteral($pattern);
        return "REGEXP_SUBSTR($val, $pat)";
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
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        if ($isMaterialized) {
            if (preg_match('/^\s*SELECT\s+/i', $cteSql)) {
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
     *
     * @param array<int|string, mixed> $options
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
    public function normalizeRawValue(string $sql): string
    {
        // MariaDB doesn't need special normalization
        return $sql;
    }
}
