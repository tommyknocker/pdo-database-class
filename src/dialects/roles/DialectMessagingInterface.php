<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\outbox\claim\OutboxClaimStrategy;

/**
 * Schema and SQL for the transactional outbox and the consumer inbox.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectMessagingInterface
{
    /**
     * SQL statements to create the transactional outbox table and indexes.
     *
     * @return list<string>
     */
    public function buildOutboxSchemaSql(string $tableName): array;

    /**
     * SQL statements to create the consumer inbox table.
     *
     * @return list<string>
     */
    public function buildInboxSchemaSql(string $tableName): array;

    /**
     * SQL expression for the current UTC time in the outbox timestamp column representation (database clock).
     */
    public function outboxNowSql(): string;

    /**
     * SQL expression for the database UTC time shifted by a (possibly negative) number of seconds.
     */
    public function outboxNowPlusSecondsSql(int $seconds): string;

    /**
     * Release processing locks that expired (worker crashed or callback exceeded lock TTL).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function buildOutboxReleaseStaleClaimsSql(string $tableName): array;

    /**
     * How this database moves due pending outbox rows to `processing` for one worker.
     */
    public function outboxClaimStrategy(): OutboxClaimStrategy;
}
