<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\RegexpReplaceValue;

/**
 * Tests for RegexpReplaceValue.
 */
final class RegexpReplaceValueTests extends TestCase
{
    /**
     * Test constructor with string source value.
     */
    public function testConstructorWithString(): void
    {
        $value = new RegexpReplaceValue('test string', 'test', 'replaced');
        $this->assertInstanceOf(RegexpReplaceValue::class, $value);
        $this->assertInstanceOf(RawValue::class, $value);
    }

    /**
     * Test constructor with RawValue source.
     */
    public function testConstructorWithRawValue(): void
    {
        $source = new RawValue('column_name');
        $value = new RegexpReplaceValue($source, 'pattern', 'replacement');
        $this->assertInstanceOf(RegexpReplaceValue::class, $value);
    }

    /**
     * Test getSourceValue with string.
     */
    public function testGetSourceValueWithString(): void
    {
        $value = new RegexpReplaceValue('test string', 'test', 'replaced');
        $source = $value->getSourceValue();
        $this->assertEquals('test string', $source);
    }

    /**
     * Test getSourceValue with RawValue.
     */
    public function testGetSourceValueWithRawValue(): void
    {
        $source = new RawValue('column_name');
        $value = new RegexpReplaceValue($source, 'pattern', 'replacement');
        $retrievedSource = $value->getSourceValue();
        $this->assertInstanceOf(RawValue::class, $retrievedSource);
        $this->assertEquals($source, $retrievedSource);
    }

    /**
     * Test getPattern.
     */
    public function testGetPattern(): void
    {
        $value = new RegexpReplaceValue('test', 'pattern', 'replacement');
        $this->assertEquals('pattern', $value->getPattern());
    }

    /**
     * Test getReplacement.
     */
    public function testGetReplacement(): void
    {
        $value = new RegexpReplaceValue('test', 'pattern', 'replacement');
        $this->assertEquals('replacement', $value->getReplacement());
    }

    /**
     * Test getValue with string source.
     */
    public function testGetValueWithStringSource(): void
    {
        $value = new RegexpReplaceValue('test string', 'test', 'replaced');
        $result = $value->getValue();
        $this->assertStringContainsString('REGEXP_REPLACE', $result);
        $this->assertStringContainsString('test string', $result);
        $this->assertStringContainsString('test', $result);
        $this->assertStringContainsString('replaced', $result);
    }

    /**
     * Test getValue with RawValue source.
     */
    public function testGetValueWithRawValueSource(): void
    {
        $source = new RawValue('column_name');
        $value = new RegexpReplaceValue($source, 'pattern', 'replacement');
        $result = $value->getValue();
        $this->assertStringContainsString('REGEXP_REPLACE', $result);
        $this->assertStringContainsString('column_name', $result);
        $this->assertStringContainsString('pattern', $result);
        $this->assertStringContainsString('replacement', $result);
    }

    /**
     * Test getValue escapes single quotes in pattern.
     */
    public function testGetValueEscapesQuotesInPattern(): void
    {
        $value = new RegexpReplaceValue('test', "test'pattern", 'replacement');
        $result = $value->getValue();
        $this->assertStringContainsString("test''pattern", $result);
    }

    /**
     * Test getValue escapes single quotes in replacement.
     */
    public function testGetValueEscapesQuotesInReplacement(): void
    {
        $value = new RegexpReplaceValue('test', 'pattern', "rep'lacement");
        $result = $value->getValue();
        $this->assertStringContainsString("rep''lacement", $result);
    }

    /**
     * Test getValue with empty pattern.
     */
    public function testGetValueWithEmptyPattern(): void
    {
        $value = new RegexpReplaceValue('test', '', 'replacement');
        $result = $value->getValue();
        $this->assertStringContainsString('REGEXP_REPLACE', $result);
        $this->assertStringContainsString('replacement', $result);
    }

    /**
     * Test getValue with empty replacement.
     */
    public function testGetValueWithEmptyReplacement(): void
    {
        $value = new RegexpReplaceValue('test', 'pattern', '');
        $result = $value->getValue();
        $this->assertStringContainsString('REGEXP_REPLACE', $result);
        $this->assertStringContainsString('pattern', $result);
    }

    /**
     * Test getValue with complex pattern.
     */
    public function testGetValueWithComplexPattern(): void
    {
        $value = new RegexpReplaceValue('test123', '[0-9]+', 'number');
        $result = $value->getValue();
        $this->assertStringContainsString('REGEXP_REPLACE', $result);
        $this->assertStringContainsString('[0-9]+', $result);
        $this->assertStringContainsString('number', $result);
    }
}
