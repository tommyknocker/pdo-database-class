<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use PDO;
use PDOException;

/**
 * MySQL and MariaDB treat " as a string delimiter unless ANSI_QUOTES is set.
 *
 * A missing connection or a failed read leaves the text visible. This is not a
 * claim that every double-quoted value is hidden.
 */
final class SessionQuoteMode
{
    public static function doubleQuotesAreLiterals(?PDO $pdo): bool
    {
        if (!$pdo instanceof PDO) {
            return false;
        }

        $mode = null;

        try {
            $statement = $pdo->query('SELECT @@SESSION.sql_mode');
            if ($statement === false) {
                return false;
            }

            try {
                $mode = $statement->fetchColumn();
            } finally {
                $statement->closeCursor();
            }
        } catch (PDOException) {
            return false;
        }

        if (!is_string($mode)) {
            return false;
        }

        foreach (explode(',', $mode) as $flag) {
            if (strcasecmp(trim($flag), 'ANSI_QUOTES') === 0) {
                return false;
            }
        }

        return true;
    }
}
