<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

use tommyknocker\pdodb\helpers\traits\ErrorUtilityTrait;
use tommyknocker\pdodb\helpers\traits\MysqlErrorTrait;
use tommyknocker\pdodb\helpers\traits\PostgresqlErrorTrait;
use tommyknocker\pdodb\helpers\traits\SqliteErrorTrait;

/**
 * Database error codes constants for different dialects.
 *
 * This class provides standardized error codes for MySQL, PostgreSQL, and SQLite
 * to improve code readability and maintainability.
 */
class DbError
{
    use MysqlErrorTrait;
    use PostgresqlErrorTrait;
    use SqliteErrorTrait;
    use ErrorUtilityTrait;
}
