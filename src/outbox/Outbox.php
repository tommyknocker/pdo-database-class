<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\PdoDb;

/**
 * Transactional outbox: append events in the same DB transaction as business writes.
 */
final class Outbox
{
    public function __construct(
        protected PdoDb $db,
        protected string $tableName = 'pdodb_outbox',
    ) {
    }

    /**
     * @return list<string> DDL statements for migrations (dialect-specific).
     */
    public function getSchemaSql(): array
    {
        return $this->storage()->getSchemaSql();
    }

    /**
     * Creates the outbox table and index when missing (tests and local bootstrap).
     */
    public function ensureSchema(): void
    {
        $this->storage()->ensureSchema();
    }

    /**
     * @param array<string, mixed> $payload
     * @param int $delaySeconds Hold the message back for this many seconds before a worker may claim it
     *
     * @throws OutboxException When not inside an active transaction.
     */
    public function add(string $eventType, array $payload, int $delaySeconds = 0): string
    {
        if (!$this->db->inTransaction()) {
            throw new OutboxException(
                'Outbox::add() must be called inside an active database transaction on the same PdoDb connection.'
            );
        }

        if ($eventType === '') {
            throw new OutboxException('Outbox event type must not be empty.');
        }

        if ($delaySeconds < 0) {
            throw new OutboxException('Outbox delay must not be negative.');
        }

        $id = OutboxUuid::v4();
        $this->storage()->insertPending($id, $eventType, $payload, $delaySeconds);

        return $id;
    }

    public function worker(
        ?string $workerId = null,
        int $batchSize = 10,
        int $lockSeconds = 120,
        int $maxAttempts = 25,
    ): OutboxWorker {
        return new OutboxWorker(
            $this->storage(),
            $workerId ?? 'worker',
            $batchSize,
            $lockSeconds,
            $maxAttempts,
        );
    }

    public function purge(string $olderThanSpec, ?string $status = null, bool $dryRun = false): OutboxPurgeResult
    {
        $resolvedStatus = $status ?? OutboxStatuses::PUBLISHED;
        if (!in_array($resolvedStatus, [OutboxStatuses::PUBLISHED, OutboxStatuses::FAILED], true)) {
            throw new OutboxException(
                'Purge supports only status "' . OutboxStatuses::PUBLISHED . '" or "' . OutboxStatuses::FAILED . '".'
            );
        }

        return $this->storage()->purge($resolvedStatus, $olderThanSpec, $dryRun);
    }

    public function countByStatus(): OutboxStatusCounts
    {
        return $this->storage()->countByStatus();
    }

    /**
     * @return list<OutboxListItem>
     */
    public function listMessages(string $status, int $limit = 50): array
    {
        return $this->storage()->listMessages($status, $limit);
    }

    public function requeueFailed(string $id, bool $resetAttempts = true): bool
    {
        return $this->storage()->requeueFailed($id, $resetAttempts);
    }

    public function requeueAllFailed(int $limit = 100, bool $resetAttempts = true, bool $dryRun = false): OutboxRequeueResult
    {
        return $this->storage()->requeueAllFailed($limit, $resetAttempts, $dryRun);
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function storage(): OutboxStorage
    {
        return new OutboxStorage($this->db, $this->tableName);
    }
}
