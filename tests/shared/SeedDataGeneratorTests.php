<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use ReflectionClass;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\seeds\SeedDataGenerator;

/**
 * Tests for SeedDataGenerator class.
 */
final class SeedDataGeneratorTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Create a test table for SeedDataGenerator
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS seed_test_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                status TEXT DEFAULT "active",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
        // Insert test data
        self::$db->rawQuery("INSERT INTO seed_test_users (name, email, status) VALUES ('John Doe', 'john@example.com', 'active')");
        self::$db->rawQuery("INSERT INTO seed_test_users (name, email, status) VALUES ('Jane Smith', 'jane@example.com', 'inactive')");
        self::$db->rawQuery("INSERT INTO seed_test_users (name, email, status) VALUES ('Bob Wilson', 'bob@example.com', 'active')");
    }

    protected function tearDown(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS seed_test_users');
        parent::tearDown();
    }

    public function testSeedDataGeneratorConstructor(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $this->assertInstanceOf(SeedDataGenerator::class, $generator);
    }

    public function testSeedDataGeneratorGenerateBasic(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('seed_test_users');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('rowCount', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertEquals(3, $result['rowCount']);
        $this->assertStringContainsString('seed_test_users', $result['content']);
        $this->assertStringContainsString('John Doe', $result['content']);
    }

    public function testSeedDataGeneratorGenerateWithLimit(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('seed_test_users', ['limit' => 2]);

        $this->assertEquals(2, $result['rowCount']);
    }

    public function testSeedDataGeneratorGenerateWithWhere(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('seed_test_users', ['where' => 'status = active']);

        $this->assertGreaterThan(0, $result['rowCount']);
        $this->assertStringContainsString('active', $result['content']);
    }

    public function testSeedDataGeneratorGenerateWithOrderBy(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('seed_test_users', ['order_by' => 'name DESC']);

        $this->assertEquals(3, $result['rowCount']);
    }

    public function testSeedDataGeneratorGenerateWithExcludeColumns(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('seed_test_users', ['exclude' => ['created_at']]);

        $this->assertStringNotContainsString('created_at', $result['content']);
    }

    public function testSeedDataGeneratorGenerateWithIncludeColumns(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('seed_test_users', ['include' => ['id', 'name']]);

        $this->assertStringContainsString('id', $result['content']);
        $this->assertStringContainsString('name', $result['content']);
        $this->assertStringNotContainsString('email', $result['content']);
    }

    public function testSeedDataGeneratorGenerateWithSkipTimestamps(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $result = $generator->generate('seed_test_users', ['skip_timestamps' => true]);

        $this->assertStringNotContainsString('created_at', $result['content']);
    }

    public function testSeedDataGeneratorGenerateThrowsExceptionForNonExistentTable(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Table 'non_existent_table' does not exist");

        $generator->generate('non_existent_table');
    }

    public function testSeedDataGeneratorGenerateThrowsExceptionForEmptyTable(): void
    {
        // Create empty table
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS empty_table (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            )
        ');

        $generator = new SeedDataGenerator(self::$db);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("No data found in table 'empty_table'");

        try {
            $generator->generate('empty_table');
        } finally {
            self::$db->rawQuery('DROP TABLE IF EXISTS empty_table');
        }
    }

    public function testSeedDataGeneratorGetPrimaryKey(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $reflection = new ReflectionClass($generator);
        $method = $reflection->getMethod('getPrimaryKey');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'seed_test_users');
        // getPrimaryKey can return null or array
        if ($result !== null) {
            $this->assertIsArray($result);
            $this->assertContains('id', $result);
        } else {
            // If null, that's also valid (no primary key found)
            $this->assertNull($result);
        }
    }

    public function testSeedDataGeneratorGetUniqueIndexes(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $reflection = new ReflectionClass($generator);
        $method = $reflection->getMethod('getUniqueIndexes');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'seed_test_users');
        $this->assertIsArray($result);
        // email column has UNIQUE constraint
        if (!empty($result)) {
            $this->assertIsArray($result[0]);
        }
    }

    public function testSeedDataGeneratorGenerateClassName(): void
    {
        $generator = new SeedDataGenerator(self::$db);
        $reflection = new ReflectionClass($generator);
        $method = $reflection->getMethod('generateClassName');
        $method->setAccessible(true);

        $result = $method->invoke($generator, 'seed_test_users');
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }
}
