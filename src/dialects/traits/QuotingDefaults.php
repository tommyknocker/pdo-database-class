<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectQuotingInterface;

/**
 * Default implementation of the quoting of identifiers and string literals part of the dialect ({@see DialectQuotingInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait QuotingDefaults
{
    /**
     * Get table alias keyword (e.g., "AS" or empty string).
     * Default implementation returns " AS " for most dialects.
     *
     * @return string The keyword to use before table alias (e.g., " AS " or " ")
     */
    public function getTableAliasKeyword(): string
    {
        return ' AS ';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function quoteStringLiteral(string $value): string
    {
        return "'" . str_replace(["\0", "'"], ['', "''"], $value) . "'";
    }
}
