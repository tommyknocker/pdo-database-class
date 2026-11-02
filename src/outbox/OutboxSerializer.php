<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * How a message payload becomes the text stored in the outbox table, and back.
 *
 * The payload column is JSON on PostgreSQL and text elsewhere, so the stored text must be valid JSON on every database.
 * That still leaves room for what a serializer is for: encrypting or signing the payload inside a JSON envelope,
 * versioning its schema, or choosing different JSON flags. The outbox rejects output that is not valid JSON.
 */
interface OutboxSerializer
{
    /**
     * @param array<int|string, mixed> $payload
     *
     * @return string valid JSON text
     */
    public function serialize(array $payload): string;

    /**
     * @return array<int|string, mixed>
     */
    public function deserialize(string $stored): array;
}
