<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectAdminInterface;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\PdoDb;

/**
 * Default implementation of the database and user management part of the dialect ({@see DialectAdminInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait AdminDefaults
{
    /**
     * {@inheritDoc}
     */
    public function createDatabase(string $databaseName, PdoDb $db): bool
    {
        $sql = $this->buildCreateDatabaseSql($databaseName);
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropDatabase(string $databaseName, PdoDb $db): bool
    {
        $sql = $this->buildDropDatabaseSql($databaseName);
        $db->rawQuery($sql);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function databaseExists(string $databaseName, PdoDb $db): bool
    {
        $databases = $this->listDatabases($db);
        return in_array($databaseName, $databases, true);
    }

    /**
     * {@inheritDoc}
     */
    public function listDatabases(PdoDb $db): array
    {
        $sql = $this->buildListDatabasesSql();
        $result = $db->rawQuery($sql);
        return $this->extractDatabaseNames($result);
    }

    /**
     * {@inheritDoc}
     */
    public function getDatabaseInfo(PdoDb $db): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(string $username, ?string $host, PdoDb $db): bool
    {
        throw new ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function userExists(string $username, ?string $host, PdoDb $db): bool
    {
        throw new ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listUsers(PdoDb $db): array
    {
        throw new ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(string $username, ?string $host, PdoDb $db): array
    {
        throw new ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function grantPrivileges(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        PdoDb $db
    ): bool {
        throw new ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function revokePrivileges(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        PdoDb $db
    ): bool {
        throw new ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function changeUserPassword(string $username, string $newPassword, ?string $host, PdoDb $db): bool
    {
        throw new ResourceException(
            'User management is not supported for ' . $this->getDriverName()
        );
    }
}
