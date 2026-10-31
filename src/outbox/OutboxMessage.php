<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\exceptions\OutboxException;

/**
 * One outbox row exposed to the application publisher callback.
 */
final class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly string $status,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $payloadRaw = $row['payload'] ?? '';
        if (is_string($payloadRaw)) {
            $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new OutboxException('Outbox payload must decode to an array.');
            }
        } elseif (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        } else {
            throw new OutboxException('Invalid outbox payload column type.');
        }

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['event_type'] ?? ''),
            $payload,
            (int) ($row['attempts'] ?? 0),
            (string) ($row['status'] ?? ''),
        );
    }
}
