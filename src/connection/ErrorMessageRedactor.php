<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use Throwable;

/**
 * Removes data values from database error messages before they are logged.
 *
 * Drivers copy the offending row into the message of a constraint violation (`Key (email)=(a@b.c) already exists`),
 * so logging `$e->getMessage()` writes bound values to the log. The kind of error, the constraint and the column
 * names stay; the values do not.
 */
final class ErrorMessageRedactor
{
    private const PLACEHOLDER = '[redacted]';

    public static function message(Throwable $e): string
    {
        return self::redact($e->getMessage());
    }

    public static function redact(string $message): string
    {
        // PostgreSQL: the DETAIL line carries the values ("Key (email)=(a@b.c) already exists.")
        $message = (string)preg_replace('/\s*DETAIL:.*$/s', ' DETAIL: ' . self::PLACEHOLDER, $message);
        // MySQL / MariaDB: Duplicate entry 'value' for key 'name'
        $message = (string)preg_replace("/Duplicate entry '.*?' for key/s", "Duplicate entry '" . self::PLACEHOLDER . "' for key", $message);
        // SQL Server: The duplicate key value is (value).
        $message = (string)preg_replace('/(duplicate key value is )\(.*?\)\./is', '$1(' . self::PLACEHOLDER . ').', $message);
        // Data that does not fit a column: Data too long / Incorrect ... value: 'x' for column
        $message = (string)preg_replace("/(value: )'.*?'( for column)/s", "$1'" . self::PLACEHOLDER . "'$2", $message);

        return $message;
    }
}
