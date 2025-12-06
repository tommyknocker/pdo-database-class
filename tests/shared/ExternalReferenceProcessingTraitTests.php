<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use ReflectionClass;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\ConditionBuilder;
use tommyknocker\pdodb\query\ExecutionEngine;
use tommyknocker\pdodb\query\ParameterManager;
use tommyknocker\pdodb\query\RawValueResolver;

/**
 * Tests for ExternalReferenceProcessingTrait.
 */
final class ExternalReferenceProcessingTraitTests extends BaseSharedTestCase
{
    protected function createConditionBuilder(): ConditionBuilder
    {
        $connection = self::$db->connection;
        $executionEngine = new ExecutionEngine(
            $connection,
            new RawValueResolver($connection, new ParameterManager()),
            new ParameterManager()
        );
        return new ConditionBuilder(
            $connection,
            new ParameterManager(),
            $executionEngine,
            new RawValueResolver($connection, new ParameterManager())
        );
    }

    public function testIsExternalReferenceWithTableColumnPattern(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new ReflectionClass($builder);
        $method = $reflection->getMethod('isExternalReference');
        $method->setAccessible(true);

        // Set table to 'users'
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableProperty->setValue($builder, 'users');

        // 'users.id' is not external (table is 'users')
        $this->assertFalse($method->invoke($builder, 'users.id'));

        // 'orders.user_id' is external (table is 'users', not 'orders')
        $this->assertTrue($method->invoke($builder, 'orders.user_id'));
    }

    public function testIsExternalReferenceWithInvalidPattern(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new ReflectionClass($builder);
        $method = $reflection->getMethod('isExternalReference');
        $method->setAccessible(true);

        // Invalid patterns
        $this->assertFalse($method->invoke($builder, 'invalid'));
        $this->assertFalse($method->invoke($builder, 'table.'));
        $this->assertFalse($method->invoke($builder, '.column'));
        $this->assertFalse($method->invoke($builder, 'table.column.extra'));
    }

    public function testIsTableInCurrentQuery(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new ReflectionClass($builder);
        $method = $reflection->getMethod('isTableInCurrentQuery');
        $method->setAccessible(true);

        // Set table to 'users'
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableProperty->setValue($builder, 'users');

        $this->assertTrue($method->invoke($builder, 'users'));
        $this->assertFalse($method->invoke($builder, 'orders'));
    }

    public function testProcessExternalReferences(): void
    {
        $builder = $this->createConditionBuilder();
        $reflection = new ReflectionClass($builder);
        $method = $reflection->getMethod('processExternalReferences');
        $method->setAccessible(true);

        // Set table to 'users'
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableProperty->setValue($builder, 'users');

        // External reference should be converted to RawValue
        $result = $method->invoke($builder, 'orders.user_id');
        $this->assertInstanceOf(RawValue::class, $result);
        $this->assertEquals('orders.user_id', $result->getValue());

        // Non-external reference should remain unchanged
        $result = $method->invoke($builder, 'users.id');
        $this->assertEquals('users.id', $result);

        // Non-string value should remain unchanged
        $result = $method->invoke($builder, 123);
        $this->assertEquals(123, $result);
    }
}
