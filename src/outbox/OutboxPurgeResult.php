<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * Result of an outbox purge operation.
 */
final class OutboxPurgeResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $cutoffIso,
        public readonly int $matched,
        public readonly int $deleted,
        public readonly bool $dryRun,
    ) {
    }
}
