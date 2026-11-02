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
        // PostgreSQL: DETAIL, CONTEXT and STATEMENT lines carry values ("Key (email)=(a@b.c) already exists.",
        // "Failing row contains (...)", "JSON data, line 1: ...")
        $message = (string)preg_replace('/\s*(DETAIL|CONTEXT|STATEMENT):.*$/s', ' $1: ' . self::PLACEHOLDER, $message);
        // PostgreSQL, first line: invalid input syntax for type integer: "abc" / value "99999" is out of range for type
        // smallint / invalid input value for enum mood: "abc". Names of relations, columns and constraints are
        // preceded by their kind (relation "t"), never by ": " or "value ", so they stay.
        $message = (string)preg_replace('/(\bvalue |: )"(?:[^"\n]|"")*"/', '$1"' . self::PLACEHOLDER . '"', $message);
        // MySQL / MariaDB: Duplicate entry 'value' for key 'name'
        $message = (string)preg_replace("/Duplicate entry '.*?' for key/s", "Duplicate entry '" . self::PLACEHOLDER . "' for key", $message);
        // MySQL / MariaDB: Incorrect integer value: 'abc' for column ..., Truncated incorrect DOUBLE value: 'abc'
        $message = (string)preg_replace("/(value: )'(?:[^'\\\\]|\\\\.|'')*'/s", "$1'" . self::PLACEHOLDER . "'", $message);
        // SQL Server: The duplicate key value is (value).
        $message = (string)preg_replace('/(duplicate key value is )\(.*?\)\./is', '$1(' . self::PLACEHOLDER . ').', $message);
        // SQL Server: Conversion failed when converting the nvarchar value 'abc' to data type int. / The conversion of
        // the nvarchar value '999' overflowed an int column. / Truncated value: 'abc'.
        $message = (string)preg_replace(
            "/((?:converting the|conversion of the) \\w+ value |Truncated value: )'(?:[^']|'')*'/i",
            "$1'" . self::PLACEHOLDER . "'",
            $message
        );

        return $message;
    }
}
