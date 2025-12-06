<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use PHPUnit\Framework\TestCase;
use Throwable;
use tommyknocker\pdodb\PdoDb;

abstract class BaseOracleTestCase extends TestCase
{
    protected static PdoDb $db;
    protected const DB_HOST = 'localhost';
    protected const DB_SERVICE_NAME = 'XEPDB1';
    protected const DB_USER = 'testuser';
    protected const DB_PASSWORD = 'testpass';
    protected const DB_PORT = 1521;
    protected const DB_CHARSET = 'AL32UTF8';

    public static function setUpBeforeClass(): void
    {
        /*
         * Oracle setup:
         * CREATE USER testuser IDENTIFIED BY testpass;
         * GRANT CONNECT, RESOURCE TO testuser;
         * ALTER USER testuser QUOTA UNLIMITED ON USERS;
         */
        // Use environment variables if available, otherwise use defaults
        // Oracle XE 21c uses XEPDB1 as PDB service name
        $host = getenv('PDODB_HOST') ?: self::DB_HOST;
        $port = (int)(getenv('PDODB_PORT') ?: (string)self::DB_PORT);
        $user = getenv('PDODB_USERNAME') ?: self::DB_USER;
        $password = getenv('PDODB_PASSWORD') ?: self::DB_PASSWORD;
        $serviceName = getenv('PDODB_SERVICE_NAME') ?: getenv('PDODB_SID') ?: self::DB_SERVICE_NAME;
        $charset = getenv('PDODB_CHARSET') ?: self::DB_CHARSET;

        self::$db = new PdoDb(
            'oci',
            [
                'host' => $host,
                'port' => $port,
                'username' => $user,
                'password' => $password,
                'service_name' => $serviceName,
                'charset' => $charset,
            ]
        );

        $connection = self::$db->connection;

        // Drop triggers first (they depend on tables and sequences)
        try {
            $connection->query('DROP TRIGGER archive_users_trigger');
        } catch (Throwable) {
            // Trigger doesn't exist, continue
        }

        try {
            $connection->query('DROP TRIGGER orders_trigger');
        } catch (Throwable) {
            // Trigger doesn't exist, continue
        }

        try {
            $connection->query('DROP TRIGGER users_trigger');
        } catch (Throwable) {
            // Trigger doesn't exist, continue
        }

        // Drop tables in correct order (child tables first, then parent tables)
        // Oracle doesn't support DROP TABLE IF EXISTS, so we use try-catch
        // Use uppercase quoted names to match CREATE TABLE statements
        try {
            $connection->query('DROP TABLE "ARCHIVE_USERS" CASCADE CONSTRAINTS');
        } catch (Throwable) {
            // Table doesn't exist, continue
        }

        try {
            $connection->query('DROP TABLE "ORDERS" CASCADE CONSTRAINTS');
        } catch (Throwable) {
            // Table doesn't exist, continue
        }

        try {
            $connection->query('DROP TABLE "USERS" CASCADE CONSTRAINTS');
        } catch (Throwable) {
            // Table doesn't exist, continue
        }

        // Drop sequences if they exist (after tables are dropped)
        try {
            $connection->query('DROP SEQUENCE archive_users_seq');
        } catch (Throwable) {
            // Sequence doesn't exist, continue
        }

        try {
            $connection->query('DROP SEQUENCE orders_seq');
        } catch (Throwable) {
            // Sequence doesn't exist, continue
        }

        try {
            $connection->query('DROP SEQUENCE users_seq');
        } catch (Throwable) {
            // Sequence doesn't exist, continue
        }

        // users table (must be created before triggers and foreign keys)
        // Use uppercase quoted names to match Oracle's default behavior and test expectations
        $connection->query('CREATE TABLE "USERS" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100) NOT NULL, "COMPANY" VARCHAR2(100), "AGE" NUMBER, "STATUS" VARCHAR2(20), "IS_ACTIVE" NUMBER(1) DEFAULT 0 NOT NULL, "CREATED_AT" TIMESTAMP DEFAULT SYSTIMESTAMP, "UPDATED_AT" TIMESTAMP, CONSTRAINT uniq_name UNIQUE ("NAME"))');

        // orders table
        $connection->query('CREATE TABLE "ORDERS" ("ID" NUMBER PRIMARY KEY, "USER_ID" NUMBER NOT NULL, "AMOUNT" NUMBER(10,2) NOT NULL, CONSTRAINT fk_orders_user FOREIGN KEY ("USER_ID") REFERENCES "USERS"("ID") ON DELETE CASCADE)');

        // archive_users table
        $connection->query('CREATE TABLE "ARCHIVE_USERS" ("ID" NUMBER PRIMARY KEY, "USER_ID" NUMBER)');

        // Create sequences for auto-increment (after tables are created)
        // Sequences were already dropped above, so create them fresh
        $connection->query('CREATE SEQUENCE users_seq START WITH 1 INCREMENT BY 1');
        $connection->query('CREATE SEQUENCE orders_seq START WITH 1 INCREMENT BY 1');
        $connection->query('CREATE SEQUENCE archive_users_seq START WITH 1 INCREMENT BY 1');

        // Create triggers for auto-increment (after sequences are created)
        $connection->query('CREATE OR REPLACE TRIGGER users_trigger BEFORE INSERT ON "USERS" FOR EACH ROW BEGIN IF :NEW."ID" IS NULL THEN SELECT users_seq.NEXTVAL INTO :NEW."ID" FROM DUAL; END IF; END;');

        $connection->query('CREATE OR REPLACE TRIGGER orders_trigger BEFORE INSERT ON "ORDERS" FOR EACH ROW BEGIN IF :NEW."ID" IS NULL THEN SELECT orders_seq.NEXTVAL INTO :NEW."ID" FROM DUAL; END IF; END;');

        $connection->query('CREATE OR REPLACE TRIGGER archive_users_trigger BEFORE INSERT ON "ARCHIVE_USERS" FOR EACH ROW BEGIN IF :NEW."ID" IS NULL THEN SELECT archive_users_seq.NEXTVAL INTO :NEW."ID" FROM DUAL; END IF; END;');
    }

    public function setUp(): void
    {
        parent::setUp();
        $connection = self::$db->connection;
        $connection->query('TRUNCATE TABLE "ORDERS"');
        $connection->query('TRUNCATE TABLE "ARCHIVE_USERS"');
        $connection->query('TRUNCATE TABLE "USERS"');

        // Reset sequences to start from 1
        // Note: Oracle doesn't have ALTER SEQUENCE RESTART, so we drop and recreate
        try {
            $connection->query('DROP SEQUENCE users_seq');
            $connection->query('DROP SEQUENCE orders_seq');
            $connection->query('DROP SEQUENCE archive_users_seq');
        } catch (Throwable) {
            // Sequences might not exist, continue
        }
        $connection->query('CREATE SEQUENCE users_seq START WITH 1 INCREMENT BY 1');
        $connection->query('CREATE SEQUENCE orders_seq START WITH 1 INCREMENT BY 1');
        $connection->query('CREATE SEQUENCE archive_users_seq START WITH 1 INCREMENT BY 1');
    }

    public static function tearDownAfterClass(): void
    {
        // Explicitly close connection to avoid conflicts with subsequent test suites
        if (isset(self::$db)) {
            try {
                self::$db->disconnect();
            } catch (Throwable) {
                // Ignore errors during cleanup
            }
        }
        parent::tearDownAfterClass();
    }
}
