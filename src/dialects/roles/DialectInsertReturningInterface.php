<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

/**
 * A dialect whose database can return the key of the row an INSERT wrote, in the same statement.
 *
 * `PDO::lastInsertId()` and sequence functions describe the session, not the statement: after an upsert that updated
 * a row, or an insert that was skipped, they name another row. A dialect that implements this interface appends a
 * RETURNING clause for the table's key instead, so the engine reads the key of exactly the row the statement wrote.
 * It is separate from DialectInterface so that existing dialects do not have to implement it.
 */
interface DialectInsertReturningInterface
{
    /**
     * The statement with a clause that returns the new row's key in its first column, or null when this table or
     * statement cannot be handled that way (no single-column key, a RETURNING clause already present); the engine
     * then reads the id with DialectDmlInterface::extractInsertId().
     */
    public function insertIdReturningSql(string $sql): ?string;
}
