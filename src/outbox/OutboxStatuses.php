<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

/**
 * Outbox row status values.
 */
final class OutboxStatuses
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';
}
