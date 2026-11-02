<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

/**
 * Event fired after a message was inserted into the outbox, still inside the caller's transaction.
 *
 * The message is not visible to workers until that transaction commits, and it disappears if it rolls back: do not
 * treat this event as proof of delivery.
 */
final class OutboxMessageAddedEvent
{
    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        private string $id,
        private string $eventType,
        private array $headers,
        private ?string $partitionKey,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getPartitionKey(): ?string
    {
        return $this->partitionKey;
    }
}
