<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * Result of claiming a batch: the readable messages, plus how many claimed rows had an unreadable payload
 * and were marked failed instead of being returned.
 */
final class OutboxClaim
{
    /**
     * @param list<OutboxMessage> $messages
     */
    public function __construct(
        public readonly array $messages,
        public readonly int $unreadable = 0,
    ) {
    }
}
