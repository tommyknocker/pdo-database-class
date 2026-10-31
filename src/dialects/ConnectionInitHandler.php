<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

/**
 * Init-wizard and env bootstrap profile (not a driver name).
 */
enum ConnectionInitHandler
{
    case SqliteFile;
    case MysqlTcp;
    case PgsqlTcp;
    case SqlsrvTcp;
    case OciService;
}
