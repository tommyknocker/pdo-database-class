<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use ReflectionClass;
use tommyknocker\pdodb\cli\TestGenerator;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Tests for TestGenerator class.
 */
final class TestGeneratorTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Create a test table for TestGenerator
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE
            )
        ');
    }

    public function testTestGeneratorModelNameToTableName(): void
    {
        $reflection = new ReflectionClass(TestGenerator::class);
        $method = $reflection->getMethod('modelNameToTableName');
        $method->setAccessible(true);

        $this->assertEquals('users', $method->invoke(null, 'User'));
        $this->assertEquals('test_models', $method->invoke(null, 'TestModel'));
        $this->assertEquals('user_profiles', $method->invoke(null, 'UserProfile'));
    }

    public function testTestGeneratorTableNameToClassName(): void
    {
        $reflection = new ReflectionClass(TestGenerator::class);
        $method = $reflection->getMethod('tableNameToClassName');
        $method->setAccessible(true);

        $this->assertEquals('User', $method->invoke(null, 'users'));
        $this->assertEquals('TestModel', $method->invoke(null, 'test_models'));
        $this->assertEquals('UserProfile', $method->invoke(null, 'user_profiles'));
    }

    public function testTestGeneratorDetectPrimaryKey(): void
    {
        $reflection = new ReflectionClass(TestGenerator::class);
        $method = $reflection->getMethod('detectPrimaryKey');
        $method->setAccessible(true);

        $result = $method->invoke(null, self::$db, 'test_users');
        $this->assertIsArray($result);
        $this->assertContains('id', $result);
    }

    public function testTestGeneratorGenerateWithTableName(): void
    {
        $tempDir = sys_get_temp_dir() . '/pdodb_test_generator_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            putenv('PHPUNIT=1'); // Suppress output
            $result = TestGenerator::generate(
                modelName: null,
                tableName: 'test_users',
                repositoryName: null,
                type: 'unit',
                namespace: 'tests\\unit',
                outputPath: $tempDir,
                db: self::$db,
                force: true
            );
            $this->assertIsString($result);
            $this->assertFileExists($result);
            // TestGenerator converts table name 'test_users' to class name 'TestUser' (removes 's' suffix)
            $this->assertStringEndsWith('TestUserTest.php', $result);
        } finally {
            putenv('PHPUNIT');
            if (file_exists($tempDir)) {
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);
            }
        }
    }

    public function testTestGeneratorGenerateThrowsExceptionForNonExistentTable(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Table 'non_existent_table' does not exist");

        putenv('PHPUNIT=1'); // Suppress output

        try {
            TestGenerator::generate(
                modelName: null,
                tableName: 'non_existent_table',
                repositoryName: null,
                type: 'unit',
                namespace: 'tests\\unit',
                outputPath: sys_get_temp_dir(),
                db: self::$db,
                force: true
            );
        } finally {
            putenv('PHPUNIT');
        }
    }
}
