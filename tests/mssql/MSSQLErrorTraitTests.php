<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use tommyknocker\pdodb\helpers\traits\MSSQLErrorTrait;

final class MSSQLErrorTraitTests extends TestCase
{
    private function getDummy()
    {
        return new class {
            use MSSQLErrorTrait;
        };
    }

    public function testRetryableErrorsContainExpectedCodes(): void
    {
        $dummy = $this->getDummy();
        $errors = $dummy::getMssqlRetryableErrors();
        $this->assertContains($dummy::MSSQL_CONNECTION_FAILURE, $errors);
        $this->assertContains($dummy::MSSQL_QUERY_TIMEOUT, $errors);
        $this->assertContains($dummy::MSSQL_DEADLOCK_DETECTED, $errors);
    }

    public function testDescriptionsIncludeCommonCodes(): void
    {
        $dummy = $this->getDummy();
        $method = new ReflectionMethod(get_class($dummy), 'getMssqlDescriptions');
        $method->setAccessible(true);
        /** @var array<string,string> $descs */
        $descs = $method->invoke(null);

        $this->assertArrayHasKey($dummy::MSSQL_CONNECTION_FAILURE, $descs);
        $this->assertSame('Connection failure', $descs[$dummy::MSSQL_CONNECTION_FAILURE]);
        $this->assertArrayHasKey($dummy::MSSQL_UNIQUE_VIOLATION, $descs);
        $this->assertArrayHasKey($dummy::MSSQL_SYNTAX_ERROR, $descs);
    }
}


