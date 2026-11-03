<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

abstract class BaseMSSQLTestCase extends TestCase
{
    protected static PdoDb $db;

    protected const string DB_HOST = 'localhost';
    protected const string DB_NAME = 'testdb';
    protected const string DB_USER = 'testuser';
    protected const string DB_PASSWORD = 'testpass';
    protected const int DB_PORT = 1433;

    /**
     * Server the suite runs against. PDODB_MSSQL_TEST_HOST / PDODB_MSSQL_TEST_PORT select another one, for example a
     * SQL Server 2025 container on 1434; they have their own names because the tests set and clear PDODB_HOST and
     * PDODB_PORT for the CLI commands they run.
     */
    protected static function host(): string
    {
        $host = getenv('PDODB_MSSQL_TEST_HOST');

        return $host === false || $host === '' ? self::DB_HOST : $host;
    }

    protected static function port(): int
    {
        $port = getenv('PDODB_MSSQL_TEST_PORT');

        return $port === false || $port === '' ? self::DB_PORT : (int)$port;
    }

    public static function setUpBeforeClass(): void
    {
        /*
         * SQL Server setup:
         * CREATE DATABASE testdb;
         * CREATE LOGIN testuser WITH PASSWORD = 'testpass', CHECK_POLICY = OFF;
         * USE testdb;
         * CREATE USER testuser FOR LOGIN testuser;
         * ALTER ROLE db_owner ADD MEMBER testuser;
         *
         * Or use SA user for CI/testing:
         * PDODB_USERNAME=sa PDODB_PASSWORD=Test123!@#
         */
        // Use environment variables if set (for CI), otherwise use constants
        $username = getenv('PDODB_USERNAME');
        if ($username === false || $username === '') {
            $username = self::DB_USER;
        }
        $password = getenv('PDODB_PASSWORD');
        if ($password === false || $password === '') {
            $password = self::DB_PASSWORD;
        }

        self::$db = new PdoDb(
            'sqlsrv',
            [
                'host' => self::host(),
                'port' => self::port(),
                'username' => $username,
                'password' => $password,
                'dbname' => self::DB_NAME,
                'trust_server_certificate' => true,
                'encrypt' => true,
            ]
        );

        $connection = self::$db->connection;
        assert($connection !== null);

        // Drop constraints first, then tables
        $connection->query('IF OBJECT_ID(\'uniq_name\', \'UQ\') IS NOT NULL BEGIN DECLARE @tableName NVARCHAR(128); SELECT @tableName = OBJECT_SCHEMA_NAME(parent_object_id) + \'.\' + OBJECT_NAME(parent_object_id) FROM sys.objects WHERE name = \'uniq_name\' AND type = \'UQ\'; EXEC(\'ALTER TABLE \' + @tableName + \' DROP CONSTRAINT uniq_name\'); END');
        $connection->query('IF OBJECT_ID(\'archive_users\', \'U\') IS NOT NULL DROP TABLE archive_users');
        $connection->query('IF OBJECT_ID(\'orders\', \'U\') IS NOT NULL DROP TABLE orders');
        $connection->query('IF OBJECT_ID(\'users\', \'U\') IS NOT NULL DROP TABLE users');

        // users table with MSSQL syntax
        $connection->query('
            CREATE TABLE users (
                id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(100),
                company NVARCHAR(100),
                age INT,
                status NVARCHAR(20) DEFAULT NULL,
                is_active BIT NOT NULL DEFAULT 0,
                created_at DATETIME2 DEFAULT GETDATE(),
                updated_at DATETIME2 DEFAULT NULL,
                CONSTRAINT uniq_name UNIQUE (name)
            )
        ');

        // orders table
        $connection->query('
            CREATE TABLE orders (
                id INT IDENTITY(1,1) PRIMARY KEY,
                user_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
            )
        ');

        // archive_users table
        $connection->query('
            CREATE TABLE archive_users (
                id INT IDENTITY(1,1) PRIMARY KEY,
                user_id INT
            )
        ');
    }

    public function setUp(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);

        // MSSQL doesn't support TRUNCATE with CASCADE, so delete in order
        $connection->query('DELETE FROM orders');
        $connection->query('DELETE FROM archive_users');
        $connection->query('DELETE FROM users');
        // Reset IDENTITY seed. RESEED 0 on a table that never had a row makes its first id 0, so only reseed used tables
        $connection->query('IF (SELECT last_value FROM sys.identity_columns WHERE object_id = OBJECT_ID(\'users\')) IS NOT NULL DBCC CHECKIDENT(\'users\', RESEED, 0)')?->closeCursor();
        $connection->query('IF (SELECT last_value FROM sys.identity_columns WHERE object_id = OBJECT_ID(\'orders\')) IS NOT NULL DBCC CHECKIDENT(\'orders\', RESEED, 0)')?->closeCursor();
        $connection->query('IF (SELECT last_value FROM sys.identity_columns WHERE object_id = OBJECT_ID(\'archive_users\')) IS NOT NULL DBCC CHECKIDENT(\'archive_users\', RESEED, 0)')?->closeCursor();
        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        // Explicitly close connection to avoid conflicts with subsequent test suites
        if (isset(self::$db)) {
            try {
                self::$db->disconnect();
            } catch (\Throwable) {
                // Ignore errors during cleanup
            }
        }
        parent::tearDownAfterClass();
    }
}
