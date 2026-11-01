<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDmlBuilder;

/**
 * MariaDB DML builder implementation.
 */
class MariaDBDmlBuilder extends MySQLFamilyDmlBuilder
{
    protected function engineLabel(): string
    {
        return 'MariaDB';
    }
}
