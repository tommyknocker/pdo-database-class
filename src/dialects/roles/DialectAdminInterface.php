<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\PdoDb;

/**
 * Database and user management.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectAdminInterface
{
    /**
     * Create a database.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If database creation fails
     */
    public function createDatabase(string $databaseName, PdoDb $db): bool;

    /**
     * Drop a database.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If database deletion fails
     */
    public function dropDatabase(string $databaseName, PdoDb $db): bool;

    /**
     * Check if a database exists.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance
     *
     * @return bool True if database exists
     */
    public function databaseExists(string $databaseName, PdoDb $db): bool;

    /**
     * List all databases.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, string> List of database names
     * @throws ResourceException If listing fails
     */
    public function listDatabases(PdoDb $db): array;

    /**
     * Get database-specific information.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> Database information
     */
    public function getDatabaseInfo(PdoDb $db): array;

    /**
     * Create a database user.
     *
     * @param string $username Username
     * @param string $password Password
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If user creation fails or not supported
     */
    public function createUser(string $username, string $password, ?string $host, PdoDb $db): bool;

    /**
     * Drop a database user.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If user deletion fails or not supported
     */
    public function dropUser(string $username, ?string $host, PdoDb $db): bool;

    /**
     * Check if a database user exists.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True if user exists
     * @throws ResourceException If check fails or not supported
     */
    public function userExists(string $username, ?string $host, PdoDb $db): bool;

    /**
     * List all database users.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, array<string, mixed>> List of users with their information
     * @throws ResourceException If listing fails or not supported
     */
    public function listUsers(PdoDb $db): array;

    /**
     * Get user information and privileges.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> User information
     * @throws ResourceException If retrieval fails or not supported
     */
    public function getUserInfo(string $username, ?string $host, PdoDb $db): array;

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
     * @throws ResourceException If grant fails or not supported
     */
    public function grantPrivileges(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        PdoDb $db
    ): bool;

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
     * @throws ResourceException If revoke fails or not supported
     */
    public function revokePrivileges(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        PdoDb $db
    ): bool;

    /**
     * Change user password.
     *
     * @param string $username Username
     * @param string $newPassword New password
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If password change fails or not supported
     */
    public function changeUserPassword(string $username, string $newPassword, ?string $host, PdoDb $db): bool;
}
