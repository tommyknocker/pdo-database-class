<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDdlBuilder;

/**
 * MariaDB DDL builder implementation.
 */
class MariaDBDdlBuilder extends MySQLFamilyDdlBuilder
{
    /**
     * MariaDB 10.4+ enforces UNIQUE on long values through a hash.
     */
    protected function allowsUniqueLongText(): bool
    {
        return true;
    }

    protected function engineLabel(): string
    {
        return 'MariaDB';
    }
}
