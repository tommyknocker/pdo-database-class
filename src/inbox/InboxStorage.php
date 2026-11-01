<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\inbox;

use tommyknocker\pdodb\dialects\roles\DialectMessagingInterface;
use tommyknocker\pdodb\dialects\roles\DialectQuotingInterface;
use tommyknocker\pdodb\exceptions\InboxException;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\PdoDb;

/** Table access for processed message ids. */
final class InboxStorage
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
        return $this->dialect()->buildInboxSchemaSql($this->tableName);
    }

    public function ensureSchema(): void
    {
        foreach ($this->getSchemaSql() as $sql) {
            $this->db->rawQuery($sql);
        }
    }

    public function claim(string $messageId, string $consumer): void
    {
        $table = $this->dialect()->quoteTable($this->tableName);
        $this->db->rawQuery(
            "INSERT INTO {$table} (message_id, consumer) VALUES (:message_id, :consumer)",
            [
                'message_id' => $messageId,
                'consumer' => $consumer,
            ]
        );
    }

    public function processed(string $messageId, string $consumer): bool
    {
        $value = $this->db->find()
            ->from($this->tableName)
            ->where('message_id', $messageId)
            ->where('consumer', $consumer)
            ->select(['COUNT(*)'])
            ->getValue();

        return (int) $value > 0;
    }

    /**
     * Number of processed ids older than the cutoff.
     */
    public function countProcessedOlderThan(int $olderThanSeconds): int
    {
        return (int) $this->db->find()
            ->from($this->tableName)
            ->where('processed_at', new RawValue($this->dialect()->outboxNowPlusSecondsSql(-$olderThanSeconds)), '<')
            ->select(['COUNT(*)'])
            ->getValue();
    }

    /**
     * Forget processed ids older than the cutoff, in batches.
     *
     * @param int $batchSize Rows per DELETE
     */
    public function deleteProcessedOlderThan(int $olderThanSeconds, int $batchSize = 200): int
    {
        if ($batchSize < 1) {
            throw new InboxException('Purge batch size must be at least 1.');
        }

        $deleted = 0;
        do {
            $rows = $this->db->find()
                ->from($this->tableName)
                ->select(['message_id', 'consumer'])
                ->where('processed_at', new RawValue($this->dialect()->outboxNowPlusSecondsSql(-$olderThanSeconds)), '<')
                ->orderBy('processed_at')
                ->limit($batchSize)
                ->get();
            if ($rows === []) {
                break;
            }

            $table = $this->dialect()->quoteTable($this->tableName);
            $conditions = [];
            $params = [];
            foreach (array_values($rows) as $i => $row) {
                $conditions[] = "(message_id = :m{$i} AND consumer = :c{$i})";
                $params["m{$i}"] = $row['message_id'] ?? $row['MESSAGE_ID'];
                $params["c{$i}"] = $row['consumer'] ?? $row['CONSUMER'];
            }
            $this->db->rawQuery("DELETE FROM {$table} WHERE " . implode(' OR ', $conditions), $params);
            $deleted += count($rows);
        } while (count($rows) === $batchSize);

        return $deleted;
    }

    protected function dialect(): DialectMessagingInterface&DialectQuotingInterface
    {
        return $this->db->connection->getDialect();
    }
}
