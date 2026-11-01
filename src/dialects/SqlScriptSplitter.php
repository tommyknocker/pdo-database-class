<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

/**
 * Splits a SQL script into statements at semicolons that are not inside a string, a quoted identifier, a comment, a
 * dollar-quoted body or parentheses. Newlines inside strings are kept exactly as written.
 */
final class SqlScriptSplitter
{
    /**
     * @param string $quoteChars Characters that open a quoted string or identifier; a doubled character is an escape
     * @param bool $dollarQuotes Treat $tag$ ... $tag$ as a quoted body (PostgreSQL)
     * @param bool $bracketIdentifiers Treat [name] as a quoted identifier (SQL Server)
     * @param bool $parentheses Do not end a statement at a semicolon inside parentheses
     */
    public function __construct(
        private readonly string $quoteChars = "'\"`",
        private readonly bool $dollarQuotes = false,
        private readonly bool $bracketIdentifiers = false,
        private readonly bool $parentheses = false,
    ) {
    }

    /**
     * @return list<string>
     */
    public function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $depth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // line comment: dropped together with its line break kept
            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end - 1;
                continue;
            }
            // block comment
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($this->dollarQuotes && $char === '$' && preg_match('/\G\$[A-Za-z_]*\$/', $sql, $tag, 0, $i) === 1) {
                $close = strpos($sql, $tag[0], $i + strlen($tag[0]));
                $end = $close === false ? $length : $close + strlen($tag[0]);
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($this->bracketIdentifiers && $char === '[') {
                $close = strpos($sql, ']', $i);
                $end = $close === false ? $length : $close + 1;
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if (str_contains($this->quoteChars, $char)) {
                $current .= $char;
                for ($i++; $i < $length; $i++) {
                    $current .= $sql[$i];
                    if ($sql[$i] === $char) {
                        if ($i + 1 < $length && $sql[$i + 1] === $char) {
                            $current .= $sql[++$i];
                            continue;
                        }
                        break;
                    }
                }
                continue;
            }
            if ($this->parentheses && $char === '(') {
                $depth++;
            } elseif ($this->parentheses && $char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ';' && $depth === 0) {
                $this->add($statements, $current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $this->add($statements, $current);

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private function add(array &$statements, string $statement): void
    {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }
}
