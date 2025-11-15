<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\PdoDb;

/**
 * Database manager for CLI operations.
 *
 * Provides methods for creating, dropping, listing, and checking database existence.
 */
class DatabaseManager extends BaseCliCommand
{
    /**
     * Create a database.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance (connected to server, not specific database)
     *
     * @return bool True on success
     * @throws ResourceException If database creation fails
     */
    public static function create(string $databaseName, PdoDb $db): bool
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->createDatabase($databaseName, $db);
    }

    /**
     * Drop a database.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance (connected to server, not specific database)
     *
     * @return bool True on success
     * @throws ResourceException If database deletion fails
     */
    public static function drop(string $databaseName, PdoDb $db): bool
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->dropDatabase($databaseName, $db);
    }

    /**
     * Check if a database exists.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance (connected to server, not specific database)
     *
     * @return bool True if database exists
     */
    public static function exists(string $databaseName, PdoDb $db): bool
    {
        $dialect = $db->schema()->getDialect();

        try {
            return $dialect->databaseExists($databaseName, $db);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * List all databases.
     *
     * @param PdoDb $db Database instance (connected to server, not specific database)
     *
     * @return array<int, string> List of database names
     * @throws ResourceException If listing fails
     */
    public static function list(PdoDb $db): array
    {
        $dialect = $db->schema()->getDialect();
        return $dialect->listDatabases($db);
    }

    /**
     * Get database information.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> Database information
     */
    public static function getInfo(PdoDb $db): array
    {
        $driver = static::getDriverName($db);
        $dialect = $db->schema()->getDialect();

        $info = [
            'driver' => $driver,
        ];

        // Get database-specific info from dialect
        try {
            $dbInfo = $dialect->getDatabaseInfo($db);
            $info = array_merge($info, $dbInfo);
        } catch (\Exception $e) {
            // Ignore errors, return basic info
        }

        return $info;
    }

    /**
     * Create a server connection from an existing PdoDb instance.
     * Uses the existing connection directly, as CREATE/DROP DATABASE are server-level commands
     * that work from any database connection.
     *
     * @param PdoDb $db Existing database instance
     *
     * @return PdoDb Same connection instance (server-level commands work from any DB)
     */
    public static function createServerConnectionFromDb(PdoDb $db): PdoDb
    {
        // CREATE/DROP DATABASE are server-level commands that work from any database connection
        // No need to create a new connection - just use the existing one
        return $db;
    }

    /**
     * Create a database connection without specifying a database.
     *
     * @param array<string, mixed> $config Original config
     *
     * @return PdoDb Database instance
     */
    public static function createServerConnection(array $config): PdoDb
    {
        $driver = $config['driver'] ?? 'mysql';
        $serverConfig = $config;
        unset($serverConfig['driver']);

        // Normalize database name key
        if (!isset($serverConfig['dbname']) && isset($serverConfig['database'])) {
            $serverConfig['dbname'] = $serverConfig['database'];
            unset($serverConfig['database']);
        }

        return new PdoDb($driver, $serverConfig);
    }
}
