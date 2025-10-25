<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

/**
 * Trait for MySQL error codes and related functionality.
 */
trait MysqlErrorTrait
{
    // MySQL Error Codes
    public const MYSQL_CONNECTION_LOST = 2006;
    public const MYSQL_CANNOT_CONNECT = 2002;
    public const MYSQL_CONNECTION_KILLED = 2013;
    public const MYSQL_CONNECTION_REFUSED = 2003;
    public const MYSQL_COMMANDS_OUT_OF_SYNC = 2014;
    public const MYSQL_LOCK_WAIT_TIMEOUT = 1205;
    public const MYSQL_DEADLOCK = 1213;
    public const MYSQL_DUPLICATE_KEY = 1062;
    public const MYSQL_FOREIGN_KEY_DELETE = 1451;
    public const MYSQL_FOREIGN_KEY_INSERT = 1452;
    public const MYSQL_FOREIGN_KEY_INSERT_CHILD = 1216;
    public const MYSQL_FOREIGN_KEY_DELETE_PARENT = 1217;
    public const MYSQL_TABLE_EXISTS = 1050;
    public const MYSQL_TABLE_NOT_EXISTS = 1146;
    public const MYSQL_COLUMN_NOT_EXISTS = 1054;
    public const MYSQL_SYNTAX_ERROR = 1064;
    public const MYSQL_ACCESS_DENIED = 1045;
    public const MYSQL_ACCESS_DENIED_USER = 1044;
    public const MYSQL_DATABASE_NOT_EXISTS = 1049;
    public const MYSQL_TOO_MANY_CONNECTIONS = 1040;
    public const MYSQL_OUT_OF_MEMORY = 1041;
    public const MYSQL_QUERY_INTERRUPTED = 1317;

    /**
     * Get retryable error codes for MySQL.
     *
     * @return array<int>
     */
    public static function getMysqlRetryableErrors(): array
    {
        return [
            self::MYSQL_CONNECTION_LOST,
            self::MYSQL_CANNOT_CONNECT,
            self::MYSQL_CONNECTION_KILLED,
            self::MYSQL_LOCK_WAIT_TIMEOUT,
            self::MYSQL_DEADLOCK,
            self::MYSQL_TOO_MANY_CONNECTIONS,
            self::MYSQL_QUERY_INTERRUPTED,
        ];
    }

    /**
     * Get MySQL error descriptions.
     *
     * @return array<int, string>
     */
    private static function getMysqlDescriptions(): array
    {
        return [
            self::MYSQL_CONNECTION_LOST => 'MySQL server has gone away',
            self::MYSQL_CANNOT_CONNECT => 'Can\'t connect to MySQL server',
            self::MYSQL_CONNECTION_KILLED => 'Connection was killed',
            self::MYSQL_CONNECTION_REFUSED => 'Can\'t connect to MySQL server on host',
            self::MYSQL_COMMANDS_OUT_OF_SYNC => 'Commands out of sync',
            self::MYSQL_LOCK_WAIT_TIMEOUT => 'Lock wait timeout exceeded',
            self::MYSQL_DEADLOCK => 'Deadlock found when trying to get lock',
            self::MYSQL_DUPLICATE_KEY => 'Duplicate entry',
            self::MYSQL_FOREIGN_KEY_DELETE => 'Cannot delete or update a parent row',
            self::MYSQL_FOREIGN_KEY_INSERT => 'Cannot add or update a child row',
            self::MYSQL_FOREIGN_KEY_INSERT_CHILD => 'Cannot add or update a child row',
            self::MYSQL_FOREIGN_KEY_DELETE_PARENT => 'Cannot delete or update a parent row',
            self::MYSQL_TABLE_EXISTS => 'Table already exists',
            self::MYSQL_TABLE_NOT_EXISTS => 'Table doesn\'t exist',
            self::MYSQL_COLUMN_NOT_EXISTS => 'Unknown column',
            self::MYSQL_SYNTAX_ERROR => 'Syntax error',
            self::MYSQL_ACCESS_DENIED => 'Access denied',
            self::MYSQL_ACCESS_DENIED_USER => 'Access denied for user',
            self::MYSQL_DATABASE_NOT_EXISTS => 'Unknown database',
            self::MYSQL_TOO_MANY_CONNECTIONS => 'Too many connections',
            self::MYSQL_OUT_OF_MEMORY => 'Out of memory',
            self::MYSQL_QUERY_INTERRUPTED => 'Query execution was interrupted',
        ];
    }
}
