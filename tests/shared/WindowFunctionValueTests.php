<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\values\WindowFunctionValue;

/**
 * Tests for WindowFunctionValue class.
 */
final class WindowFunctionValueTests extends BaseSharedTestCase
{
    public function testWindowFunctionValueGetters(): void
    {
        $value = new WindowFunctionValue(
            'ROW_NUMBER',
            [],
            ['department'],
            [['salary' => 'DESC']],
            'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW'
        );

        $this->assertEquals('ROW_NUMBER', $value->getFunction());
        $this->assertEquals([], $value->getArgs());
        $this->assertEquals(['department'], $value->getPartitionBy());
        $this->assertEquals([['salary' => 'DESC']], $value->getOrderBy());
        $this->assertEquals('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW', $value->getFrameClause());
    }

    public function testWindowFunctionValueWithArgs(): void
    {
        $value = new WindowFunctionValue('LAG', ['salary', 1, 0], [], []);

        $this->assertEquals('LAG', $value->getFunction());
        $this->assertEquals(['salary', 1, 0], $value->getArgs());
    }

    public function testWindowFunctionValuePartitionBySingleColumn(): void
    {
        $value = new WindowFunctionValue('RANK');
        $result = $value->partitionBy('department');

        $this->assertSame($value, $result);
        $this->assertEquals(['department'], $value->getPartitionBy());
    }

    public function testWindowFunctionValuePartitionByMultipleColumns(): void
    {
        $value = new WindowFunctionValue('RANK');
        $result = $value->partitionBy(['department', 'location']);

        $this->assertSame($value, $result);
        $this->assertEquals(['department', 'location'], $value->getPartitionBy());
    }

    public function testWindowFunctionValueOrderBySingleColumn(): void
    {
        $value = new WindowFunctionValue('ROW_NUMBER');
        $result = $value->orderBy('salary', 'DESC');

        $this->assertSame($value, $result);
        $this->assertEquals([['salary' => 'DESC']], $value->getOrderBy());
    }

    public function testWindowFunctionValueOrderByArrayWithDirections(): void
    {
        $value = new WindowFunctionValue('ROW_NUMBER');
        $result = $value->orderBy(['salary' => 'DESC', 'name' => 'ASC']);

        $this->assertSame($value, $result);
        $this->assertCount(2, $value->getOrderBy());
        $this->assertContains(['salary' => 'DESC'], $value->getOrderBy());
        $this->assertContains(['name' => 'ASC'], $value->getOrderBy());
    }

    public function testWindowFunctionValueOrderByArrayWithoutDirections(): void
    {
        $value = new WindowFunctionValue('ROW_NUMBER');
        $result = $value->orderBy(['salary', 'name']);

        $this->assertSame($value, $result);
        $this->assertCount(2, $value->getOrderBy());
        $this->assertContains(['salary' => 'ASC'], $value->getOrderBy());
        $this->assertContains(['name' => 'ASC'], $value->getOrderBy());
    }

    public function testWindowFunctionValueRows(): void
    {
        $value = new WindowFunctionValue('ROW_NUMBER');
        $frameClause = 'ROWS BETWEEN 1 PRECEDING AND 1 FOLLOWING';
        $result = $value->rows($frameClause);

        $this->assertSame($value, $result);
        $this->assertEquals($frameClause, $value->getFrameClause());
    }

    public function testWindowFunctionValueRowsNull(): void
    {
        $value = new WindowFunctionValue('ROW_NUMBER', [], [], [], 'ROWS BETWEEN UNBOUNDED PRECEDING');
        $result = $value->rows(null);

        $this->assertSame($value, $result);
        $this->assertNull($value->getFrameClause());
    }

    public function testWindowFunctionValueInheritsFromRawValue(): void
    {
        $value = new WindowFunctionValue('ROW_NUMBER');
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\values\RawValue::class, $value);
    }

    public function testWindowFunctionValueOrderByChaining(): void
    {
        $value = new WindowFunctionValue('ROW_NUMBER');
        $value->orderBy('salary', 'DESC')->orderBy('name', 'ASC');

        $this->assertCount(2, $value->getOrderBy());
        $this->assertEquals([['salary' => 'DESC'], ['name' => 'ASC']], $value->getOrderBy());
    }
}
