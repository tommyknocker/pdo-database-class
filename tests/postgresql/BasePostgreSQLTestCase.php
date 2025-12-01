<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

abstract class BasePostgreSQLTestCase extends TestCase
{
    protected static PdoDb $db;
    protected const string DB_HOST = 'localhost';
    protected const string DB_NAME = 'testdb';
    protected const string DB_USER = 'testuser';
    protected const string DB_PASSWORD = 'testpass';
    protected const int DB_PORT = 5433;

    public static function setUpBeforeClass(): void
    {
        /*
         * sudo -i -u postgres
         * psql
         * CREATE USER testuser WITH PASSWORD 'testpass';
         * CREATE DATABASE testdb OWNER testuser;
         * GRANT ALL PRIVILEGES ON DATABASE testdb TO testuser;
         * GRANT pg_read_server_files TO testuser;
         * GRANT pg_write_server_files TO testuser;
         * \q
         */
        self::$db = new PdoDb(
            'pgsql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
            ]
        );

        self::$db->rawQuery('DROP TABLE IF EXISTS archive_users');
        self::$db->rawQuery('DROP TABLE IF EXISTS orders');
        self::$db->rawQuery('DROP TABLE IF EXISTS users');

        // users
        self::$db->rawQuery('
            CREATE TABLE users (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                company VARCHAR(100),
                age INT,
                status VARCHAR(20),
                is_active BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NULL,
                UNIQUE (name)
            );
        ');

        // orders
        self::$db->rawQuery('
             CREATE TABLE orders (
                id SERIAL PRIMARY KEY,
                user_id INT NOT NULL,
                amount NUMERIC(10,2) NOT NULL,
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
            );
        ');

        // archive_users
        self::$db->rawQuery('
            CREATE TABLE archive_users (
                id SERIAL PRIMARY KEY,
                user_id INT
            );
        ');
    }

    public function setUp(): void
    {
        self::$db->rawQuery('TRUNCATE TABLE orders, archive_users, users RESTART IDENTITY CASCADE');
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
