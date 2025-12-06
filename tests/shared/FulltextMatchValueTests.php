<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\values\FulltextMatchValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Tests for FulltextMatchValue class.
 */
final class FulltextMatchValueTests extends BaseSharedTestCase
{
    public function testFulltextMatchValueWithSingleColumn(): void
    {
        $value = new FulltextMatchValue('title', 'search term');

        $this->assertEquals('title', $value->getColumns());
        $this->assertEquals('search term', $value->getSearchTerm());
        $this->assertNull($value->getMode());
        $this->assertFalse($value->isWithQueryExpansion());
    }

    public function testFulltextMatchValueWithMultipleColumns(): void
    {
        $columns = ['title', 'content', 'description'];
        $value = new FulltextMatchValue($columns, 'search term');

        $this->assertEquals($columns, $value->getColumns());
        $this->assertEquals('search term', $value->getSearchTerm());
    }

    public function testFulltextMatchValueWithMode(): void
    {
        $value = new FulltextMatchValue('title', 'search term', 'boolean');

        $this->assertEquals('boolean', $value->getMode());
    }

    public function testFulltextMatchValueWithQueryExpansion(): void
    {
        $value = new FulltextMatchValue('title', 'search term', 'natural', true);

        $this->assertEquals('natural', $value->getMode());
        $this->assertTrue($value->isWithQueryExpansion());
    }

    public function testFulltextMatchValueWithExpansionMode(): void
    {
        $value = new FulltextMatchValue(['title', 'content'], 'search term', 'expansion', true);

        $this->assertEquals(['title', 'content'], $value->getColumns());
        $this->assertEquals('expansion', $value->getMode());
        $this->assertTrue($value->isWithQueryExpansion());
    }

    public function testFulltextMatchValueInheritsFromRawValue(): void
    {
        $value = new FulltextMatchValue('title', 'search term');

        $this->assertInstanceOf(RawValue::class, $value);
    }
}
