<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use tommyknocker\pdodb\dialects\mysql\MySQLDdlQueryBuilder;

/**
 * MariaDB-specific DDL Query Builder.
 *
 * MariaDB is largely compatible with MySQL, so we extend MySQLDdlQueryBuilder
 * and add MariaDB-specific features.
 */
class MariaDBDdlQueryBuilder extends MySQLDdlQueryBuilder
{
    // MariaDB inherits all MySQL types and adds its own extensions
    // For now, MariaDB is fully compatible with MySQL schema builder
    // Future MariaDB-specific types can be added here
}
