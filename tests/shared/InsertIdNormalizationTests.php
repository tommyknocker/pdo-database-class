<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tommyknocker\pdodb\query\ExecutionEngine;

/**
 * PDO::lastInsertId() answers with a string; an id becomes an int only when that loses nothing.
 */
final class InsertIdNormalizationTests extends TestCase
{
    private function normalize(string|false $raw): int|string|null
    {
        $engine = (new ReflectionClass(ExecutionEngine::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($engine, 'normalizeInsertId'))->invoke($engine, $raw);
    }

    public function testNoIdIsNull(): void
    {
        $this->assertNull($this->normalize(false));
        $this->assertNull($this->normalize(''));
        $this->assertNull($this->normalize('0'));
    }

    public function testANumberThatFitsBecomesAnInt(): void
    {
        $this->assertSame(1, $this->normalize('1'));
        $this->assertSame(42, $this->normalize('42'));
        $this->assertSame(PHP_INT_MAX, $this->normalize((string)PHP_INT_MAX));
    }

    public function testANumberBeyondIntStaysAString(): void
    {
        $this->assertSame('9223372036854775808', $this->normalize('9223372036854775808'));
        $this->assertSame('18446744073709551000', $this->normalize('18446744073709551000'));
    }

    public function testANonNumericIdIsKeptAsIs(): void
    {
        $this->assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $this->normalize('0f8fad5b-d9cb-469f-a165-70867728950e'));
        $this->assertSame('007', $this->normalize('007'), 'leading zeros are part of the value');
    }
}
