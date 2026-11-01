<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDdlBuilder;

/**
 * MySQL DDL builder implementation.
 */
class MySQLDdlBuilder extends MySQLFamilyDdlBuilder
{
    protected function engineLabel(): string
    {
        return 'MySQL';
    }
}
