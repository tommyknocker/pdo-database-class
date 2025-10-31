<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

abstract class BaseSqliteTestCase extends TestCase
{
    protected static PdoDb $db;

    public function setUp(): void
    {
        self::$db = new PdoDb('sqlite', ['path' => ':memory:']);
        self::$db->rawQuery('CREATE TABLE IF NOT EXISTS users (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT,
              company TEXT,
              age INTEGER,
              status TEXT DEFAULT NULL,
              is_active INTEGER NOT NULL DEFAULT 0,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT NULL,
              UNIQUE(name)
        );');

        self::$db->rawQuery('CREATE TABLE IF NOT EXISTS orders (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL,
              amount NUMERIC(10,2) NOT NULL,
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        );');

        self::$db->rawQuery('
        CREATE TABLE IF NOT EXISTS archive_users (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          user_id INTEGER
        );');
        
        parent::setUp();
    }
}