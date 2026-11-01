<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\oracle;

/**
 * Rewrites SQL written for other databases into what Oracle accepts: boolean literals, COALESCE over CLOBs,
 * recursive CTE column lists and join conditions. {@see OracleDialect} delegates to it.
 */
final class OracleSqlNormalizer
{
    public function __construct(private readonly OracleDialect $dialect)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function rawValue(string $sql): string
    {
        /** @var string $sql */
        // Oracle-specific normalization: handle LENGTH(COALESCE(...)) with CLOB columns
        // LENGTH(COALESCE(email, phone, 'N/A')) -> LENGTH(TO_CHAR(COALESCE(TO_CHAR("EMAIL"), TO_CHAR("PHONE"), 'N/A')))
        if (preg_match('/^LENGTH\s*\(\s*COALESCE\s*\((.*)\)\s*\)$/i', trim($sql), $lengthMatches)) {
            $coalesceArgs = $lengthMatches[1];
            // Normalize COALESCE arguments
            $normalizedCoalesce = $this->coalesceArgs($coalesceArgs);
            return "LENGTH(TO_CHAR(COALESCE($normalizedCoalesce)))";
        }

        // Oracle-specific normalization: handle COALESCE with CLOB columns
        // COALESCE(phone, email, 'No contact') -> COALESCE(TO_CHAR("PHONE"), TO_CHAR("EMAIL"), 'No contact')
        if (preg_match('/^COALESCE\s*\((.*)\)$/i', trim($sql), $matches)) {
            $argsStr = $matches[1];
            // Split by comma, but respect quoted strings
            $args = [];
            $current = '';
            $inQuotes = false;
            $quoteChar = '';
            for ($i = 0; $i < strlen($argsStr); $i++) {
                $char = $argsStr[$i];
                if (($char === '"' || $char === "'" || $char === '`') && ($i === 0 || $argsStr[$i - 1] !== '\\')) {
                    if (!$inQuotes) {
                        $inQuotes = true;
                        $quoteChar = $char;
                    } elseif ($char === $quoteChar) {
                        $inQuotes = false;
                        $quoteChar = '';
                    }
                    $current .= $char;
                } elseif ($char === ',' && !$inQuotes) {
                    $args[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            if ($current !== '') {
                $args[] = trim($current);
            }

            $formattedArgs = [];
            foreach ($args as $arg) {
                $arg = trim($arg);
                // Check if it's a quoted string literal (single quotes for string literals in SQL)
                // Must start and end with single quote
                if (preg_match("/^'.*'$/", $arg)) {
                    // It's a string literal (single quotes) - keep as-is
                    $formattedArgs[] = $arg;
                } elseif (preg_match('/^"([^"]+)"$/', $arg, $matches)) {
                    // It's a quoted identifier - check if it contains single quotes (string literal in double quotes)
                    $inner = $matches[1];
                    if (preg_match("/^'.*'$/", $inner)) {
                        // It's a string literal wrapped in double quotes (e.g., "'No contact'")
                        // Extract the inner string literal and keep as-is
                        $formattedArgs[] = $inner;
                    } else {
                        // It's a quoted identifier (column) - apply formatColumnForComparison for CLOB compatibility
                        $formattedArgs[] = $this->dialect->formatColumnForComparison($arg);
                    }
                } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $arg)) {
                    // Check if it's a SQL keyword that should not be quoted
                    $upperArg = strtoupper($arg);
                    if (in_array($upperArg, ['NULL', 'TRUE', 'FALSE', 'DEFAULT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'SYSDATE', 'SYSTIMESTAMP'])) {
                        // It's a SQL keyword - keep as-is without quoting
                        $formattedArgs[] = $upperArg;
                    } else {
                        // It's an unquoted column identifier - quote and apply formatColumnForComparison
                        $quoted = $this->dialect->quoteIdentifier($arg);
                        $formattedArgs[] = $this->dialect->formatColumnForComparison($quoted);
                    }
                } else {
                    // It's an expression or something else - check if it contains CAST or CONCAT
                    // Apply CAST normalization if needed
                    if (preg_match('/CAST\s*\(([^,]+)\s+AS\s+([^)]+)\)/i', $arg, $castMatches)) {
                        $castExpr = trim($castMatches[1]);
                        $castType = trim($castMatches[2]);

                        // Check if CAST expression is a column identifier
                        $isCastQuotedIdentifier = preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $castExpr);
                        $isCastUnquotedIdentifier = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $castExpr);
                        $upperCastExpr = strtoupper($castExpr);
                        $isCastSqlKeyword = in_array($upperCastExpr, ['NULL', 'TRUE', 'FALSE', 'DEFAULT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'SYSDATE', 'SYSTIMESTAMP']);

                        if (($isCastQuotedIdentifier || $isCastUnquotedIdentifier) && !$isCastSqlKeyword) {
                            // It's a column - apply TO_CHAR() for CLOB compatibility before CAST
                            $castQuoted = $isCastQuotedIdentifier ? $castExpr : $this->dialect->quoteIdentifier($castExpr);
                            $castExprFormatted = $this->dialect->formatColumnForComparison($castQuoted);

                            // For numeric types (INTEGER, NUMBER, etc.), use CASE WHEN for safe casting
                            // Oracle CAST throws error on invalid values, so we need to catch it
                            if (preg_match('/^(INTEGER|NUMBER|DECIMAL|NUMERIC|REAL|FLOAT|DOUBLE)/i', $castType)) {
                                // Use CASE WHEN REGEXP_LIKE for safe numeric casting
                                // CASE WHEN REGEXP_LIKE(TO_CHAR(column), '^-?[0-9]+(\.[0-9]+)?$') THEN CAST(TO_CHAR(column) AS type) ELSE NULL END
                                $arg = "CASE WHEN REGEXP_LIKE($castExprFormatted, '^-?[0-9]+(\\.[0-9]+)?\$') THEN CAST($castExprFormatted AS $castType) ELSE NULL END";
                            } elseif (preg_match('/^(DATE|TIMESTAMP)/i', $castType)) {
                                // Use CASE WHEN with TO_DATE for safe date casting
                                // Try common date formats: YYYY-MM-DD, DD-MON-YYYY, etc.
                                // CASE WHEN REGEXP_LIKE(column, '^\d{4}-\d{2}-\d{2}') THEN TO_DATE(column, 'YYYY-MM-DD') ELSE NULL END
                                $arg = "CASE WHEN REGEXP_LIKE($castExprFormatted, '^\\d{4}-\\d{2}-\\d{2}') THEN TO_DATE($castExprFormatted, 'YYYY-MM-DD') ELSE NULL END";
                            } else {
                                // For other types, use CAST directly
                                $arg = preg_replace('/CAST\s*\(' . preg_quote($castExpr, '/') . '\s+AS\s+' . preg_quote($castType, '/') . '\)/i', "CAST($castExprFormatted AS $castType)", $arg);
                            }
                        }
                    }
                    // Check if it contains || operator (Oracle concatenation)
                    // 'Name: ' || name -> 'Name: ' || TO_CHAR("NAME")
                    if ($arg !== null && preg_match('/\|\|/', $arg)) {
                        // Handle || operator (Oracle concatenation)
                        // Split by || but respect quoted strings
                        $parts = [];
                        $current = '';
                        $inQuotes = false;
                        $quoteChar = '';
                        $argLength = strlen($arg);
                        for ($i = 0; $i < $argLength; $i++) {
                            $char = $arg[$i];
                            if (($char === '"' || $char === "'") && ($i === 0 || $arg[$i - 1] !== '\\')) {
                                if (!$inQuotes) {
                                    $inQuotes = true;
                                    $quoteChar = $char;
                                } elseif ($char === $quoteChar) {
                                    $inQuotes = false;
                                    $quoteChar = '';
                                }
                                $current .= $char;
                            } elseif ($char === '|' && $i + 1 < $argLength && $arg[$i + 1] === '|' && !$inQuotes) {
                                // Found || operator
                                $parts[] = trim($current);
                                $current = '';
                                $i++; // Skip second |
                            } else {
                                $current .= $char;
                            }
                        }
                        if ($current !== '') {
                            $parts[] = trim($current);
                        }

                        // Process each part
                        $formattedParts = [];
                        foreach ($parts as $part) {
                            $part = trim($part);
                            // If it's a quoted string literal, keep as-is
                            if (preg_match("/^'.*'$/", $part)) {
                                $formattedParts[] = $part;
                            } elseif (preg_match('/^"([^"]+)"$/', $part, $matches)) {
                                // It's a quoted identifier (column) - apply formatColumnForComparison
                                $formattedParts[] = $this->dialect->formatColumnForComparison($part);
                            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $part)) {
                                // Check if it's a SQL keyword that should not be quoted
                                $upperPart = strtoupper($part);
                                if (in_array($upperPart, ['NULL', 'TRUE', 'FALSE', 'DEFAULT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'SYSDATE', 'SYSTIMESTAMP'])) {
                                    // It's a SQL keyword - keep as-is without quoting
                                    $formattedParts[] = $upperPart;
                                } else {
                                    // It's an unquoted column identifier - quote and apply formatColumnForComparison
                                    $quoted = $this->dialect->quoteIdentifier($part);
                                    $formattedParts[] = $this->dialect->formatColumnForComparison($quoted);
                                }
                            } else {
                                // It's already an expression or quoted identifier - keep as-is
                                $formattedParts[] = $part;
                            }
                        }
                        // Rebuild with || operator
                        $arg = implode(' || ', $formattedParts);
                    } elseif ($arg !== null && preg_match('/CONCAT\s*\(([^)]+)\)/i', $arg, $concatMatches)) {
                        $concatArgs = $concatMatches[1];
                        // Split CONCAT arguments by comma, but respect quoted strings
                        $concatParts = [];
                        $current = '';
                        $inQuotes = false;
                        $quoteChar = '';
                        for ($i = 0; $i < strlen($concatArgs); $i++) {
                            $char = $concatArgs[$i];
                            if (($char === '"' || $char === "'") && ($i === 0 || $concatArgs[$i - 1] !== '\\')) {
                                if (!$inQuotes) {
                                    $inQuotes = true;
                                    $quoteChar = $char;
                                } elseif ($char === $quoteChar) {
                                    $inQuotes = false;
                                    $quoteChar = '';
                                }
                                $current .= $char;
                            } elseif ($char === ',' && !$inQuotes) {
                                $concatParts[] = trim($current);
                                $current = '';
                            } else {
                                $current .= $char;
                            }
                        }
                        if ($current !== '') {
                            $concatParts[] = trim($current);
                        }

                        // Process each CONCAT argument
                        $formattedConcatParts = [];
                        foreach ($concatParts as $part) {
                            $part = trim($part);
                            // If it's a quoted string literal, keep as-is
                            if (preg_match("/^'.*'$/", $part)) {
                                $formattedConcatParts[] = $part;
                            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $part)) {
                                // It's an unquoted column identifier - quote and apply formatColumnForComparison
                                $quoted = $this->dialect->quoteIdentifier($part);
                                $formattedConcatParts[] = $this->dialect->formatColumnForComparison($quoted);
                            } else {
                                // It's already an expression or quoted identifier - keep as-is
                                $formattedConcatParts[] = $part;
                            }
                        }
                        // Rebuild CONCAT with formatted parts
                        $arg = 'CONCAT(' . implode(', ', $formattedConcatParts) . ')';
                    }
                    $formattedArgs[] = $arg;
                }
            }
            // Return COALESCE without wrapping in TO_CHAR()
            // COALESCE may return non-string types (INTEGER, NUMBER, etc.) from CAST operations
            // Wrapping in TO_CHAR() would cause type conversion issues
            return 'COALESCE(' . implode(', ', $formattedArgs) . ')';
        }

        // Oracle-specific normalization: handle NULLIF with CLOB columns
        // NULLIF(email, '') -> NULLIF(TO_CHAR("EMAIL"), '')
        if (preg_match('/^NULLIF\s*\((.*),\s*(.*)\)$/i', trim($sql), $matches)) {
            $expr1 = trim($matches[1]);
            $expr2 = trim($matches[2]);

            // Apply formatColumnForComparison to first expression if it's a column
            if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $expr1) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $expr1)) {
                // It's a column identifier
                $quoted = preg_match('/^["`\[\]]/', $expr1) ? $expr1 : $this->dialect->quoteIdentifier($expr1);
                $expr1Formatted = $this->dialect->formatColumnForComparison($quoted);
            } else {
                // It's an expression - keep as-is
                $expr1Formatted = $expr1;
            }

            return "NULLIF($expr1Formatted, $expr2)";
        }

        // Oracle-specific normalization: handle CAST with CLOB columns
        // CAST(text_value AS NUMBER) -> CAST(TO_CHAR("TEXT_VALUE") AS NUMBER)
        if (preg_match('/CAST\s*\(([^,]+)\s+AS\s+([^)]+)\)/i', $sql, $matches)) {
            $expr = trim($matches[1]);
            $type = trim($matches[2]);

            // Check if expression is a column identifier (quoted or unquoted)
            if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $expr) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $expr)) {
                // It's a column - apply TO_CHAR() for CLOB compatibility before CAST
                $quoted = preg_match('/^["`\[\]]/', $expr) ? $expr : $this->dialect->quoteIdentifier($expr);
                $exprFormatted = $this->dialect->formatColumnForComparison($quoted);
                // Replace CAST(column AS type) with CAST(TO_CHAR(column) AS type)
                $result = preg_replace('/CAST\s*\(' . preg_quote($expr, '/') . '\s+AS\s+' . preg_quote($type, '/') . '\)/i', "CAST($exprFormatted AS $type)", $sql);
                if (is_string($result)) {
                    $sql = $result;
                }
            }
        }

        // Oracle-specific normalization: handle LOG10() -> LOG(10, ...)
        // Oracle doesn't support LOG10(), use LOG(10, ...) instead
        if (preg_match('/LOG10\s*\(/i', $sql)) {
            $result = preg_replace('/LOG10\s*\(/i', 'LOG(10, ', $sql);
            if (is_string($result)) {
                $sql = $result;
            }
        }

        // Oracle-specific normalization: handle TRUE/FALSE -> 1/0
        // Oracle doesn't support TRUE/FALSE constants, use 1/0 instead
        if ($sql !== '') {
            $result = preg_replace('/\bTRUE\b/i', '1', $sql);
            if (is_string($result)) {
                $sql = $result;
            }
            $result = preg_replace('/\bFALSE\b/i', '0', $sql);
            if (is_string($result)) {
                $sql = $result;
            }
        }

        return $sql;
    }

    /**
     * Normalize COALESCE arguments for CLOB compatibility.
     *
     * @param string $argsStr The COALESCE arguments string
     *
     * @return string Normalized arguments string
     */
    public function coalesceArgs(string $argsStr): string
    {
        // Split by comma, but respect quoted strings
        $args = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        for ($i = 0; $i < strlen($argsStr); $i++) {
            $char = $argsStr[$i];
            if (($char === '"' || $char === "'" || $char === '`') && ($i === 0 || $argsStr[$i - 1] !== '\\')) {
                if (!$inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuotes = false;
                    $quoteChar = '';
                }
                $current .= $char;
            } elseif ($char === ',' && !$inQuotes) {
                $args[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if ($current !== '') {
            $args[] = trim($current);
        }

        $formattedArgs = [];
        foreach ($args as $arg) {
            $arg = trim($arg);
            // Check if it's a quoted string literal (single quotes for string literals in SQL)
            if (preg_match("/^'.*'$/", $arg)) {
                // It's a string literal (single quotes) - keep as-is
                $formattedArgs[] = $arg;
            } elseif (preg_match('/^"([^"]+)"$/', $arg, $matches)) {
                // It's a quoted identifier - check if it contains single quotes (string literal in double quotes)
                $inner = $matches[1];
                if (preg_match("/^'.*'$/", $inner)) {
                    // It's a string literal wrapped in double quotes (e.g., "'No contact'")
                    // Extract the inner string literal and keep as-is
                    $formattedArgs[] = $inner;
                } else {
                    // It's a quoted identifier (column) - apply formatColumnForComparison for CLOB compatibility
                    $formattedArgs[] = $this->dialect->formatColumnForComparison($arg);
                }
            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $arg)) {
                // It's an unquoted column identifier - quote and apply formatColumnForComparison
                $quoted = $this->dialect->quoteIdentifier($arg);
                $formattedArgs[] = $this->dialect->formatColumnForComparison($quoted);
            } else {
                // It's an expression or something else - keep as-is
                $formattedArgs[] = $arg;
            }
        }

        return implode(', ', $formattedArgs);
    }

    /**
     * {@inheritDoc}
     */
    public function recursiveCte(string $sql, string $cteName, bool $isRecursive): string
    {
        if (!$isRecursive) {
            return $sql;
        }
        /** @var string $sql */

        // Oracle requires CTE name qualification instead of aliases in recursive part
        // Extract unquoted CTE name for pattern matching
        $unquotedCteName = trim($cteName, '"');
        $unquotedCteNameUpper = strtoupper($unquotedCteName);

        // Oracle requires column list for recursive CTEs (ORA-32039 without it)
        // When column list is present, column aliases in SELECT must be removed
        // Pattern: AS identifier or AS "identifier" (case-insensitive)
        // This removes aliases like "0 as level" -> "0" or "name AS \"PATH\"" -> "name"
        $result = preg_replace('/\s+AS\s+(?:"[^"]+"|[a-zA-Z_][a-zA-Z0-9_]*)/i', '', $sql);
        if (is_string($result)) {
            $sql = $result;
        }

        // Replace aliases in recursive part (after UNION ALL)
        // Pattern: "CT"."COLUMN" or ct.column -> CTE_NAME.COLUMN
        if (preg_match('/UNION\s+ALL\s+(.+)$/is', $sql, $matches)) {
            $recursivePart = $matches[1];

            // Extract CTE alias from JOIN clause to avoid false matches
            // Pattern: JOIN "CTE_NAME" "ALIAS" or JOIN cte_name alias
            $cteAlias = null;
            if (preg_match('/JOIN\s+"?' . preg_quote($unquotedCteNameUpper, '/') . '"?\s+"?([a-zA-Z_][a-zA-Z0-9_]*)"?/i', $recursivePart, $joinMatch)) {
                $cteAlias = strtoupper($joinMatch[1]);
            }

            // Replace quoted aliases like "CT" with CTE name
            // Match pattern: "ALIAS"."COLUMN" where ALIAS matches CTE name
            $result = preg_replace_callback(
                '/"([^"]+)"\s*\.\s*"([^"]+)"/i',
                function ($m) use ($cteName, $unquotedCteNameUpper, $cteAlias) {
                    $alias = strtoupper($m[1]);
                    $column = $m[2];
                    // Check if alias matches CTE name or the detected CTE alias
                    $isCtReference = (
                        $alias === $unquotedCteNameUpper ||
                        ($cteAlias !== null && $alias === $cteAlias)
                    );

                    if ($isCtReference) {
                        return $cteName . '.' . $this->dialect->quoteIdentifier($column);
                    }
                    return $m[0];
                },
                $recursivePart
            );
            if (is_string($result)) {
                $recursivePart = $result;
            }
            // Replace unquoted aliases like ct.column or ct.level
            // Match pattern: alias.column (not in quotes)
            $result = preg_replace_callback(
                '/(\b)([a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*([a-zA-Z_][a-zA-Z0-9_]*|\*)(\b)/',
                function ($m) use ($cteName, $unquotedCteNameUpper, $cteAlias) {
                    $alias = strtoupper($m[2]);
                    $column = $m[3];
                    // Check if alias matches CTE name or the detected CTE alias
                    $isCtReference = (
                        $alias === $unquotedCteNameUpper ||
                        ($cteAlias !== null && $alias === $cteAlias)
                    );

                    if ($isCtReference) {
                        $quotedColumn = $column === '*' ? '*' : $this->dialect->quoteIdentifier($column);
                        return $m[1] . $cteName . '.' . $quotedColumn . $m[4];
                    }
                    return $m[0];
                },
                $recursivePart
            );
            if (is_string($result)) {
                $recursivePart = $result;
            }
            // Remove aliases from CTE references in JOIN
            // Oracle doesn't allow aliases for CTE in recursive queries
            // Change: INNER JOIN "TREE" "T" -> INNER JOIN "TREE"
            if ($recursivePart !== '') {
                $result = preg_replace_callback(
                    '/INNER\s+JOIN\s+"([^"]+)"\s+"([^"]+)"/i',
                    function ($m) use ($cteName, $unquotedCteNameUpper) {
                        $tableName = strtoupper($m[1]);
                        // If table name matches CTE name, remove the alias
                        if ($tableName === $unquotedCteNameUpper) {
                            return 'INNER JOIN ' . $cteName;
                        }
                        return $m[0];
                    },
                    $recursivePart
                );
                if (is_string($result)) {
                    $recursivePart = $result;
                }
                if ($recursivePart !== '') {
                    $result = preg_replace('/UNION\s+ALL\s+.+$/is', 'UNION ALL ' . $recursivePart, $sql);
                    if (is_string($result)) {
                        $sql = $result;
                    }
                }
            }
        }

        return $sql;
    }

    public function joinCondition(string $condition): string
    {
        // Oracle: identifiers in ON conditions must be quoted
        // Pattern: table.column or alias.column
        // Replace unquoted identifiers with quoted ones
        return preg_replace_callback(
            '/(\w+)\.(\w+)/',
            function ($matches) {
                $table = $this->dialect->quoteIdentifier($matches[1]);
                $column = $this->dialect->quoteIdentifier($matches[2]);
                return "{$table}.{$column}";
            },
            $condition
        ) ?? $condition;
    }
}
