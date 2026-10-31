<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\OutboxException;
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
    public function insertPending(string $id, string $eventType, array $payload, string $createdAt): void
    {
        $this->db->find()->table($this->tableName)->insert([
            'id' => $id,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => OutboxStatuses::PENDING,
            'attempts' => 0,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }

    public function releaseStaleClaims(): void
    {
        [$sql, $params] = $this->dialect()->buildOutboxReleaseStaleClaimsSql($this->tableName);
        if (array_key_exists('now', $params) && $params['now'] === '') {
            $params['now'] = gmdate('Y-m-d\TH:i:s\Z');
        }
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

        $dialect = $this->dialect();
        if ($dialect->supportsOutboxConcurrentWorkerClaim()) {
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

    public function markPublished(string $messageId, string $workerToken, string $publishedAt): bool
    {
        $updated = $this->db->find()
            ->table($this->tableName)
            ->where('id', $messageId)
            ->where('locked_by', $workerToken)
            ->where('status', OutboxStatuses::PROCESSING)
            ->update([
                'status' => OutboxStatuses::PUBLISHED,
                'published_at' => $publishedAt,
                'locked_by' => null,
                'locked_until' => null,
                'last_error' => null,
            ]);

        return $updated > 0;
    }

    public function markRetry(
        string $messageId,
        string $workerToken,
        string $availableAt,
        string $errorMessage,
    ): bool {
        $updated = $this->db->find()
            ->table($this->tableName)
            ->where('id', $messageId)
            ->where('locked_by', $workerToken)
            ->where('status', OutboxStatuses::PROCESSING)
            ->update([
                'status' => OutboxStatuses::PENDING,
                'available_at' => $availableAt,
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
            $this->releaseStaleClaims();
            $message = $this->claimOne($workerToken, $lockSeconds);
        });

        return $message;
    }

    protected function claimOne(string $workerToken, int $lockSeconds): ?OutboxMessage
    {
        $dialect = $this->dialect();
        [$sql, $params] = $dialect->buildOutboxClaimOneSql($this->tableName);
        $params['token'] = $workerToken;
        if (array_key_exists('lock_seconds', $params)) {
            $params['lock_seconds'] = $lockSeconds;
        }
        if (array_key_exists('locked_until', $params)) {
            $params['locked_until'] = gmdate('Y-m-d\TH:i:s\Z', time() + $lockSeconds);
        }
        if (array_key_exists('now', $params)) {
            $params['now'] = gmdate('Y-m-d\TH:i:s\Z');
        }

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
            ->where('locked_by', $workerToken)
            ->where('status', OutboxStatuses::PROCESSING)
            ->orderBy('attempts', 'DESC')
            ->getOne();

        if (!is_array($row)) {
            return null;
        }

        return OutboxMessage::fromRow($row);
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

    protected function truncateError(string $message): string
    {
        if (strlen($message) <= 4000) {
            return $message;
        }

        return substr($message, 0, 3997) . '...';
    }
}
