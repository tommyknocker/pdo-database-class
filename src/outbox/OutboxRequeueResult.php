<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * Result of requeueing failed outbox rows.
 */
final class OutboxRequeueResult
{
    public function __construct(
        public readonly int $matched,
        public readonly int $requeued,
        public readonly bool $dryRun,
    ) {
    }
}
