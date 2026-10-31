<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * Summary of one OutboxWorker::runOnce() invocation.
 */
final class OutboxProcessResult
{
    public function __construct(
        public readonly int $claimed,
        public readonly int $published,
        public readonly int $scheduledRetry,
        public readonly int $markedFailed,
        public readonly int $unconfirmed = 0,
    ) {
    }
}
