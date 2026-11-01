<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\inbox;

use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\InboxException;
use tommyknocker\pdodb\outbox\OutboxDuration;
use tommyknocker\pdodb\PdoDb;

/**
 * Consumer-side inbox: remember processed message ids so at-least-once delivery is idempotent.
 *
 * once() claims the id in the same transaction as the handler. A duplicate claim rolls
 * back only that savepoint when the connection is already in a transaction, then returns false.
 */
final class Inbox
{
    public function __construct(
        protected PdoDb $db,
        protected string $consumer = 'default',
        protected string $tableName = 'pdodb_inbox',
    ) {
    }

    /**
     * @return list<string>
     */
    public function getSchemaSql(): array
    {
        return $this->storage()->getSchemaSql();
    }

    public function ensureSchema(): void
    {
        $this->storage()->ensureSchema();
    }

    public function processed(string $messageId): bool
    {
        return $this->storage()->processed($messageId, $this->consumer);
    }

    /**
     * Run the handler once for this message id and consumer.
     *
     * @param callable(string): void $handler
     */
    public function once(string $messageId, callable $handler): bool
    {
        if ($messageId === '') {
            throw new InboxException('Inbox message id must not be empty.');
        }
        if ($this->consumer === '') {
            throw new InboxException('Inbox consumer name must not be empty.');
        }

        try {
            $this->db->transaction(function () use ($messageId, $handler): void {
                $this->storage()->claim($messageId, $this->consumer);
                $handler($messageId);
            });
        } catch (ConstraintViolationException $exception) {
            if ($this->processed($messageId)) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    /**
     * Forget processed message ids older than the given age, so the table does not grow forever.
     *
     * The age must be longer than the longest time the broker can redeliver a message: a message that comes
     * back after its id was purged is handled a second time. Applies to every consumer sharing the table.
     *
     * @param string $olderThanSpec Age such as 30d, 12h or 90m (same format as the outbox purge)
     * @param bool $dryRun Only count the rows
     *
     * @return int Rows deleted, or rows that would be deleted for a dry run
     */
    public function purge(string $olderThanSpec, bool $dryRun = false): int
    {
        $seconds = OutboxDuration::toSeconds($olderThanSpec);

        return $dryRun
            ? $this->storage()->countProcessedOlderThan($seconds)
            : $this->storage()->deleteProcessedOlderThan($seconds);
    }

    public function storage(): InboxStorage
    {
        return new InboxStorage($this->db, $this->tableName);
    }
}
