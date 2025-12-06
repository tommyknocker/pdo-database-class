<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\RegexpLikeValue;
use tommyknocker\pdodb\helpers\values\ToCharValue;

/**
 * Tests for RegexpLikeValue.
 */
final class RegexpLikeValueTests extends BaseSharedTestCase
{
    public function testRegexpLikeValueWithString(): void
    {
        $value = new RegexpLikeValue('column_name', '^test');
        $this->assertEquals('column_name', $value->getSourceValue());
        $this->assertEquals('^test', $value->getPattern());
    }

    public function testRegexpLikeValueWithRawValue(): void
    {
        $rawValue = new RawValue('UPPER(column)');
        $value = new RegexpLikeValue($rawValue, '^TEST');
        $this->assertInstanceOf(RawValue::class, $value->getSourceValue());
        $this->assertEquals('^TEST', $value->getPattern());
    }

    public function testRegexpLikeValueGetValueWithStringColumn(): void
    {
        $value = new RegexpLikeValue('column_name', '^test');
        $result = $value->getValue();
        $this->assertStringContainsString('REGEXP_LIKE', $result);
        // RegexpLikeValue converts column names to uppercase for Oracle
        $this->assertStringContainsString('COLUMN_NAME', $result);
        $this->assertStringContainsString('^test', $result);
    }

    public function testRegexpLikeValueGetValueWithToCharValue(): void
    {
        $toCharValue = new ToCharValue('column_name');
        $value = new RegexpLikeValue($toCharValue, '^test');
        $result = $value->getValue();
        $this->assertStringContainsString('REGEXP_LIKE', $result);
        $this->assertStringContainsString('TO_CHAR', $result);
        $this->assertStringContainsString('^test', $result);
    }

    public function testRegexpLikeValueGetValueEscapesSingleQuotes(): void
    {
        $value = new RegexpLikeValue('column_name', "test'value");
        $result = $value->getValue();
        $this->assertStringContainsString("test''value", $result);
    }

    public function testRegexpLikeValueGetValueWithComplexPattern(): void
    {
        $value = new RegexpLikeValue('email', '^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$');
        $result = $value->getValue();
        $this->assertStringContainsString('REGEXP_LIKE', $result);
        // RegexpLikeValue converts column names to uppercase for Oracle
        $this->assertStringContainsString('EMAIL', $result);
        $this->assertStringContainsString('^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$', $result);
    }
}
