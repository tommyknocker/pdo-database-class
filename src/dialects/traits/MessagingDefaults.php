<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectMessagingInterface;

/**
 * Default implementation of the outbox and inbox SQL part of the dialect ({@see DialectMessagingInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait MessagingDefaults
{
    /**
     * {@inheritDoc}
     */
    public function buildInboxSchemaSql(string $tableName): array
    {
        $tableQuoted = $this->quoteTable($tableName);

        return [
            "CREATE TABLE IF NOT EXISTS {$tableQuoted} (
                message_id VARCHAR(190) NOT NULL,
                consumer VARCHAR(190) NOT NULL,
                processed_at TEXT NOT NULL DEFAULT (datetime('now')),
                PRIMARY KEY (message_id, consumer)
            )",
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function buildOutboxSchemaSql(string $tableName): array
    {
        $tableQuoted = $this->quoteTable($tableName);
        $indexName = $this->quoteIdentifier($tableName . '_pending_idx');

        return [
            "CREATE TABLE IF NOT EXISTS {$tableQuoted} (
                id VARCHAR(36) PRIMARY KEY,
                event_type VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                locked_by VARCHAR(128) NULL,
                locked_until TEXT NULL,
                last_error TEXT NULL,
                published_at TEXT NULL
            )",
            "CREATE INDEX IF NOT EXISTS {$indexName} ON {$tableQuoted} (status, available_at)",
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function outboxNowSql(): string
    {
        return "datetime('now')";
    }

    /**
     * {@inheritDoc}
     */
    public function outboxNowPlusSecondsSql(int $seconds): string
    {
        return "datetime('now', '" . sprintf('%+d', $seconds) . " seconds')";
    }

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
