<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\InsertId;

/**
 * Databases answer with strings; an id becomes an int only when that loses nothing.
 */
final class InsertIdNormalizationTests extends TestCase
{
    public function testNoIdIsNull(): void
    {
        foreach ([false, null, '', '0', 0] as $none) {
            $this->assertNull(InsertId::from($none), var_export($none, true));
        }
    }

    public function testANumberThatFitsBecomesAnInt(): void
    {
        $this->assertSame(1, InsertId::from('1'));
        $this->assertSame(42, InsertId::from(42));
        $this->assertSame(PHP_INT_MAX, InsertId::from((string)PHP_INT_MAX));
        $this->assertSame(-5, InsertId::from('-5'), 'a negative key is a key');
    }

    public function testANumberBeyondIntStaysAString(): void
    {
        $this->assertSame('9223372036854775808', InsertId::from('9223372036854775808'));
        $this->assertSame('18446744073709551000', InsertId::from('18446744073709551000'));
        $this->assertSame('-9223372036854775809', InsertId::from('-9223372036854775809'));
    }

    public function testANonNumericIdIsKeptAsIs(): void
    {
        $this->assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', InsertId::from('0f8fad5b-d9cb-469f-a165-70867728950e'));
        $this->assertSame('007', InsertId::from('007'), 'leading zeros are part of the value');
    }
}
