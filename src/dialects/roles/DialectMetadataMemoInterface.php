<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

/**
 * A dialect that remembers what it has learned about tables (a key, a trigger) for the life of its connection.
 *
 * The connection calls {@see forgetMetadata()} when a statement may have changed the answer: DDL, a grant, a change of
 * the current database, or a rolled-back transaction. It is separate from DialectInterface so that existing dialects do
 * not have to implement it.
 */
interface DialectMetadataMemoInterface
{
    /**
     * Drop everything remembered about tables.
     */
    public function forgetMetadata(): void;
}
