<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use JsonException;
use tommyknocker\pdodb\dialects\roles\DialectMessagingInterface;
use tommyknocker\pdodb\dialects\roles\DialectQuotingInterface;
use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\PdoDb;

/**
 * Low-level outbox table access (claim, publish, retry).
 */
final class OutboxStorage
{
    public function __construct(
        protected PdoDb $db,
        protected string $tableName,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getSchemaSql(): array
    {
        return $this->dialect()->buildOutboxSchemaSql($this->tableName);
    }

    public function ensureSchema(): void
    {
        foreach ($this->getSchemaSql() as $sql) {
            $this->db->rawQuery($sql);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function insertPending(string $id, string $eventType, array $payload, int $delaySeconds = 0): void
    {
        $dialect = $this->dialect();
        $tableQuoted = $dialect->quoteTable($this->tableName);
        $now = $dialect->outboxNowSql();
        $availableAt = $delaySeconds > 0 ? $dialect->outboxNowPlusSecondsSql($delaySeconds) : $now;
        $this->db->rawQuery(
            "INSERT INTO {$tableQuoted}
                (id, event_type, payload, status, attempts, available_at, created_at)
             VALUES (:id, :event_type, :payload, :status, :attempts, {$availableAt}, {$now})",
            [
                'id' => $id,
                'event_type' => $eventType,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => OutboxStatuses::PENDING,
                'attempts' => 0,
            ]
        );
    }

    public function assertConnectionIdle(): void
    {
        if ($this->db->inTransaction()) {
            throw new OutboxException(
                'Outbox worker cannot run while a transaction is active on this connection.'
            );
        }
    }

    public function releaseStaleClaims(): void
    {
        [$sql, $params] = $this->dialect()->buildOutboxReleaseStaleClaimsSql($this->tableName);
        $this->db->rawQuery($sql, $params);
    }

    /**
     * Claim up to `$limit` due messages for one worker in a single transaction.
     *
     * A claimed row whose payload cannot be decoded is marked failed in the same transaction, so one
     * poison row cannot roll the batch back and block the queue.
     */
    public function claimBatch(string $workerToken, int $limit, int $lockSeconds): OutboxClaim
    {
        if ($limit < 1) {
            return new OutboxClaim([]);
        }

        $this->assertConnectionIdle();

        $strategy = $this->dialect()->outboxClaimStrategy();
        /** @var OutboxClaim $claim */
        $claim = $this->db->transaction(function () use ($strategy, $workerToken, $limit, $lockSeconds): OutboxClaim {
            $this->releaseStaleClaims();
            $rows = $strategy->claim($this->db, $this->tableName, $workerToken, $limit, $lockSeconds);

            // Payload LOBs must be read before the transaction ends (Oracle ORA-22297).
            $messages = [];
            $unreadable = 0;
            foreach ($rows as $row) {
                try {
                    $messages[] = OutboxMessage::fromRow($row);
                } catch (JsonException|OutboxException $e) {
                    $unreadable++;
                    $this->markFailed((string)($row['id'] ?? ''), $workerToken, 'Unreadable payload: ' . $e->getMessage());
                }
            }

            return new OutboxClaim($messages, $unreadable);
        });

        return $claim;
    }

    public function markPublished(string $messageId, string $workerToken): bool
    {
        $updated = $this->db->find()
            ->table($this->tableName)
            ->where('id', $messageId)
            ->where('locked_by', $workerToken)
            ->where('status', OutboxStatuses::PROCESSING)
            ->update([
                'status' => OutboxStatuses::PUBLISHED,
                'published_at' => new RawValue($this->dialect()->outboxNowSql()),
                'locked_by' => null,
                'locked_until' => null,
                'last_error' => null,
            ]);

        return $updated > 0;
    }

    public function markRetry(
        string $messageId,
        string $workerToken,
        int $delaySeconds,
        string $errorMessage,
    ): bool {
        $updated = $this->db->find()
            ->table($this->tableName)
            ->where('id', $messageId)
            ->where('locked_by', $workerToken)
            ->where('status', OutboxStatuses::PROCESSING)
            ->update([
                'status' => OutboxStatuses::PENDING,
                'available_at' => new RawValue($this->dialect()->outboxNowPlusSecondsSql($delaySeconds)),
                'locked_by' => null,
                'locked_until' => null,
                'last_error' => $this->truncateError($errorMessage),
            ]);

        return $updated > 0;
    }

    public function markFailed(string $messageId, string $workerToken, string $errorMessage): bool
    {
        $updated = $this->db->find()
            ->table($this->tableName)
            ->where('id', $messageId)
            ->where('locked_by', $workerToken)
            ->where('status', OutboxStatuses::PROCESSING)
            ->update([
                'status' => OutboxStatuses::FAILED,
                'locked_by' => null,
                'locked_until' => null,
                'last_error' => $this->truncateError($errorMessage),
            ]);

        return $updated > 0;
    }

    protected function dialect(): DialectMessagingInterface&DialectQuotingInterface
    {
        return $this->db->schema()->getDialect();
    }

    public function countRowsOlderThan(string $status, string $timestampColumn, int $olderThanSeconds): int
    {
        self::assertPurgeTimestampColumn($timestampColumn);

        $value = $this->db->find()
            ->table($this->tableName)
            ->where('status', $status)
            ->where($timestampColumn, new RawValue($this->dialect()->outboxNowPlusSecondsSql(-$olderThanSeconds)), '<')
            ->select(['COUNT(*)'])
            ->getValue();

        return (int) $value;
    }

    /**
     * Delete old rows in batches, so a large backlog never becomes one statement that locks the table and floods
     * the replication log.
     *
     * @param int $batchSize Rows per DELETE
     */
    public function deleteRowsOlderThan(string $status, string $timestampColumn, int $olderThanSeconds, int $batchSize = 1000): int
    {
        self::assertPurgeTimestampColumn($timestampColumn);
        if ($batchSize < 1) {
            throw new OutboxException('Purge batch size must be at least 1.');
        }

        $deleted = 0;
        do {
            $ids = $this->db->find()
                ->from($this->tableName)
                ->select(['id'])
                ->where('status', $status)
                ->where($timestampColumn, new RawValue($this->dialect()->outboxNowPlusSecondsSql(-$olderThanSeconds)), '<')
                ->orderBy($timestampColumn)
                ->limit($batchSize)
                ->getColumn();
            if ($ids === []) {
                break;
            }

            $deleted += $this->db->find()
                ->table($this->tableName)
                ->where('id', array_values($ids), 'IN')
                ->delete();
        } while (count($ids) === $batchSize);

        return $deleted;
    }

    public function countByStatus(): OutboxStatusCounts
    {
        $rows = $this->db->find()
            ->table($this->tableName)
            ->select(['status', 'COUNT(*) AS cnt'])
            ->groupBy('status')
            ->get();

        $counts = [
            OutboxStatuses::PENDING => 0,
            OutboxStatuses::PROCESSING => 0,
            OutboxStatuses::PUBLISHED => 0,
            OutboxStatuses::FAILED => 0,
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = array_change_key_case($row, CASE_LOWER);
            $status = (string) ($row['status'] ?? '');
            if (!array_key_exists($status, $counts)) {
                continue;
            }
            $counts[$status] = (int) ($row['cnt'] ?? 0);
        }

        return new OutboxStatusCounts(
            $counts[OutboxStatuses::PENDING],
            $counts[OutboxStatuses::PROCESSING],
            $counts[OutboxStatuses::PUBLISHED],
            $counts[OutboxStatuses::FAILED],
        );
    }

    /**
     * @return list<OutboxListItem>
     */
    public function listMessages(string $status, int $limit = 50): array
    {
        self::assertListableStatus($status);
        if ($limit < 1) {
            return [];
        }

        $rows = $this->db->find()
            ->table($this->tableName)
            ->where('status', $status)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = OutboxListItem::fromRow($row);
        }

        return $items;
    }

    public function requeueFailed(string $id, bool $resetAttempts = true): bool
    {
        if ($id === '') {
            throw new OutboxException('Outbox message id must not be empty.');
        }

        return $this->requeueFailedIds([$id], $resetAttempts) > 0;
    }

    /**
     * Put failed messages back in the queue, one UPDATE per chunk of ids.
     *
     * @param list<string> $ids
     *
     * @return int Messages that were still failed and are pending again
     */
    protected function requeueFailedIds(array $ids, bool $resetAttempts): int
    {
        $update = [
            'status' => OutboxStatuses::PENDING,
            'available_at' => new RawValue($this->dialect()->outboxNowSql()),
            'locked_by' => null,
            'locked_until' => null,
            'published_at' => null,
        ];
        if ($resetAttempts) {
            $update['attempts'] = 0;
        }

        $requeued = 0;
        // 1000 is the largest IN list every supported dialect accepts (Oracle).
        foreach (array_chunk($ids, 1000) as $chunk) {
            $requeued += $this->db->find()
                ->table($this->tableName)
                ->where('id', $chunk, 'IN')
                ->where('status', OutboxStatuses::FAILED)
                ->update($update);
        }

        return $requeued;
    }

    public function requeueAllFailed(int $limit, bool $resetAttempts, bool $dryRun): OutboxRequeueResult
    {
        if ($limit < 1) {
            return new OutboxRequeueResult(0, 0, $dryRun);
        }

        $rows = $this->db->find()
            ->table($this->tableName)
            ->where('status', OutboxStatuses::FAILED)
            ->orderBy('created_at', 'ASC')
            ->limit($limit)
            ->select(['id'])
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = array_change_key_case($row, CASE_LOWER);
            $messageId = (string) ($row['id'] ?? '');
            if ($messageId !== '') {
                $ids[] = $messageId;
            }
        }

        $matched = count($ids);
        if ($dryRun || $matched === 0) {
            return new OutboxRequeueResult($matched, 0, $dryRun);
        }

        return new OutboxRequeueResult($matched, $this->requeueFailedIds($ids, $resetAttempts), false);
    }

    public function purge(
        string $status,
        string $olderThanSpec,
        bool $dryRun = false,
    ): OutboxPurgeResult {
        $timestampColumn = $status === OutboxStatuses::PUBLISHED ? 'published_at' : 'created_at';
        $seconds = OutboxDuration::toSeconds($olderThanSpec);
        $cutoffIso = OutboxDuration::cutoffIso8601($olderThanSpec);
        $matched = $this->countRowsOlderThan($status, $timestampColumn, $seconds);

        if ($dryRun) {
            return new OutboxPurgeResult($status, $cutoffIso, $matched, 0, true);
        }

        $deleted = $matched > 0
            ? $this->deleteRowsOlderThan($status, $timestampColumn, $seconds)
            : 0;

        return new OutboxPurgeResult($status, $cutoffIso, $matched, $deleted, false);
    }

    protected static function assertPurgeTimestampColumn(string $column): void
    {
        if (!in_array($column, ['published_at', 'created_at'], true)) {
            throw new OutboxException('Invalid purge timestamp column.');
        }
    }

    protected static function assertListableStatus(string $status): void
    {
        if (!in_array($status, [
            OutboxStatuses::PENDING,
            OutboxStatuses::PROCESSING,
            OutboxStatuses::PUBLISHED,
            OutboxStatuses::FAILED,
        ], true)) {
            throw new OutboxException(
                'Invalid outbox status for list. Allowed: pending, processing, published, failed.'
            );
        }
    }

    protected function truncateError(string $message): string
    {
        if (strlen($message) <= 4000) {
            return $message;
        }

        return mb_strcut($message, 0, 3997, 'UTF-8') . '...';
    }
}
