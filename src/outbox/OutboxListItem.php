<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * Summary of one outbox row for inspection commands.
 */
final class OutboxListItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventType,
        public readonly string $status,
        public readonly int $attempts,
        public readonly ?string $lastError,
        public readonly string $createdAt,
        public readonly string $availableAt,
        public readonly ?string $publishedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $row = array_change_key_case($row, CASE_LOWER);

        $lastError = $row['last_error'] ?? null;
        $publishedAt = $row['published_at'] ?? null;

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['event_type'] ?? ''),
            (string) ($row['status'] ?? ''),
            (int) ($row['attempts'] ?? 0),
            is_string($lastError) && $lastError !== '' ? $lastError : null,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['available_at'] ?? ''),
            is_string($publishedAt) && $publishedAt !== '' ? $publishedAt : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'last_error' => $this->lastError,
            'created_at' => $this->createdAt,
            'available_at' => $this->availableAt,
            'published_at' => $this->publishedAt,
        ];
    }
}
