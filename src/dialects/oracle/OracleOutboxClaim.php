<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\oracle;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\outbox\claim\OutboxClaimRows;
use tommyknocker\pdodb\outbox\claim\OutboxClaimStrategy;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\PdoDb;

/**
 * FOR UPDATE cannot be combined with a row limit in Oracle, so candidate ids are locked one by one
 * with SKIP LOCKED and marked processing, all inside the caller's transaction.
 */
final class OracleOutboxClaim implements OutboxClaimStrategy
{
    private const int CANDIDATE_FLOOR = 100;

    public function __construct(private DialectInterface $dialect)
    {
    }

    public function claim(PdoDb $db, string $tableName, string $token, int $limit, int $lockSeconds): array
    {
        $table = $this->dialect->quoteTable($tableName);
        $candidateCap = max(self::CANDIDATE_FLOOR, $limit);
        $candidates = $db->rawQuery(
            "SELECT id FROM (
                SELECT id FROM {$table} cand
                WHERE status = 'pending' AND available_at <= {$this->dialect->outboxNowSql()}
                    " . OutboxClaimRows::partitionGuard($table, 'cand') . "
                ORDER BY available_at ASC, id ASC
            ) WHERE ROWNUM <= {$candidateCap}"
        );

        $claimedIds = [];
        foreach ($candidates as $candidate) {
            if (count($claimedIds) >= $limit) {
                break;
            }
            if (!is_array($candidate)) {
                continue;
            }
            $id = (string) (array_change_key_case($candidate, CASE_LOWER)['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $locked = $db->rawQueryOne(
                "SELECT id FROM {$table} WHERE id = :id AND status = 'pending' FOR UPDATE SKIP LOCKED",
                ['id' => $id]
            );
            if (!is_array($locked) || $locked === []) {
                continue;
            }

            $updated = $db->find()
                ->table($tableName)
                ->where('id', $id)
                ->where('status', OutboxStatuses::PENDING)
                ->update([
                    'status' => OutboxStatuses::PROCESSING,
                    'locked_by' => $token,
                    'locked_until' => new RawValue($this->dialect->outboxNowPlusSecondsSql($lockSeconds)),
                    'attempts' => new RawValue('attempts + 1'),
                ]);
            if ($updated > 0) {
                $claimedIds[] = $id;
            }
        }
        // Rows are read one at a time: fetching several rows with a CLOB payload in one result set leaves
        // LOB locators open and the transaction cannot commit (ORA-22297).
        $rows = [];
        foreach ($claimedIds as $id) {
            $row = $db->find()
                ->table($tableName)
                ->where('id', $id)
                ->where('locked_by', $token)
                ->where('status', OutboxStatuses::PROCESSING)
                ->getOne();
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return OutboxClaimRows::normalize($rows, false);
    }
}
