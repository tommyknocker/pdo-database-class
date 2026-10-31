<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\dialects\DialectInterface;
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
    public function insertPending(string $id, string $eventType, array $payload): void
    {
        $dialect = $this->dialect();
        $tableQuoted = $dialect->quoteTable($this->tableName);
        $now = $dialect->outboxNowSql();
        $this->db->rawQuery(
            "INSERT INTO {$tableQuoted}
                (id, event_type, payload, status, attempts, available_at, created_at)
             VALUES (:id, :event_type, :payload, :status, :attempts, {$now}, {$now})",
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
     * @return list<OutboxMessage>
     */
    public function claimBatch(string $workerToken, int $limit, int $lockSeconds): array
    {
        if ($limit < 1) {
            return [];
        }

        $this->assertConnectionIdle();

        $dialect = $this->dialect();
        if ($dialect->supportsOutboxConcurrentWorkerClaim()) {
            $this->releaseStaleClaims();
            $messages = [];
            for ($i = 0; $i < $limit; $i++) {
                $message = $this->claimOneInTransaction($workerToken, $lockSeconds);
                if ($message === null) {
                    break;
                }
                $messages[] = $message;
            }

            return $messages;
        }

        $messages = [];
        $this->db->transaction(function () use ($workerToken, $limit, $lockSeconds, &$messages): void {
            $this->releaseStaleClaims();
            for ($i = 0; $i < $limit; $i++) {
                $message = $this->claimOne($workerToken, $lockSeconds);
                if ($message === null) {
                    break;
                }
                $messages[] = $message;
            }
        });

        return $messages;
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

    protected function claimOneInTransaction(string $workerToken, int $lockSeconds): ?OutboxMessage
    {
        $message = null;
        $this->db->transaction(function () use ($workerToken, $lockSeconds, &$message): void {
            $message = $this->claimOne($workerToken, $lockSeconds);
        });

        return $message;
    }

    protected function claimOne(string $workerToken, int $lockSeconds): ?OutboxMessage
    {
        $dialect = $this->dialect();
        $claimToken = $workerToken . ':' . bin2hex(random_bytes(8));

        $lockCandidate = $dialect->buildOutboxLockCandidateSql($this->tableName);
        $pendingIds = $dialect->buildOutboxPendingIdsSql($this->tableName);
        if ($lockCandidate !== null && $pendingIds !== null) {
            return $this->claimFirstUnlocked($pendingIds, $lockCandidate, $claimToken, $lockSeconds);
        }
        if ($lockCandidate !== null) {
            return $this->claimLockedCandidate($lockCandidate, $claimToken, $lockSeconds);
        }

        [$sql, $params] = $dialect->buildOutboxClaimOneSql($this->tableName, $lockSeconds);
        $params['token'] = $claimToken;

        if ($dialect->outboxClaimSqlReturnsClaimedRow()) {
            $row = $this->db->rawQueryOne($sql, $params);
            if (!is_array($row) || $row === []) {
                return null;
            }

            return OutboxMessage::fromRow($row);
        }

        $this->db->rawQuery($sql, $params);
        $row = $this->db->find()
            ->table($this->tableName)
            ->where('locked_by', $claimToken)
            ->where('status', OutboxStatuses::PROCESSING)
            ->getOne();

        if (!is_array($row)) {
            return null;
        }

        return OutboxMessage::fromRow($row);
    }

    /**
     * @param array{0: string, 1: array<string, mixed>} $pendingIds
     * @param array{0: string, 1: array<string, mixed>} $lockById
     */
    protected function claimFirstUnlocked(
        array $pendingIds,
        array $lockById,
        string $claimToken,
        int $lockSeconds,
    ): ?OutboxMessage {
        [$listSql, $listParams] = $pendingIds;

        $candidates = $this->db->rawQuery($listSql, $listParams);
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidate = array_change_key_case($candidate, CASE_LOWER);
            $id = (string) ($candidate['id'] ?? '');
            if ($id === '') {
                continue;
            }

            [$lockSql, $lockParams] = $lockById;
            $lockParams['id'] = $id;
            $locked = $this->db->rawQueryOne($lockSql, $lockParams);
            if (!is_array($locked) || $locked === []) {
                continue;
            }

            $message = $this->markCandidateProcessing($id, $claimToken, $lockSeconds);
            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }

    protected function markCandidateProcessing(string $id, string $claimToken, int $lockSeconds): ?OutboxMessage
    {
        $updated = $this->db->find()
            ->table($this->tableName)
            ->where('id', $id)
            ->where('status', OutboxStatuses::PENDING)
            ->update([
                'status' => OutboxStatuses::PROCESSING,
                'locked_by' => $claimToken,
                'locked_until' => new RawValue($this->dialect()->outboxNowPlusSecondsSql($lockSeconds)),
                'attempts' => new RawValue('attempts + 1'),
            ]);
        if ($updated < 1) {
            return null;
        }

        $row = $this->db->find()
            ->table($this->tableName)
            ->where('id', $id)
            ->where('locked_by', $claimToken)
            ->where('status', OutboxStatuses::PROCESSING)
            ->getOne();
        if (!is_array($row)) {
            return null;
        }

        return OutboxMessage::fromRow($row);
    }

    /**
     * @param array{0: string, 1: array<string, mixed>} $lockCandidate
     */
    protected function claimLockedCandidate(
        array $lockCandidate,
        string $claimToken,
        int $lockSeconds,
    ): ?OutboxMessage {
        [$sql, $params] = $lockCandidate;

        $candidate = $this->db->rawQueryOne($sql, $params);
        if (!is_array($candidate) || $candidate === []) {
            return null;
        }

        $candidate = array_change_key_case($candidate, CASE_LOWER);
        $id = (string) ($candidate['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return $this->markCandidateProcessing($id, $claimToken, $lockSeconds);
    }

    protected function dialect(): DialectInterface
    {
        return $this->db->schema()->getDialect();
    }

    public function countRowsOlderThan(string $status, string $timestampColumn, string $cutoffIso): int
    {
        self::assertPurgeTimestampColumn($timestampColumn);

        $value = $this->db->find()
            ->table($this->tableName)
            ->where('status', $status)
            ->where($timestampColumn, $cutoffIso, '<')
            ->select(['COUNT(*)'])
            ->getValue();

        return (int) $value;
    }

    public function deleteRowsOlderThan(string $status, string $timestampColumn, string $cutoffIso): int
    {
        self::assertPurgeTimestampColumn($timestampColumn);

        return $this->db->find()
            ->table($this->tableName)
            ->where('status', $status)
            ->where($timestampColumn, $cutoffIso, '<')
            ->delete();
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

        return $this->db->find()
            ->table($this->tableName)
            ->where('id', $id)
            ->where('status', OutboxStatuses::FAILED)
            ->update($update) > 0;
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

        $requeued = 0;
        foreach ($ids as $messageId) {
            if ($this->requeueFailed($messageId, $resetAttempts)) {
                $requeued++;
            }
        }

        return new OutboxRequeueResult($matched, $requeued, false);
    }

    public function purge(
        string $status,
        string $olderThanSpec,
        bool $dryRun = false,
    ): OutboxPurgeResult {
        $timestampColumn = $status === OutboxStatuses::PUBLISHED ? 'published_at' : 'created_at';
        $cutoffIso = OutboxDuration::cutoffIso8601($olderThanSpec);
        $matched = $this->countRowsOlderThan($status, $timestampColumn, $cutoffIso);

        if ($dryRun) {
            return new OutboxPurgeResult($status, $cutoffIso, $matched, 0, true);
        }

        $deleted = $matched > 0
            ? $this->deleteRowsOlderThan($status, $timestampColumn, $cutoffIso)
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
