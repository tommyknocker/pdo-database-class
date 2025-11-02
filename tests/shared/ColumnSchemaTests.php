<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Tests for ColumnSchema class.
 */
class ColumnSchemaTests extends BaseSharedTestCase
{
    /**
     * Test ColumnSchema constructor and basic getters.
     */
    public function testColumnSchemaConstructor(): void
    {
        $schema = new ColumnSchema('VARCHAR', 255);
        $this->assertEquals('VARCHAR', $schema->getType());
        $this->assertEquals(255, $schema->getLength());
        $this->assertNull($schema->getScale());

        $schema2 = new ColumnSchema('DECIMAL', 10, 2);
        $this->assertEquals('DECIMAL', $schema2->getType());
        $this->assertEquals(10, $schema2->getLength());
        $this->assertEquals(2, $schema2->getScale());
    }

    /**
     * Test notNull and null methods.
     */
    public function testNotNullAndNull(): void
    {
        $schema = new ColumnSchema('INT');
        $this->assertFalse($schema->isNotNull());

        $schema->notNull();
        $this->assertTrue($schema->isNotNull());

        $schema->null();
        $this->assertFalse($schema->isNotNull());
    }

    /**
     * Test defaultValue method.
     */
    public function testDefaultValue(): void
    {
        $schema = new ColumnSchema('VARCHAR', 100);
        $schema->defaultValue('default text');
        $this->assertEquals('default text', $schema->getDefaultValue());
        $this->assertFalse($schema->isDefaultExpression());
    }

    /**
     * Test defaultExpression method.
     */
    public function testDefaultExpression(): void
    {
        $schema = new ColumnSchema('TIMESTAMP');
        $schema->defaultExpression('CURRENT_TIMESTAMP');
        $this->assertEquals('CURRENT_TIMESTAMP', $schema->getDefaultValue());
        $this->assertTrue($schema->isDefaultExpression());
    }

    /**
     * Test comment method.
     */
    public function testComment(): void
    {
        $schema = new ColumnSchema('INT');
        $this->assertNull($schema->getComment());

        $schema->comment('User ID');
        $this->assertEquals('User ID', $schema->getComment());
    }

    /**
     * Test unsigned method.
     */
    public function testUnsigned(): void
    {
        $schema = new ColumnSchema('INT');
        $this->assertFalse($schema->isUnsigned());

        $schema->unsigned();
        $this->assertTrue($schema->isUnsigned());
    }

    /**
     * Test autoIncrement method.
     */
    public function testAutoIncrement(): void
    {
        $schema = new ColumnSchema('INT');
        $this->assertFalse($schema->isAutoIncrement());

        $schema->autoIncrement();
        $this->assertTrue($schema->isAutoIncrement());
    }

    /**
     * Test unique method.
     */
    public function testUnique(): void
    {
        $schema = new ColumnSchema('VARCHAR', 100);
        $this->assertFalse($schema->isUnique());

        $schema->unique();
        $this->assertTrue($schema->isUnique());
    }

    /**
     * Test after method.
     */
    public function testAfter(): void
    {
        $schema = new ColumnSchema('VARCHAR', 100);
        $this->assertNull($schema->getAfter());
        $this->assertFalse($schema->isFirst());

        $schema->after('id');
        $this->assertEquals('id', $schema->getAfter());
        $this->assertFalse($schema->isFirst());
    }

    /**
     * Test first method.
     */
    public function testFirst(): void
    {
        $schema = new ColumnSchema('INT');
        $this->assertFalse($schema->isFirst());
        $this->assertNull($schema->getAfter());

        $schema->first();
        $this->assertTrue($schema->isFirst());
        $this->assertNull($schema->getAfter());
    }

    /**
     * Test that first() clears after().
     */
    public function testFirstClearsAfter(): void
    {
        $schema = new ColumnSchema('VARCHAR', 100);
        $schema->after('id');
        $this->assertEquals('id', $schema->getAfter());

        $schema->first();
        $this->assertTrue($schema->isFirst());
        $this->assertNull($schema->getAfter());
    }

    /**
     * Test that after() clears first().
     */
    public function testAfterClearsFirst(): void
    {
        $schema = new ColumnSchema('INT');
        $schema->first();
        $this->assertTrue($schema->isFirst());

        $schema->after('id');
        $this->assertFalse($schema->isFirst());
        $this->assertEquals('id', $schema->getAfter());
    }

    /**
     * Test fluent interface chaining.
     */
    public function testFluentInterface(): void
    {
        $schema = (new ColumnSchema('VARCHAR', 255))
            ->notNull()
            ->defaultValue('test')
            ->comment('Test column')
            ->unique();

        $this->assertEquals('VARCHAR', $schema->getType());
        $this->assertTrue($schema->isNotNull());
        $this->assertEquals('test', $schema->getDefaultValue());
        $this->assertEquals('Test column', $schema->getComment());
        $this->assertTrue($schema->isUnique());
    }
}
