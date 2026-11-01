<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\inbox;

use tommyknocker\pdodb\dialects\DialectInterface;
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

    protected function dialect(): DialectInterface
    {
        return $this->db->connection->getDialect();
    }
}
