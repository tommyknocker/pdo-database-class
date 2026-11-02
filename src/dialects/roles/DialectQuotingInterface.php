<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * Quoting of identifiers, tables and string literals.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectQuotingInterface
{
    /**
     * Quote table column in query.
     *
     * @param string $name
     *
     * @return string
     */
    public function quoteIdentifier(string $name): string;

    /**
     * Quote table.
     *
     * @param string $table
     *
     * @return string
     */
    public function quoteTable(string $table): string;

    /**
     * Quote a value as an SQL string literal, including the surrounding quotes.
     *
     * Use it only where a value must be written into SQL text (DDL, defaults, aggregate separators);
     * everything else belongs in bound parameters.
     */
    public function quoteStringLiteral(string $value): string;

    /**
     * Get table alias keyword (e.g., "AS" or empty string).
     * Some dialects (like Oracle) do not support AS keyword for table aliases.
     *
     * @return string The keyword to use before table alias (e.g., " AS " or " ")
     */
    public function getTableAliasKeyword(): string;
}
