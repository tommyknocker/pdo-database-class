<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * Savepoints, nested transactions and table locking.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectTransactionInterface
{
    /**
     * Whether nested transactions (savepoints) are supported.
     */
    public function supportsNestedTransactions(): bool;

    /**
     * Whether DDL statements can be rolled back with the surrounding transaction.
     *
     * MySQL, MariaDB and Oracle commit implicitly before and after every DDL statement, so a migration that
     * fails halfway cannot be undone by a rollback there.
     */
    public function supportsTransactionalDdl(): bool;

    public function buildCreateSavepointSql(string $name): string;

    public function buildRollbackToSavepointSql(string $name): string;

    public function buildReleaseSavepointSql(string $name): string;

    public function releasesSavepointOnNestedCommit(): bool;

    public function releasesSavepointOnNestedRollback(): bool;

    /**
     * LOCK syntax.
     *
     * @param array<int, string> $tables
     * @param string $prefix
     * @param string $lockMethod
     *
     * @return string
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string;

    /**
     * UNLOCK syntax.
     *
     * @return string
     */
    public function buildUnlockSql(): string;
}
