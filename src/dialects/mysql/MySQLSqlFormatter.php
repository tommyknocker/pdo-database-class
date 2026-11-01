<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilySqlFormatter;

/**
 * MySQL SQL formatter implementation.
 */
class MySQLSqlFormatter extends MySQLFamilySqlFormatter
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
        $sql = strtoupper($function) . '(';

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

        if (!empty($partitionBy)) {
            $quotedPartitions = array_map(
                fn ($col) => $this->quoteIdentifier($col),
                $partitionBy
            );
            $sql .= 'PARTITION BY ' . implode(', ', $quotedPartitions);
        }

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

        if ($frameClause !== null) {
            $sql .= ' ' . $frameClause;
        }

        $sql .= ')';

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

        if (count($parts) > 1) {
            $lastSegment = $this->getLastSegment($parts);
            $isLastNumeric = $this->isNumericIndex($lastSegment);

            if ($isLastNumeric) {
                $sql = "JSON_SET({$colQuoted}, '{$jsonPath}', CAST({$param} AS JSON))";
            } else {
                $parentParts = $this->getParentPathParts($parts);
                $parentPath = $this->buildJsonPathString($parentParts);
                $lastKey = (string)$lastSegment;

                $parentNew = "JSON_SET(COALESCE(JSON_EXTRACT({$colQuoted}, '{$parentPath}'), CAST('{}' AS JSON)), '$.{$lastKey}', CAST({$param} AS JSON))";
                $sql = "JSON_SET({$colQuoted}, '{$parentPath}', {$parentNew})";
            }
        } else {
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

        $last = $this->getLastSegment($parts);
        if ($this->isNumericIndex($last)) {
            return "JSON_SET({$colQuoted}, '{$jsonPath}', CAST('null' AS JSON))";
        }

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

        $sql = "JSON_REPLACE({$colQuoted}, '{$jsonPath}', CAST({$param} AS JSON))";

        return [$sql, [$param => $jsonText]];
    }
}
