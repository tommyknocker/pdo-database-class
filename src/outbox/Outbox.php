<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\events\OutboxMessageAddedEvent;
use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\PdoDb;

/**
 * Transactional outbox: append events in the same DB transaction as business writes.
 */
final class Outbox
{
    /** Longest stored headers JSON, in bytes: the column is VARCHAR(4000) (Oracle counts bytes) */
    public const MAX_HEADERS_BYTES = 4000;

    /** Longest partition key, in bytes */
    public const MAX_PARTITION_KEY_BYTES = 190;

    public function __construct(
        protected PdoDb $db,
        protected string $tableName = 'pdodb_outbox',
        protected ?OutboxSerializer $serializer = null,
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
     * @param array<string, mixed> $headers Information about the message, such as a trace id or a tenant, kept apart from
     *                                      the payload and handed to the publisher as `$message->headers` (at most 4000 bytes as JSON)
     * @param string|null $partitionKey Messages with the same key are delivered one at a time, in the order they were added
     *                                  (for example an order id); messages without a key have no such ordering
     *
     * @throws OutboxException When not inside an active transaction, or headers or partition key are not acceptable.
     */
    public function add(
        string $eventType,
        array $payload,
        int $delaySeconds = 0,
        array $headers = [],
        ?string $partitionKey = null,
    ): string {
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

        foreach (array_keys($headers) as $name) {
            if (!is_string($name) || $name === '') {
                throw new OutboxException('Outbox header names must be non-empty strings.');
            }
        }
        if ($headers !== [] && strlen(json_encode($headers, JSON_THROW_ON_ERROR)) > self::MAX_HEADERS_BYTES) {
            throw new OutboxException('Outbox headers are too large: at most ' . self::MAX_HEADERS_BYTES . ' bytes as JSON.');
        }
        if ($partitionKey !== null && ($partitionKey === '' || strlen($partitionKey) > self::MAX_PARTITION_KEY_BYTES)) {
            throw new OutboxException('Outbox partition key must be 1 to ' . self::MAX_PARTITION_KEY_BYTES . ' bytes.');
        }

        // Time-ordered, so messages with the same partition key can be delivered in the order they were added
        $id = OutboxUuid::ordered();
        $storage = $this->storage();
        $storage->insertPending($id, $eventType, $payload, $delaySeconds, $headers, $partitionKey);
        $storage->dispatch(new OutboxMessageAddedEvent($id, $eventType, $headers, $partitionKey));

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
        return new OutboxStorage($this->db, $this->tableName, $this->serializer);
    }
}
