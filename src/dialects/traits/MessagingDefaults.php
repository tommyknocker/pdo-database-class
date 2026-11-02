<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectMessagingInterface;

/**
 * Default implementation of the outbox and inbox SQL that does not depend on the database
 * ({@see DialectMessagingInterface}), shared by every dialect through DialectAbstract. Each dialect supplies its own
 * schema and clock expressions.
 */
trait MessagingDefaults
{
    /**
     * {@inheritDoc}
     */
    public function buildOutboxReleaseStaleClaimsSql(string $tableName): array
    {
        $tableQuoted = $this->quoteTable($tableName);

        return [
            "UPDATE {$tableQuoted}
                SET status = 'pending', locked_by = NULL, locked_until = NULL
                WHERE status = 'processing' AND locked_until IS NOT NULL AND locked_until < {$this->outboxNowSql()}",
            [],
        ];
    }
}
