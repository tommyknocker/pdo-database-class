<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\query\QueryConstants;

final class ConstantsAndExternalRefsTests extends TestCase
{
    public function testQueryConstantsAreDefined(): void
    {
        $this->assertSame('AND', QueryConstants::BOOLEAN_AND);
        $this->assertSame('OR', QueryConstants::BOOLEAN_OR);
        $this->assertContains('=', QueryConstants::getComparisonOperators());
        $this->assertTrue(QueryConstants::isValidSqlOperator('IN'));
    }
}
