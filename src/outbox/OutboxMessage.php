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
     * @param array<string, mixed> $headers Information about the message that was kept apart from the payload
     * @param string|null $partitionKey The key whose messages are delivered in order, or null
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly string $status,
        public readonly string $lockToken = '',
        public readonly array $headers = [],
        public readonly ?string $partitionKey = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, ?OutboxSerializer $serializer = null): self
    {
        $serializer ??= new JsonOutboxSerializer();
        $row = array_change_key_case($row, CASE_LOWER);
        $payloadRaw = $row['payload'] ?? '';
        if (is_resource($payloadRaw)) {
            $payloadRaw = stream_get_contents($payloadRaw);
            if ($payloadRaw === false) {
                throw new OutboxException('Failed to read outbox payload.');
            }
        } elseif (is_object($payloadRaw) && method_exists($payloadRaw, 'load')) {
            $loaded = $payloadRaw->load();
            $payloadRaw = is_string($loaded) ? $loaded : '';
        }

        if (is_string($payloadRaw)) {
            try {
                $payload = $serializer->deserialize($payloadRaw);
            } catch (\JsonException $e) {
                throw new OutboxException('Outbox payload is not valid JSON: ' . $e->getMessage(), 0, $e);
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
            (string) ($row['locked_by'] ?? ''),
            self::decodeHeaders($row['headers'] ?? null),
            isset($row['partition_key']) && $row['partition_key'] !== '' ? (string) $row['partition_key'] : null,
        );
    }

    /**
     * Headers are information about the message, not the message: unreadable ones are dropped rather than failing
     * delivery of a message whose payload is fine.
     *
     * @return array<string, mixed>
     */
    private static function decodeHeaders(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
