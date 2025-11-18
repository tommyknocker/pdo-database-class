<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

abstract class BaseMySQLTestCase extends TestCase
{
    protected static PdoDb $db;

    protected const string DB_HOST = '127.0.0.1';
    protected const string DB_NAME = 'testdb';
    protected const string DB_USER = 'testuser';
    protected const string DB_PASSWORD = 'testpass';
    protected const int DB_PORT = 3306;
    protected const string DB_CHARSET = 'utf8mb4';

    public static function setUpBeforeClass(): void
    {
        /*
         * CREATE DATABASE testdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
         * CREATE USER 'testuser'@'127.0.0.1' IDENTIFIED BY 'testpass';
         * GRANT ALL PRIVILEGES ON testdb.* TO 'testuser'@'127.0.0.1';
         * FLUSH PRIVILEGES;
         */
        self::$db = new PdoDb(
            'mysql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
                'charset' => self::DB_CHARSET,
            ]
        );

        // Drop tables in correct order (child tables first, then parent tables)
        // Disable foreign key checks to avoid constraint violations
        self::$db->rawQuery('SET FOREIGN_KEY_CHECKS=0');
        self::$db->rawQuery('DROP TABLE IF EXISTS archive_users');
        self::$db->rawQuery('DROP TABLE IF EXISTS orders');
        self::$db->rawQuery('DROP TABLE IF EXISTS users');
        self::$db->rawQuery('SET FOREIGN_KEY_CHECKS=1');

        self::$db->rawQuery('CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            company VARCHAR(100),
            age INT,
            status VARCHAR(20) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT NULL,
            UNIQUE KEY uniq_name (name)
        ) ENGINE=InnoDB');

        self::$db->rawQuery('CREATE TABLE orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB;');

        self::$db->rawQuery('
        CREATE TABLE archive_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT
        )
    ');
    }

    public function setUp(): void
    {
        self::$db->rawQuery('SET FOREIGN_KEY_CHECKS=0');
        self::$db->rawQuery('TRUNCATE TABLE orders');
        self::$db->rawQuery('TRUNCATE TABLE users');
        self::$db->rawQuery('TRUNCATE TABLE archive_users');
        self::$db->rawQuery('SET FOREIGN_KEY_CHECKS=1');
        parent::setUp();
    }
}
