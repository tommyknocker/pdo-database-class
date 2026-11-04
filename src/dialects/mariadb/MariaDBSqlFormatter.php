<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilySqlFormatter;

/**
 * MariaDB SQL formatter implementation.
 */
class MariaDBSqlFormatter extends MySQLFamilySqlFormatter
{
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
            $defaultFormatted = is_numeric($defaultValue) ? (string)$defaultValue : $this->dialect->quoteStringLiteral((string)$defaultValue);
            $sql = "COALESCE({$sql}, {$defaultFormatted})";
        }

        return $sql;
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

        // MariaDB has no CAST(:param AS JSON), and a plain text parameter is stored as a JSON string whatever it holds:
        // 999 becomes "999", {"x":1} a string of JSON text. JSON_COMPACT(:param) parses the text, so the value keeps its type.
        // For nested paths, ensure parent exists as object before setting child (similar to MySQL approach).
        if (count($parts) > 1) {
            $lastSegment = $this->getLastSegment($parts);
            $isLastNumeric = $this->isNumericIndex($lastSegment);

            if ($isLastNumeric) {
                // For array indices, use direct path
                $sql = "JSON_SET({$colQuoted}, '{$jsonPath}', JSON_COMPACT({$param}))";
            } else {
                // For object keys, ensure parent exists
                $parentParts = $this->getParentPathParts($parts);
                $parentPath = $this->buildJsonPathString($parentParts);
                $lastKey = (string)$lastSegment;
                $lastKeyPath = $this->buildJsonPathString([$lastKey]);

                // Build expression: set the parent to JSON_SET(COALESCE(JSON_EXTRACT(col,parent), '{}') , '$.<last>', :param)
                // then write that parent back into the document
                $parentNew = "JSON_SET(COALESCE(JSON_EXTRACT({$colQuoted}, '{$parentPath}'), '{}'), '{$lastKeyPath}', JSON_COMPACT({$param}))";
                $sql = "JSON_SET({$colQuoted}, '{$parentPath}', {$parentNew})";
            }
        } else {
            // simple single-segment path
            $sql = "JSON_SET({$colQuoted}, '{$jsonPath}', JSON_COMPACT({$param}))";
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

        // JSON_COMPACT(:param) parses the text, so the value keeps its type (see formatJsonSet).
        $sql = "JSON_REPLACE({$colQuoted}, '{$jsonPath}', JSON_COMPACT({$param}))";

        return [$sql, [$param => $jsonText]];
    }
}
