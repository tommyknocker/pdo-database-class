<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\traits;

use InvalidArgumentException;

trait IdentifierQuotingTrait
{
    /**
     * Quote qualified identifier.
     *
     * @param string $name
     *
     * @return string
     */
    protected function quoteQualifiedIdentifier(string $name): string
    {
        // If it looks like an expression (contains spaces, parentheses, commas or quotes)
        // treat as raw expression but DO NOT accept suspicious unquoted parts silently.
        if (preg_match('/[`\["\'\s\(\),]/', $name)) {
            // allow already-quoted or complex expressions to pass through,
            // but still protect obvious injection attempts by checking for dangerous tokens
            if (preg_match('/;|--|\bDROP\b|\bDELETE\b|\bINSERT\b|\bUPDATE\b|\bSELECT\b|\bUNION\b/i', $name)) {
                throw new InvalidArgumentException('Unsafe SQL expression provided as identifier/expression.');
            }
            return $name;
        }

        $parts = explode('.', $name);
        foreach ($parts as $p) {
            // require valid simple identifier parts
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $p)) {
                throw new InvalidArgumentException("Invalid identifier part: {$p}");
            }
        }
        $quoted = array_map(fn ($p) => $this->dialect->quoteIdentifier($p), $parts);
        return implode('.', $quoted);
    }
}
