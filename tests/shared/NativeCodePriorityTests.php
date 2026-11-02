<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDOException;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\exceptions\ExceptionFactory;

/**
 * A driver's own error code decides the kind of error; words in a table or constraint name do not.
 */
final class NativeCodePriorityTests extends TestCase
{
    private function classify(string $driver, string $state, int $nativeCode, string $message): array
    {
        $e = new PDOException($message);
        $e->errorInfo = [$state, $nativeCode, $message];
        $classified = ExceptionFactory::createFromPdoException($e, $driver);

        return [$classified->getCategory(), $classified->isRetryable()];
    }

    public function testObjectNamesDoNotOverrideTheNativeCode(): void
    {
        $this->assertSame(
            ['constraint', false],
            $this->classify('oci', 'HY000', 1, 'ORA-00001: unique constraint (APP.TRANSACTION_ID_UQ) violated')
        );
        $this->assertSame(
            ['constraint', false],
            $this->classify('sqlsrv', '23000', 547, 'The INSERT statement conflicted with the FOREIGN KEY constraint "FK_blocks". The conflict occurred in database "app", table "dbo.blocks", column id.')
        );
    }

    public function testMessageWordsStillClassifyWhatTheNativeCodeDoesNotCover(): void
    {
        [$category] = $this->classify('mysql', 'HY000', 0, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');

        $this->assertSame('connection', $category);
    }
}
