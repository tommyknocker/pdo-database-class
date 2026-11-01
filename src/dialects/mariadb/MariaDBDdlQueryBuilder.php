<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDdlQueryBuilder;

/**
 * MariaDB-specific DDL Query Builder.
 *
 * Add MariaDB-only column types here; shared types live in MySQLFamilyDdlQueryBuilder.
 */
class MariaDBDdlQueryBuilder extends MySQLFamilyDdlQueryBuilder
{
}
