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
     *
     * @throws OutboxException When not inside an active transaction.
     */
    public function add(string $eventType, array $payload): string
    {
        if (!$this->db->inTransaction()) {
            throw new OutboxException(
                'Outbox::add() must be called inside an active database transaction on the same PdoDb connection.'
            );
        }

        if ($eventType === '') {
            throw new OutboxException('Outbox event type must not be empty.');
        }

        $id = OutboxUuid::v4();
        $createdAt = gmdate('Y-m-d\TH:i:s\Z');
        $this->storage()->insertPending($id, $eventType, $payload, $createdAt);

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

    protected function storage(): OutboxStorage
    {
        return new OutboxStorage($this->db, $this->tableName);
    }
}
