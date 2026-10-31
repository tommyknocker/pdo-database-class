<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use PDO;
use PDOException;

/**
 * MySQL and MariaDB treat " as a string delimiter unless ANSI_QUOTES is set.
 *
 * If the session mode cannot be read, treat double-quoted text as a literal
 * so an unknown mode does not expose a possible string value.
 */
final class SessionQuoteMode
{
    public static function doubleQuotesAreLiterals(?PDO $pdo): bool
    {
        if (!$pdo instanceof PDO) {
            return true;
        }

        $mode = null;

        try {
            $statement = $pdo->query('SELECT @@SESSION.sql_mode');
            if ($statement === false) {
                return true;
            }

            try {
                $mode = $statement->fetchColumn();
            } finally {
                $statement->closeCursor();
            }
        } catch (PDOException) {
            return true;
        }

        if (!is_string($mode)) {
            return true;
        }

        foreach (explode(',', $mode) as $flag) {
            if (strcasecmp(trim($flag), 'ANSI_QUOTES') === 0) {
                return false;
            }
        }

        return true;
    }
}
