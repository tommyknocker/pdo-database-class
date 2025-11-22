<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

/**
 * Tests for ExternalReferenceProcessingTrait.
 */
class ExternalReferenceProcessingTraitTests extends BaseSharedTestCase
{
    /**
     * Set up test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Clean up any existing test tables
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS users');
        $db->rawQuery('DROP TABLE IF EXISTS orders');
        $db->rawQuery('DROP TABLE IF EXISTS products');
    }

    /**
     * Test external reference processing through WHERE EXISTS.
     */
    public function testExternalReferenceInWhereExists(): void
    {
        $db = self::$db;

        // Create test tables
        $db->schema()->createTable('users', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $db->schema()->createTable('orders', [
            'id' => $db->schema()->primaryKey(),
            'user_id' => $db->schema()->integer(),
            'amount' => $db->schema()->decimal(10, 2),
        ]);

        // Insert test data
        $db->find()->table('users')->insert(['name' => 'John']);
        $db->find()->table('users')->insert(['name' => 'Jane']);
        $db->find()->table('orders')->insert(['user_id' => 1, 'amount' => 100]);

        // Test WHERE EXISTS with external reference - 'users.id' is external to orders subquery
        $result = $db->find()
            ->from('users')
            ->whereExists(function ($query) {
                $query->from('orders')
                    ->where('user_id', 'users.id'); // External reference
            })
            ->get();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['name']);
    }

    /**
     * Test external reference processing through WHERE NOT EXISTS.
     */
    public function testExternalReferenceInWhereNotExists(): void
    {
        $db = self::$db;

        // Create test tables
        $db->schema()->createTable('users', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $db->schema()->createTable('orders', [
            'id' => $db->schema()->primaryKey(),
            'user_id' => $db->schema()->integer(),
        ]);

        // Insert test data
        $db->find()->table('users')->insert(['name' => 'John']);
        $db->find()->table('users')->insert(['name' => 'Jane']);
        $db->find()->table('orders')->insert(['user_id' => 1]);

        // Test WHERE NOT EXISTS with external reference
        $result = $db->find()
            ->from('users')
            ->whereNotExists(function ($query) {
                $query->from('orders')
                    ->where('user_id', 'users.id'); // External reference
            })
            ->get();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('Jane', $result[0]['name']);
    }

    /**
     * Test processExternalReferences through actual WHERE EXISTS usage.
     */
    public function testProcessExternalReferencesThroughWhereExists(): void
    {
        $db = self::$db;

        // Create test tables
        $db->schema()->createTable('users', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $db->schema()->createTable('orders', [
            'id' => $db->schema()->primaryKey(),
            'user_id' => $db->schema()->integer(),
        ]);

        // Insert test data
        $db->find()->table('users')->insert(['name' => 'John']);
        $db->find()->table('orders')->insert(['user_id' => 1]);

        // Test WHERE EXISTS with external reference - this uses processExternalReferences internally
        $result = $db->find()
            ->from('users')
            ->whereExists(function ($query) {
                $query->from('orders')
                    ->where('user_id', 'users.id'); // External reference
            })
            ->get();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }
}
