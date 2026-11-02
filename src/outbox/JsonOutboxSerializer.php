<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\exceptions\OutboxException;

/**
 * The default serializer: the payload as JSON.
 */
final class JsonOutboxSerializer implements OutboxSerializer
{
    public function serialize(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public function deserialize(string $stored): array
    {
        $payload = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new OutboxException('Outbox payload must decode to an array.');
        }

        return $payload;
    }
}
