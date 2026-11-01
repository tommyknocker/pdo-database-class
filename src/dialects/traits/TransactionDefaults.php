<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectTransactionInterface;

/**
 * Default implementation of the savepoints, nested transactions and locking part of the dialect ({@see DialectTransactionInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait TransactionDefaults
{
    /**
     * {@inheritDoc}
     */
    public function supportsNestedTransactions(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSavepointSql(string $name): string
    {
        return "SAVEPOINT {$name}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRollbackToSavepointSql(string $name): string
    {
        return "ROLLBACK TO SAVEPOINT {$name}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildReleaseSavepointSql(string $name): string
    {
        return "RELEASE SAVEPOINT {$name}";
    }

    /**
     * {@inheritDoc}
     */
    public function releasesSavepointOnNestedCommit(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function releasesSavepointOnNestedRollback(): bool
    {
        return true;
    }
}
