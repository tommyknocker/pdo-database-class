<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\formatter;

/**
 * SQL Formatter for pretty-printing SQL queries.
 * Formats SQL with proper indentation, line breaks, and optional keyword highlighting.
 */
class SqlFormatter
{
    /** @var array<string> SQL keywords that should be on new lines */
    protected const KEYWORDS_NEWLINE = [
        'SELECT', 'FROM', 'WHERE', 'JOIN', 'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN',
        'FULL JOIN', 'CROSS JOIN', 'LEFT OUTER JOIN', 'RIGHT OUTER JOIN',
        'FULL OUTER JOIN', 'ON', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT', 'OFFSET',
        'UNION', 'UNION ALL', 'INTERSECT', 'EXCEPT', 'WITH', 'AS', 'INSERT',
        'UPDATE', 'DELETE', 'VALUES', 'SET', 'INTO', 'AND', 'OR', 'MERGE', 'USING',
    ];

    /** @var array<string> Keywords that should be uppercase */
    protected const KEYWORDS_UPPERCASE = [
        'SELECT', 'FROM', 'WHERE', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'OUTER',
        'CROSS', 'LATERAL', 'ON', 'GROUP', 'BY', 'ORDER', 'HAVING', 'LIMIT', 'OFFSET',
        'UNION', 'ALL', 'INTERSECT', 'EXCEPT', 'WITH', 'AS', 'INSERT', 'UPDATE',
        'DELETE', 'VALUES', 'SET', 'INTO', 'AND', 'OR', 'NOT', 'IN', 'EXISTS',
        'BETWEEN', 'LIKE', 'IS', 'NULL', 'ASC', 'DESC', 'DISTINCT', 'COUNT', 'SUM',
        'AVG', 'MAX', 'MIN', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'CAST', 'IF',
        'COALESCE', 'NULLIF', 'TRUE', 'FALSE', 'DEFAULT', 'PRIMARY', 'KEY',
        'UNIQUE', 'FOREIGN', 'REFERENCES', 'CONSTRAINT', 'INDEX', 'TABLE', 'CREATE',
        'ALTER', 'DROP', 'TRUNCATE', 'MERGE', 'USING', 'MATCHED', 'MATCHED BY SOURCE',
    ];

    protected bool $highlightKeywords;
    protected int $indentSize;
    protected string $indentChar;

    /**
     * @param bool $highlightKeywords Whether to highlight keywords (for CLI output)
     * @param int $indentSize Number of spaces per indentation level
     * @param string $indentChar Character to use for indentation (space or tab)
     */
    public function __construct(
        bool $highlightKeywords = false,
        int $indentSize = 4,
        string $indentChar = ' '
    ) {
        $this->highlightKeywords = $highlightKeywords;
        $this->indentSize = $indentSize;
        $this->indentChar = $indentChar;
    }

    /**
     * Format SQL query with proper indentation and line breaks.
     *
     * @param string $sql Raw SQL query
     *
     * @return string Formatted SQL query
     */
    public function format(string $sql): string
    {
        // Normalize whitespace first
        $sql = $this->normalizeWhitespace($sql);

        // Split into tokens while preserving structure
        $tokens = $this->tokenize($sql);

        // Format tokens with indentation
        $formatted = $this->formatTokens($tokens);

        // Clean up trailing whitespace
        return $this->cleanup($formatted);
    }

    /**
     * Normalize whitespace in SQL.
     *
     * @param string $sql
     *
     * @return string
     */
    protected function normalizeWhitespace(string $sql): string
    {
        // Replace multiple spaces with single space
        $sql = (string)preg_replace('/\s+/', ' ', $sql);
        // Remove spaces around parentheses
        $sql = (string)preg_replace('/\s*\(\s*/', '(', $sql);
        $sql = (string)preg_replace('/\s*\)\s*/', ')', $sql);
        // Remove spaces around commas
        $sql = (string)preg_replace('/\s*,\s*/', ', ', $sql);
        // Trim
        return trim($sql);
    }

    /**
     * Tokenize SQL into an array of tokens.
     *
     * @param string $sql
     *
     * @return array<string>
     */
    protected function tokenize(string $sql): array
    {
        $tokens = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $inParens = 0;

        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $nextChar = $i + 1 < $length ? $sql[$i + 1] : '';

            // Handle quoted strings
            if (($char === '"' || $char === "'" || $char === '`') && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($char === $quoteChar && $inQuotes) {
                // Check for escaped quote
                if ($i > 0 && $sql[$i - 1] === '\\') {
                    $current .= $char;
                } else {
                    $inQuotes = false;
                    $quoteChar = '';
                    $current .= $char;
                }
            } elseif ($inQuotes) {
                $current .= $char;
            } elseif ($char === '(') {
                if ($current !== '') {
                    $tokens[] = trim($current);
                    $current = '';
                }
                $tokens[] = '(';
                $inParens++;
            } elseif ($char === ')') {
                if ($current !== '') {
                    $tokens[] = trim($current);
                    $current = '';
                }
                $tokens[] = ')';
                $inParens--;
            } elseif ($char === ',' && $inParens === 0) {
                if ($current !== '') {
                    $tokens[] = trim($current);
                    $current = '';
                }
                $tokens[] = ',';
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $tokens[] = trim($current);
        }

        return $tokens;
    }

    /**
     * Format tokens with proper indentation.
     *
     * @param array<string> $tokens
     *
     * @return string
     */
    protected function formatTokens(array $tokens): string
    {
        $result = [];
        $indentLevel = 0;
        $inSubquery = false;
        $i = 0;
        $tokenCount = count($tokens);
        $lastTokenWasKeyword = false;

        while ($i < $tokenCount) {
            $token = $tokens[$i];
            $upperToken = strtoupper($token);
            $isKeyword = $this->isKeyword($upperToken);

            // Handle opening parenthesis (subquery)
            if ($token === '(') {
                $result[] = '(';
                $indentLevel++;
                $inSubquery = true;
                $i++;
                continue;
            }

            // Handle closing parenthesis
            if ($token === ')') {
                $indentLevel--;
                if ($indentLevel < 0) {
                    $indentLevel = 0;
                }
                $inSubquery = $indentLevel > 0;
                if (!empty($result) && end($result) !== "\n") {
                    $result[] = "\n" . $this->getIndent($indentLevel);
                }
                $result[] = ')';
                $i++;
                continue;
            }

            // Handle commas - new line for list items in SELECT/INSERT
            if ($token === ',') {
                $result[] = ',';
                // Check if next token is a major keyword
                if ($i + 1 < $tokenCount) {
                    $nextToken = $tokens[$i + 1];
                    $nextUpper = strtoupper($nextToken);
                    if ($this->isMajorKeyword($nextUpper)) {
                        $result[] = "\n" . $this->getIndent($indentLevel);
                    } else {
                        $result[] = ' ';
                    }
                } else {
                    $result[] = ' ';
                }
                $i++;
                continue;
            }

            // Handle major keywords (new line)
            if ($isKeyword && $this->shouldBeOnNewLine($upperToken)) {
                // Don't add newline if it's the first token
                if (!empty($result) && end($result) !== "\n") {
                    $result[] = "\n";
                }
                $result[] = $this->getIndent($indentLevel);
                $result[] = $this->formatKeyword($upperToken);
                $result[] = ' ';
                $lastTokenWasKeyword = true;
                $i++;
                continue;
            }

            // Handle AND/OR in WHERE/HAVING clauses
            if (($upperToken === 'AND' || $upperToken === 'OR') && !$inSubquery) {
                $result[] = "\n" . $this->getIndent($indentLevel);
                $result[] = $this->formatKeyword($upperToken);
                $result[] = ' ';
                $lastTokenWasKeyword = true;
                $i++;
                continue;
            }

            // Regular token
            if (!empty($result) && end($result) !== ' ' && end($result) !== "\n" && end($result) !== '(') {
                if (!$lastTokenWasKeyword) {
                    $result[] = ' ';
                }
            }
            $result[] = $this->maybeHighlightKeyword($token, $upperToken);
            $lastTokenWasKeyword = false;
            $i++;
        }

        return implode('', $result);
    }

    /**
     * Check if token is a SQL keyword.
     *
     * @param string $token
     *
     * @return bool
     */
    protected function isKeyword(string $token): bool
    {
        return in_array($token, self::KEYWORDS_UPPERCASE, true);
    }

    /**
     * Check if keyword should be on a new line.
     *
     * @param string $token
     *
     * @return bool
     */
    protected function shouldBeOnNewLine(string $token): bool
    {
        foreach (self::KEYWORDS_NEWLINE as $keyword) {
            if (str_starts_with($token, $keyword) || str_starts_with($keyword, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if token is a major keyword.
     *
     * @param string $token
     *
     * @return bool
     */
    protected function isMajorKeyword(string $token): bool
    {
        $majorKeywords = ['SELECT', 'FROM', 'WHERE', 'JOIN', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'OFFSET'];
        foreach ($majorKeywords as $keyword) {
            if (str_starts_with($token, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Format keyword with uppercase and optional highlighting.
     *
     * @param string $keyword
     *
     * @return string
     */
    protected function formatKeyword(string $keyword): string
    {
        $formatted = strtoupper($keyword);
        return $this->maybeHighlightKeyword($formatted, $formatted);
    }

    /**
     * Optionally highlight keyword if highlighting is enabled.
     *
     * @param string $token
     * @param string $upperToken
     *
     * @return string
     */
    protected function maybeHighlightKeyword(string $token, string $upperToken): string
    {
        if (!$this->highlightKeywords) {
            return $this->isKeyword($upperToken) ? strtoupper($token) : $token;
        }

        // CLI color highlighting (ANSI codes)
        if ($this->isKeyword($upperToken)) {
            return "\033[1;34m" . strtoupper($token) . "\033[0m"; // Blue, bold
        }

        return $token;
    }

    /**
     * Get indentation string for current level.
     *
     * @param int $level
     *
     * @return string
     */
    protected function getIndent(int $level): string
    {
        if ($level < 0) {
            $level = 0;
        }
        return str_repeat($this->indentChar, $level * $this->indentSize);
    }

    /**
     * Clean up formatted SQL.
     *
     * @param string $sql
     *
     * @return string
     */
    protected function cleanup(string $sql): string
    {
        // Remove multiple consecutive newlines
        $sql = (string)preg_replace('/\n{3,}/', "\n\n", $sql);
        // Remove trailing spaces from lines
        $sql = (string)preg_replace('/[ \t]+$/m', '', $sql);
        // Remove spaces before newlines
        $sql = (string)preg_replace('/ +\n/', "\n", $sql);
        // Trim
        return trim($sql);
    }
}
