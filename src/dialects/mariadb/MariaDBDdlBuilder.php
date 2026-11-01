<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDdlBuilder;

/**
 * MariaDB DDL builder implementation.
 */
class MariaDBDdlBuilder extends MySQLFamilyDdlBuilder
{
    protected function engineLabel(): string
    {
        return 'MariaDB';
    }
}
