<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDmlBuilder;

/**
 * MySQL DML builder implementation.
 */
class MySQLDmlBuilder extends MySQLFamilyDmlBuilder
{
    protected function engineLabel(): string
    {
        return 'MySQL';
    }

    protected function usesRowAlias(): bool
    {
        return $this->dialect instanceof MySQLDialect && $this->dialect->usesUpsertAlias();
    }
}
