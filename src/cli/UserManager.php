<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\PdoDb;

/**
 * User manager for CLI operations.
 *
 * Provides methods for creating, dropping, listing, and managing database users.
 */
class UserManager extends BaseCliCommand
{
    /**
     * Create a database user.
     *
     * @param string $username Username
     * @param string $password Password
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If user creation fails
     */
    public static function create(string $username, string $password, ?string $host, PdoDb $db): bool
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->createUser($username, $password, $host, $db);
    }

    /**
     * Drop a database user.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If user deletion fails
     */
    public static function drop(string $username, ?string $host, PdoDb $db): bool
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->dropUser($username, $host, $db);
    }

    /**
     * Check if a database user exists.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True if user exists
     * @throws ResourceException If check fails
     */
    public static function exists(string $username, ?string $host, PdoDb $db): bool
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->userExists($username, $host, $db);
    }

    /**
     * List all database users.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, array<string, mixed>> List of users
     * @throws ResourceException If listing fails
     */
    public static function list(PdoDb $db): array
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->listUsers($db);
    }

    /**
     * Get user information and privileges.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> User information
     * @throws ResourceException If retrieval fails
     */
    public static function getInfo(string $username, ?string $host, PdoDb $db): array
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->getUserInfo($username, $host, $db);
    }

    /**
     * Grant privileges to a user.
     *
     * @param string $username Username
     * @param string $privileges Privileges (e.g., 'SELECT,INSERT,UPDATE' or 'ALL')
     * @param string|null $database Database name (null = all databases, '*' = all databases)
     * @param string|null $table Table name (null = all tables, '*' = all tables)
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If grant fails
     */
    public static function grant(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        PdoDb $db
    ): bool {
        $dialect = $db->schema()->getDialect();
        return $dialect->grantPrivileges($username, $privileges, $database, $table, $host, $db);
    }

    /**
     * Revoke privileges from a user.
     *
     * @param string $username Username
     * @param string $privileges Privileges (e.g., 'SELECT,INSERT,UPDATE' or 'ALL')
     * @param string|null $database Database name (null = all databases, '*' = all databases)
     * @param string|null $table Table name (null = all tables, '*' = all tables)
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If revoke fails
     */
    public static function revoke(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        PdoDb $db
    ): bool {
        $dialect = $db->schema()->getDialect();
        return $dialect->revokePrivileges($username, $privileges, $database, $table, $host, $db);
    }

    /**
     * Change user password.
     *
     * @param string $username Username
     * @param string $newPassword New password
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If password change fails
     */
    public static function changePassword(string $username, string $newPassword, ?string $host, PdoDb $db): bool
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->changeUserPassword($username, $newPassword, $host, $db);
    }

    /**
     * Create server connection from existing database connection.
     *
     * @param PdoDb $db Existing database connection
     *
     * @return PdoDb Server connection
     */
    public static function createServerConnectionFromDb(PdoDb $db): PdoDb
    {
        return $db;
    }

    /**
     * Create server connection from config.
     *
     * @param array<string, mixed> $config Database configuration
     *
     * @return PdoDb Server connection
     */
    public static function createServerConnection(array $config): PdoDb
    {
        $driver = $config['driver'] ?? 'mysql';
        $normalizedConfig = $config;

        // Normalize database name key
        if (isset($normalizedConfig['database'])) {
            $normalizedConfig['dbname'] = $normalizedConfig['database'];
            unset($normalizedConfig['database']);
        }

        return new PdoDb($driver, $normalizedConfig);
    }
}
